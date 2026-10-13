<?php

namespace App\Services\WhatsApp;

use App\Exceptions\DomainRuleException;
use App\Models\AiInteraction;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\ActivityLogger;

/**
 * Outbound WhatsApp messages. A message is stored as `queued` before the API call; it becomes
 * `accepted` when Meta returns a message id, and only Meta's status webhooks set sent/delivered/read/failed.
 */
class MessagingService
{
    public function __construct(private ActivityLogger $activity) {}

    public function integration(): Integration
    {
        $i = Integration::where('provider', 'whatsapp_cloud')->first();
        if (! $i || ! CloudApiClient::for($i)->configured() || $i->status === 'disabled') {
            throw new DomainRuleException('WhatsApp is not connected. Configure it under Integrations.', 'whatsapp_not_connected', 409);
        }

        return $i;
    }

    public function sendText(Conversation $c, string $body, string $senderType, ?User $user = null, ?AiInteraction $interaction = null, array $meta = []): Message
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4096) {
            throw new DomainRuleException('Message must be between 1 and 4096 characters.', 'invalid_message');
        }
        $this->guardContactable($c);
        if (! $c->windowOpen()) {
            throw new DomainRuleException('The 24-hour reply window is closed. Send an approved template instead.', 'window_closed', 422);
        }
        $integration = $this->integration();

        return $this->dispatch($c, $integration, [
            'type' => 'text', 'body' => $body, 'sender_type' => $senderType, 'user_id' => $user?->id, 'ai_interaction_id' => $interaction?->id, 'meta' => $meta ?: null,
        ], fn (CloudApiClient $api) => $api->sendText($c->external_id, $body));
    }

    public function sendTemplate(Conversation $c, MessageTemplate $tpl, array $params, ?User $user): Message
    {
        if ($tpl->status !== 'APPROVED') {
            throw new DomainRuleException('Only approved templates can be sent.', 'template_not_approved');
        }
        if (count($params) < $tpl->body_params) {
            throw new DomainRuleException("This template needs {$tpl->body_params} value(s).", 'template_params');
        }
        $this->guardContactable($c, template: true);
        $integration = $this->integration();
        $preview = $this->renderTemplate($tpl, $params);

        return $this->dispatch($c, $integration, [
            'type' => 'template', 'template_name' => $tpl->name, 'body' => $preview, 'sender_type' => 'user', 'user_id' => $user?->id,
            'meta' => ['language' => $tpl->language, 'params' => array_values($params)],
        ], fn (CloudApiClient $api) => $api->sendTemplate($c->external_id, $tpl->name, $tpl->language, array_slice(array_values($params), 0, $tpl->body_params)));
    }

    private function dispatch(Conversation $c, Integration $integration, array $attrs, \Closure $call): Message
    {
        $msg = Message::create($attrs + ['conversation_id' => $c->id, 'direction' => 'out', 'status' => 'queued', 'occurred_at' => now()]);
        try {
            $wamid = $call(CloudApiClient::for($integration));
            $msg->forceFill(['provider_message_id' => $wamid, 'status' => 'accepted'])->save();
            $integration->markSuccess();
        } catch (WhatsAppException $e) {
            $msg->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'failed_at' => now()])->save();
            if ($e->isAuthError()) {
                $integration->markError($e->getMessage());
            }
            throw new DomainRuleException(
                $e->isWindowClosed() ? 'WhatsApp rejected the message: the 24-hour window is closed. Use a template.' : 'WhatsApp rejected the message: '.$e->getMessage(),
                'whatsapp_send_failed', 502, ['message_id' => $msg->id]
            );
        }
        $c->forceFill([
            'last_message_at' => $msg->occurred_at, 'last_message_preview' => mb_substr((string) $msg->body, 0, 160),
            'first_response_at' => $c->first_response_at ?? ($attrs['sender_type'] !== 'system' ? now() : null), 'unread_count' => 0,
        ])->save();
        if ($c->lead) {
            $this->activity->forLead($c->lead, 'whatsapp_out', $msg->body, ['message_id' => $msg->id, 'sender' => $attrs['sender_type']], 'customer', $attrs['user_id'] ?? null);
            if ($attrs['sender_type'] === 'user' && ! $c->lead->first_contacted_at) {
                $c->lead->forceFill(['first_contacted_at' => now(), 'last_contacted_at' => now()])->save();
            }
        }

        return $msg;
    }

    private function guardContactable(Conversation $c, bool $template = false): void
    {
        $contact = $c->contact;
        if ($contact->isSuppressed('whatsapp')) {
            throw new DomainRuleException('This contact opted out of WhatsApp messages.', 'contact_suppressed', 422);
        }
    }

    public function renderTemplate(MessageTemplate $tpl, array $params): string
    {
        $body = collect((array) $tpl->components)->firstWhere('type', 'BODY')['text'] ?? $tpl->name;
        foreach (array_values($params) as $i => $p) {
            $body = str_replace('{{'.($i + 1).'}}', (string) $p, $body);
        }

        return $body;
    }

    public function syncTemplates(): int
    {
        $integration = $this->integration();
        if (! $integration->cfg('waba_id')) {
            throw new DomainRuleException('Add the WhatsApp Business Account ID first.', 'waba_missing');
        }
        try {
            $rows = CloudApiClient::for($integration)->templates();
        } catch (WhatsAppException $e) {
            $integration->markError($e->getMessage());
            throw new DomainRuleException('Template sync failed: '.$e->getMessage(), 'whatsapp_error', 502);
        }
        $seen = [];
        foreach ($rows as $t) {
            $body = collect($t['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '';
            preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
            $tpl = MessageTemplate::updateOrCreate(['name' => $t['name'], 'language' => $t['language']], [
                'external_id' => $t['id'] ?? null, 'category' => $t['category'] ?? null, 'status' => $t['status'] ?? 'UNKNOWN',
                'components' => $t['components'] ?? [], 'body_params' => $m[1] ? max(array_map('intval', $m[1])) : 0, 'synced_at' => now(),
            ]);
            $seen[] = $tpl->id;
        }
        MessageTemplate::whereNotIn('id', $seen ?: [0])->update(['status' => 'DELETED']);
        $integration->markSuccess();

        return count($rows);
    }
}
