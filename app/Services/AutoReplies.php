<?php

namespace App\Services;

use App\Models\AutoReplyRule;
use App\Models\Conversation;
use App\Models\Message;

/** Instant, deterministic replies (greetings, FAQs, after-hours) evaluated before the AI. */
class AutoReplies
{
    public function __construct(private BusinessHours $hours) {}

    public function match(Conversation $c, Message $inbound): ?AutoReplyRule
    {
        $text = mb_strtolower(trim((string) $inbound->body));
        $isFirst = $c->messages()->where('direction', 'in')->count() === 1;
        $open = $this->hours->isOpen($inbound->occurred_at);

        foreach (AutoReplyRule::where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            $hit = match ($rule->trigger) {
                'first_message' => $isFirst,
                'outside_hours' => ! $open,
                'keyword' => $text !== '' && $this->keywordHit($rule, $text),
                default => false,
            };
            if (! $hit) {
                continue;
            }
            $recent = Message::where('conversation_id', $c->id)->where('sender_type', 'auto_reply')
                ->where('occurred_at', '>', now()->subMinutes(max(1, $rule->cooldown_minutes)))
                ->get()->contains(fn ($m) => ($m->meta['rule_id'] ?? null) === $rule->id);
            if (! $recent) {
                return $rule;
            }
        }

        return null;
    }

    private function keywordHit(AutoReplyRule $rule, string $text): bool
    {
        foreach ((array) $rule->keywords as $kw) {
            $kw = mb_strtolower(trim((string) $kw));
            if ($kw === '') {
                continue;
            }
            $hit = match ($rule->match_type) {
                'exact' => $text === $kw,
                'regex' => @preg_match('/'.str_replace('/', '\/', $kw).'/iu', $text) === 1,
                default => (bool) preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($kw, '/').'($|[^\p{L}\p{N}])/u', $text),
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    public function render(AutoReplyRule $rule, Conversation $c): string
    {
        $name = $c->contact?->name ? explode(' ', $c->contact->name)[0] : '';

        return trim(str_replace(['{name}', '{business}'], [$name, app(\App\Support\Tenancy\CurrentOrganization::class)->require()->name], $rule->response));
    }
}
