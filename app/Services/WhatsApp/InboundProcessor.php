<?php

namespace App\Services\WhatsApp;

use App\Jobs\RespondToConversation;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\ActivityLogger;
use App\Services\AssignmentRouter;
use App\Services\AttributionService;
use App\Services\ConsentService;
use App\Services\ContactMatcher;
use App\Services\LeadScoring;
use App\Services\LeadService;
use App\Services\NotificationService;
use App\Services\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Processes a verified WhatsApp Cloud API webhook payload for one organization.
 * Idempotent: messages are keyed by Meta's message id (wamid), so retried deliveries are no-ops.
 */
class InboundProcessor
{
    private const STATUS_RANK = ['queued' => 0, 'accepted' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'played' => 4];

    public function __construct(
        private PhoneNormalizer $phones,
        private ContactMatcher $matcher,
        private LeadService $leads,
        private AttributionService $attribution,
        private ActivityLogger $activity,
        private NotificationService $notify,
        private ConsentService $consent,
        private AssignmentRouter $router,
        private LeadScoring $scoring,
    ) {}

    public function process(WebhookEvent $event, Integration $integration): array
    {
        $stats = ['messages' => 0, 'duplicates' => 0, 'statuses' => 0, 'ignored' => 0];
        $phoneNumberId = (string) $integration->cfg('phone_number_id');

        foreach ((array) ($event->payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    $stats['ignored']++;
                    continue;
                }
                $value = (array) ($change['value'] ?? []);
                if ((string) ($value['metadata']['phone_number_id'] ?? '') !== $phoneNumberId) {
                    $stats['ignored']++; // a different number on the same app; never cross organizations
                    continue;
                }
                $profiles = collect($value['contacts'] ?? [])->keyBy('wa_id');
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $this->inbound($m, $profiles[$m['from'] ?? ''] ?? null, $integration) ? $stats['messages']++ : $stats['duplicates']++;
                }
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    $this->status($s) && $stats['statuses']++;
                }
            }
        }
        $integration->forceFill(['last_webhook_at' => now()])->saveQuietly();

        return $stats;
    }

    /** @return bool false when this message was already processed */
    private function inbound(array $m, ?array $profile, Integration $integration): bool
    {
        $wamid = (string) ($m['id'] ?? '');
        $waId = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
        if ($wamid === '' || $waId === '' || Message::where('provider_message_id', $wamid)->exists()) {
            return false;
        }
        [$type, $body, $media] = $this->content($m);
        $at = isset($m['timestamp']) ? Carbon::createFromTimestampUTC((int) $m['timestamp']) : now();
        $referral = $m['referral'] ?? null;

        try {
            [$conversation, $message, $isNewLead] = DB::transaction(function () use ($waId, $profile, $integration, $wamid, $type, $body, $media, $at, $referral, $m) {
                $contact = $this->contact($waId, $profile);
                $conversation = Conversation::firstOrCreate(['channel' => 'whatsapp', 'external_id' => $waId], ['contact_id' => $contact->id, 'integration_id' => $integration->id]);
                $message = Message::create([
                    'conversation_id' => $conversation->id, 'direction' => 'in', 'sender_type' => 'contact', 'type' => $type, 'body' => $body,
                    'media' => $media, 'provider_message_id' => $wamid, 'status' => 'received', 'occurred_at' => $at,
                    'meta' => array_filter(['referral' => $referral, 'context' => $m['context'] ?? null]) ?: null,
                ]);
                [$lead, $isNew] = $this->lead($conversation, $contact, $referral);
                $conversation->forceFill([
                    'lead_id' => $lead->id, 'contact_id' => $contact->id, 'status' => 'open',
                    'assigned_user_id' => $conversation->assigned_user_id ?? $lead->assigned_user_id,
                    'last_inbound_at' => max($conversation->last_inbound_at ?? $at, $at), 'last_message_at' => $at,
                    'last_message_preview' => mb_substr((string) $body, 0, 160), 'unread_count' => $conversation->unread_count + 1,
                ])->save();
                $this->activity->forLead($lead, 'whatsapp_in', $body, ['message_id' => $message->id, 'type' => $type], 'customer', null);
                if (! $lead->first_responded_at) {
                    $lead->forceFill(['first_responded_at' => $at])->save();
                }

                return [$conversation, $message, $isNew];
            });
        } catch (UniqueConstraintViolationException) {
            return false; // concurrent delivery of the same wamid won the race
        }

        $lead = $conversation->lead;
        if ($this->isOptOut((string) $body)) {
            $this->consent->optOut($conversation->contact, 'whatsapp', 'Customer sent "'.mb_substr((string) $body, 0, 40).'" on WhatsApp', 'whatsapp_message');
            $conversation->forceFill(['ai_mode' => 'off'])->save();
        } else {
            RespondToConversation::dispatch($conversation->id, $message->id, $conversation->organization_id)
                ->delay(now()->addSeconds(config('ai.whatsapp.reply_debounce_seconds')));
        }

        $targets = $conversation->assigned_user_id ? [$conversation->assigned_user_id] : $this->notify->escalationTargets(null);
        foreach ($targets as $uid) {
            $this->notify->notify($uid, $isNewLead ? 'lead_assigned' : 'whatsapp_in',
                ($isNewLead ? 'New WhatsApp lead: ' : 'WhatsApp: ').$conversation->contact->displayName(),
                mb_substr((string) $body, 0, 140), '/whatsapp?c='.$conversation->id, 'wa:'.$wamid);
        }
        $this->scoring->score($lead->fresh());

        return true;
    }

    private function contact(string $waId, ?array $profile): Contact
    {
        $identity = ContactIdentity::where('provider', 'whatsapp_wa_id')->where('external_id', $waId)->first();
        if ($identity && ($c = Contact::find($identity->contact_id))) {
            return $c->merged_into_id ? Contact::findOrFail($c->merged_into_id) : $c;
        }
        $norm = $this->phones->normalize('+'.$waId);
        $contact = $this->matcher->strongMatches([$norm['e164']], null)->first()['contact'] ?? null;
        $profileName = isset($profile['profile']['name']) ? mb_substr(trim($profile['profile']['name']), 0, 160) : null;
        if (! $contact) {
            $contact = Contact::create([
                'name' => $profileName ?: null, 'whatsapp_raw' => '+'.$waId, 'whatsapp_e164' => $norm['e164'],
                'country_code' => $norm['region'], 'preferred_contact_method' => 'whatsapp',
            ]);
        } elseif (! $contact->whatsapp_e164 && $norm['e164']) {
            $contact->forceFill(['whatsapp_raw' => '+'.$waId, 'whatsapp_e164' => $norm['e164']])->save();
        }
        ContactIdentity::firstOrCreate(['provider' => 'whatsapp_wa_id', 'external_id' => $waId], ['contact_id' => $contact->id]);

        return $contact;
    }

    /** Re-use the contact's open lead; otherwise open a new inquiry. @return array{0: Lead, 1: bool} */
    private function lead(Conversation $c, Contact $contact, ?array $referral): array
    {
        $open = $c->lead_id ? Lead::with('status')->find($c->lead_id) : null;
        if (! $open || $open->status->category !== 'open' || $open->archived_at) {
            $open = Lead::where('contact_id', $contact->id)->whereNull('archived_at')
                ->whereHas('status', fn ($q) => $q->where('category', 'open'))->latest('id')->first();
        }
        $attr = $this->referralAttribution($referral);
        if ($open) {
            if ($attr) {
                $this->attribution->record($open, $attr, 'whatsapp_referral');
            }

            return [$open, false];
        }
        $platform = $attr['platform'] ?? null;
        $sourceKey = $platform === 'instagram' ? 'instagram_ads' : ($platform === 'facebook' ? 'facebook_ads' : 'whatsapp');
        $lead = $this->leads->create([
            'contact_id' => $contact->id,
            'lead_source_id' => LeadSource::where('key', $sourceKey)->value('id') ?? LeadSource::where('key', 'whatsapp')->value('id'),
            'assigned_user_id' => $this->router->next()?->id,
            'source_notes' => $attr ? 'Click-to-WhatsApp ad'.(! empty($referral['headline']) ? ': '.$referral['headline'] : '') : 'Started a WhatsApp conversation',
            'attribution' => $attr ?? [],
            'attribution_via' => 'whatsapp_referral',
            'allow_duplicate_open_lead' => true,
            'note' => null,
        ], null, 'whatsapp');

        return [$lead, true];
    }

    private function referralAttribution(?array $r): ?array
    {
        if (! $r) {
            return null;
        }
        $url = strtolower((string) ($r['source_url'] ?? ''));
        $platform = str_contains($url, 'instagram') ? 'instagram' : (str_contains($url, 'facebook') || str_contains($url, 'fb.') ? 'facebook' : 'meta');

        return [
            'platform' => $platform, 'source' => $platform, 'medium' => ($r['source_type'] ?? null) === 'ad' ? 'paid_social' : 'social',
            'external_ad_id' => ($r['source_type'] ?? null) === 'ad' ? ($r['source_id'] ?? null) : null,
            'ad_name' => $r['headline'] ?? null,
            'referral' => array_intersect_key($r, array_flip(['source_url', 'source_id', 'source_type', 'headline', 'body', 'media_type', 'ctwa_clid'])),
        ];
    }

    private function status(array $s): bool
    {
        $msg = Message::where('provider_message_id', (string) ($s['id'] ?? ''))->first();
        $new = (string) ($s['status'] ?? '');
        if (! $msg || $new === '') {
            return false;
        }
        $at = isset($s['timestamp']) ? Carbon::createFromTimestampUTC((int) $s['timestamp']) : now();
        if ($new === 'failed') {
            $err = $s['errors'][0] ?? [];
            $msg->forceFill(['status' => 'failed', 'failed_at' => $at, 'error' => mb_substr(trim(($err['title'] ?? 'Failed').' '.($err['error_data']['details'] ?? $err['message'] ?? '')), 0, 500)])->save();

            return true;
        }
        if (! isset(self::STATUS_RANK[$new])) {
            return false;
        }
        $fill = [$new === 'played' ? 'read_at' : $new.'_at' => $at];
        // statuses can arrive out of order; never move backwards
        if ((self::STATUS_RANK[$msg->status] ?? -1) < self::STATUS_RANK[$new] && $msg->status !== 'failed') {
            $fill['status'] = $new === 'played' ? 'read' : $new;
        }
        $msg->forceFill($fill)->save();

        return true;
    }

    /** @return array{0: string, 1: ?string, 2: ?array} */
    private function content(array $m): array
    {
        $type = (string) ($m['type'] ?? 'unknown');

        return match ($type) {
            'text' => ['text', $m['text']['body'] ?? '', null],
            'button' => ['button', $m['button']['text'] ?? '', null],
            'interactive' => ['interactive', $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '[interactive]', null],
            'image', 'video', 'document', 'audio', 'sticker' => [$type, $m[$type]['caption'] ?? ($type === 'document' ? '[document: '.($m['document']['filename'] ?? 'file').']' : '['.$type.']'),
                array_filter(['id' => $m[$type]['id'] ?? null, 'mime_type' => $m[$type]['mime_type'] ?? null, 'filename' => $m[$type]['filename'] ?? null])],
            'location' => ['location', '📍 '.trim(($m['location']['name'] ?? '').' '.($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? '')), null],
            'contacts' => ['contacts', '[shared contact]', null],
            'reaction' => ['reaction', $m['reaction']['emoji'] ?? '', null],
            default => ['unsupported', '[unsupported message]', null],
        };
    }

    private function isOptOut(string $body): bool
    {
        $t = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $body)));

        return $t !== '' && in_array($t, array_map('mb_strtolower', config('ai.opt_out_keywords')), true);
    }
}
