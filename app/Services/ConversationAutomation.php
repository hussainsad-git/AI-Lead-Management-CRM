<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\AiInteraction;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiResponder;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\LeadQualifier;
use App\Services\Ai\ProviderRegistry;
use App\Services\WhatsApp\MessagingService;

/**
 * What happens after a customer message: opt-out checks → instant auto-replies → AI (suggest or
 * auto-send, with human handoff) → qualification. Runs on the queue, debounced, and only for the
 * latest inbound message so bursts of messages get one reply.
 */
class ConversationAutomation
{
    public function __construct(
        private AutoReplies $autoReplies,
        private AiResponder $responder,
        private LeadQualifier $qualifier,
        private AssistantConfig $assistants,
        private ProviderRegistry $providers,
        private MessagingService $messaging,
        private NotificationService $notify,
        private BusinessHours $hours,
    ) {}

    public function handle(Conversation $c, Message $trigger): array
    {
        $log = [];
        $latest = $c->messages()->where('direction', 'in')->latest('occurred_at')->latest('id')->first();
        if (! $latest || $latest->id !== $trigger->id) {
            return ['skipped' => 'newer message pending'];
        }
        if ($c->contact->isSuppressed('whatsapp')) {
            return ['skipped' => 'contact opted out'];
        }
        // Already answered by a human/AI after this message?
        if ($c->messages()->where('direction', 'out')->where('occurred_at', '>=', $trigger->occurred_at)->where('sender_type', '!=', 'system')->exists()) {
            return ['skipped' => 'already replied'];
        }

        $assistant = $this->assistants->get();
        $text = mb_strtolower(trim((string) $trigger->body));

        // 1. explicit request for a human
        foreach ((array) $assistant->handoff_keywords as $kw) {
            $kw = mb_strtolower(trim((string) $kw));
            if ($kw !== '' && preg_match('/(^|[^\p{L}])'.preg_quote($kw, '/').'($|[^\p{L}])/u', $text)) {
                $this->handoff($c, 'Customer asked for: '.$kw, $assistant->handoff_message);

                return ['handoff' => 'keyword'];
            }
        }

        // 2. deterministic auto-replies
        if ($rule = $this->autoReplies->match($c, $trigger)) {
            try {
                $this->messaging->sendText($c, $this->autoReplies->render($rule, $c), 'auto_reply', null, null, ['rule_id' => $rule->id]);
                $rule->increment('times_used');
                $log['auto_reply'] = $rule->id;
            } catch (DomainRuleException $e) {
                $log['auto_reply_error'] = $e->getMessage();
            }
            if ($rule->stop_ai) {
                return $log + $this->maybeQualify($c, $assistant);
            }
        }

        // 3. AI
        $mode = $c->ai_mode === 'off' ? 'off' : ($c->ai_mode === 'on' ? ($assistant->mode === 'off' ? 'suggest' : $assistant->mode) : $assistant->mode);
        $inHours = $this->hours->isOpen();
        $activeNow = match ($assistant->active_hours) {
            'outside_hours' => ! $inHours, 'in_hours' => $inHours, default => true,
        };
        if ($mode === 'off' || $c->handoff_at) {
            return $log + ['ai' => $c->handoff_at ? 'human handling' : 'off'] + $this->maybeQualify($c, $assistant);
        }
        if (! in_array($trigger->type, ['text', 'button', 'interactive'], true)) {
            return $log + ['ai' => 'non-text message'] + $this->maybeQualify($c, $assistant);
        }

        $kind = ($mode === 'auto' && $activeNow && $c->ai_reply_count < $assistant->max_auto_replies) ? 'reply' : 'suggest';
        $r = $this->responder->respond($c, $kind);
        $log['ai'] = $r['interaction']->outcome ?? $r['interaction']->status;

        if ($kind === 'reply' && $r['ok']) {
            if ($r['needs_human'] || ($r['off_topic'] && $assistant->kb_only && ! $r['reply'])) {
                $this->handoff($c, $r['handoff_reason'] ?: 'AI could not answer from the knowledge base', $assistant->handoff_message, $r['interaction']);
                $log['handoff'] = 'ai';
            } elseif ($r['reply']) {
                try {
                    $this->messaging->sendText($c, $r['reply'], 'ai', null, $r['interaction']);
                    $c->increment('ai_reply_count');
                    if ($r['language'] && ! $c->language) {
                        $c->forceFill(['language' => mb_substr($r['language'], 0, 12)])->save();
                    }
                } catch (DomainRuleException $e) {
                    $log['send_error'] = $e->getMessage();
                }
            }
        } elseif ($kind === 'reply' && ! $r['ok'] && $r['needs_human']) {
            $this->handoff($c, 'AI response unusable', $assistant->handoff_message, $r['interaction']);
        } elseif ($kind === 'suggest' && $r['ok'] && $c->assigned_user_id) {
            $this->notify->notify($c->assigned_user_id, 'ai_suggestion', 'AI reply ready: '.$c->contact->displayName(), mb_substr((string) $r['reply'], 0, 140), '/whatsapp?c='.$c->id, 'sugg:'.$r['interaction']->id);
        }

        return $log + $this->maybeQualify($c, $assistant);
    }

    public function handoff(Conversation $c, string $reason, ?string $message, ?AiInteraction $interaction = null): void
    {
        $first = ! $c->handoff_at;
        $c->forceFill(['handoff_at' => now(), 'handoff_reason' => mb_substr($reason, 0, 255), 'status' => 'pending'])->save();
        if ($first && $message) {
            try {
                $this->messaging->sendText($c, $message, 'system', null, $interaction);
            } catch (DomainRuleException) {
                // window closed / not connected: the team still gets notified below
            }
        }
        $targets = $c->assigned_user_id ? [$c->assigned_user_id] : $this->notify->escalationTargets(null);
        foreach ($targets as $uid) {
            $this->notify->notify($uid, 'handoff', 'Needs a human: '.$c->contact->displayName(), $reason, '/whatsapp?c='.$c->id, 'handoff:'.$c->id.':'.$c->handoff_at->timestamp);
        }
    }

    private function maybeQualify(Conversation $c, $assistant): array
    {
        if (! $assistant->auto_qualify || ! $c->lead_id || ! $this->providers->forAssistant($assistant)) {
            return [];
        }
        $inbound = $c->messages()->where('direction', 'in')->count();
        if ($inbound > 6 && $inbound % 3 !== 0) {
            return [];
        }
        $q = $this->qualifier->qualify($c->fresh(['lead']));
        if ($q && $q['wants_no_contact']) {
            // Flag for a human rather than auto opting out on an AI judgement.
            $this->handoff($c, 'Customer may not want further contact — please confirm', null);
        }

        return ['qualified' => $q ? array_keys($q['filled']) : null];
    }
}
