<?php

namespace App\Services\Telephony;

use App\Exceptions\DomainRuleException;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\TelephonyCall;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Click-to-call through the connected provider. The provider rings the rep's phone first, then bridges the customer,
 * so no softphone is needed. Every status/duration value is copied from provider callbacks; the rep still records
 * the outcome (answered / callback / not interested …) on the lead, which is what drives pipeline metrics.
 */
class TelephonyService
{
    public function __construct(private ActivityLogger $activity, private NotificationService $notifications, private PhoneNormalizer $phones) {}

    public function integration(): ?Integration
    {
        $i = Integration::where('provider', 'twilio_voice')->first();

        return $i && $i->status === 'connected' && TwilioClient::for($i)->configured() ? $i : null;
    }

    /** Why click-to-call is (un)available for this user on this lead — shown as a disabled-button reason, never guessed. */
    public function availability(Lead $lead, User $user): array
    {
        if (! $this->integration()) {
            return ['available' => false, 'reason' => 'telephony_not_connected'];
        }
        $agent = $this->agentNumber($user);
        if (! $agent) {
            return ['available' => false, 'reason' => 'agent_phone_missing'];
        }
        $to = $lead->contact->phone_e164 ?: $lead->contact->whatsapp_e164;
        if (! $to) {
            return ['available' => false, 'reason' => 'no_phone'];
        }
        if ($lead->contact->isSuppressed('phone')) {
            return ['available' => false, 'reason' => 'suppressed'];
        }
        if (TelephonyCall::where('user_id', $user->id)->whereIn('status', TelephonyCall::OPEN)->where('created_at', '>', now()->subMinutes(30))->exists()) {
            return ['available' => false, 'reason' => 'call_in_progress'];
        }

        return ['available' => true, 'reason' => null, 'to' => $to];
    }

    public function dial(Lead $lead, User $user): TelephonyCall
    {
        $a = $this->availability($lead, $user);
        if (! $a['available']) {
            throw new DomainRuleException(match ($a['reason']) {
                'telephony_not_connected' => 'Telephony is not connected. Ask an administrator to connect Twilio on Integrations.',
                'agent_phone_missing' => 'Add your phone number on your profile first; the provider rings you before connecting the customer.',
                'no_phone' => 'This lead has no phone number.',
                'suppressed' => 'This contact has opted out of phone calls.',
                default => 'You already have a call in progress.',
            }, $a['reason'], 422);
        }
        $i = $this->integration();
        $client = TwilioClient::for($i);
        $call = DB::transaction(function () use ($lead, $user, $a, $i) {
            return TelephonyCall::create(['lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'user_id' => $user->id, 'provider' => 'twilio',
                'status' => 'queued', 'from_number' => (string) $i->cfg('from_number'), 'to_number' => $a['to'], 'agent_number' => $this->agentNumber($user)]);
        });
        try {
            $r = $client->createCall($call->agent_number, $call->from_number, $this->url($i, "twiml/{$call->id}"), $this->url($i, "status/{$call->id}"));
        } catch (TelephonyException $e) {
            $call->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'ended_at' => now()])->save();
            throw new DomainRuleException('Could not start the call: '.$e->getMessage(), 'telephony_failed', 502);
        }
        $call->forceFill(['provider_sid' => $r['sid'], 'status' => $r['status'] ?: 'queued', 'started_at' => now()])->save();
        $this->activity->forLead($lead, 'call_placed', null, ['call_id' => $call->id, 'provider' => 'twilio', 'to' => $call->to_number], 'internal', $user->id);

        return $call;
    }

    /** TwiML for the agent leg: once the rep answers, dial the customer and report the customer leg's result. */
    public function twiml(Integration $i, TelephonyCall $call): string
    {
        $record = $i->cfg('record_calls') === '1' || $i->cfg('record_calls') === true || $i->cfg('record_calls') === 'true';
        $say = htmlspecialchars((string) ($i->cfg('agent_prompt') ?: 'Connecting you to the customer.'), ENT_XML1);
        $action = htmlspecialchars($this->url($i, "dial-status/{$call->id}"), ENT_XML1);
        $callerId = htmlspecialchars($call->from_number, ENT_XML1);
        $to = htmlspecialchars($call->to_number, ENT_XML1);
        $rec = $record ? ' record="record-from-answer-dual" recordingStatusCallback="'.htmlspecialchars($this->url($i, "recording/{$call->id}"), ENT_XML1).'"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><Response><Say>'.$say.'</Say><Dial callerId="'.$callerId.'" timeout="30" action="'.$action.'" method="POST"'.$rec.'><Number>'.$to.'</Number></Dial></Response>';
    }

    /** Parent-leg StatusCallback: CallStatus, CallDuration (completed only), Timestamp. */
    public function applyStatus(TelephonyCall $call, array $p): void
    {
        $status = strtolower((string) ($p['CallStatus'] ?? ''));
        if ($status === '') {
            return;
        }
        $rank = ['queued' => 0, 'initiated' => 1, 'ringing' => 2, 'in-progress' => 3, 'completed' => 4, 'busy' => 4, 'no-answer' => 4, 'failed' => 4, 'canceled' => 4];
        if (($rank[$status] ?? -1) < ($rank[$call->status] ?? -1)) {
            return; // out-of-order delivery: never regress
        }
        $fill = ['status' => $status];
        if ($status === 'in-progress' && ! $call->answered_at) {
            $fill['answered_at'] = now();
        }
        if (($rank[$status] ?? 0) >= 4) {
            $fill['ended_at'] = now();
            if (isset($p['CallDuration']) && is_numeric($p['CallDuration'])) {
                $fill['duration_seconds'] = (int) $p['CallDuration'];
            }
            if ($status === 'failed' && ! empty($p['ErrorCode'])) {
                $fill['error'] = 'Twilio error '.$p['ErrorCode'].(isset($p['ErrorMessage']) ? ': '.mb_substr($p['ErrorMessage'], 0, 200) : '');
            }
        }
        $wasOpen = $call->isOpen();
        $call->forceFill($fill)->save();
        if ($wasOpen && ! $call->isOpen()) {
            $this->finished($call);
        }
    }

    /** <Dial action> callback for the customer leg: DialCallStatus, DialCallDuration, DialCallSid. */
    public function applyDialStatus(TelephonyCall $call, array $p): string
    {
        $call->forceFill([
            'lead_leg_status' => strtolower((string) ($p['DialCallStatus'] ?? '')) ?: null,
            'talk_seconds' => isset($p['DialCallDuration']) && is_numeric($p['DialCallDuration']) ? (int) $p['DialCallDuration'] : $call->talk_seconds,
        ])->save();

        return '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
    }

    public function applyRecording(TelephonyCall $call, array $p): void
    {
        if (! empty($p['RecordingSid']) && ! empty($p['RecordingUrl'])) {
            $call->forceFill(['recording_sid' => mb_substr($p['RecordingSid'], 0, 64), 'recording_url' => mb_substr($p['RecordingUrl'], 0, 500)])->save();
        }
    }

    private function finished(TelephonyCall $call): void
    {
        $lead = $call->lead()->with('contact')->first();
        if (! $lead) {
            return;
        }
        $customer = $call->lead_leg_status ?: ($call->status === 'completed' ? 'unknown' : $call->status);
        $this->activity->forLead($lead, 'call_ended', null, ['call_id' => $call->id, 'provider' => 'twilio', 'status' => $call->status, 'customer_leg' => $customer,
            'duration_seconds' => $call->duration_seconds, 'talk_seconds' => $call->talk_seconds, 'error' => $call->error], 'internal', $call->user_id);
        if ($call->user_id) {
            $this->notifications->notify($call->user_id, 'callback_due', 'Log the outcome of your call', 'Call with '.$lead->contact->displayName().' ended ('.($call->talk_seconds ? round($call->talk_seconds / 60, 1).' min talk' : $customer).'). Record what happened.', '/leads/'.$lead->id.'?log_call='.$call->id, 'call-outcome:'.$call->id);
        }
    }

    private function agentNumber(User $user): ?string
    {
        $n = $this->phones->normalize($user->phone, (string) ($user->organization?->default_country ?: 'PK'));

        return $n['valid'] ? $n['e164'] : null;
    }

    private function url(Integration $i, string $path): string
    {
        return url('/webhooks/telephony/'.$i->webhook_key.'/'.$path);
    }
}
