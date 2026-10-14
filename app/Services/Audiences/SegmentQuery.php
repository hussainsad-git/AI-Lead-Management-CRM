<?php

namespace App\Services\Audiences;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a segment definition {match, groups:[{match, rules:[{field, op, value}]}]} into one SQL query
 * over leads (+ contact). Groups are combined with the outer match, rules inside a group with the inner match.
 */
class SegmentQuery
{
    public function build(array $definition): Builder
    {
        $q = Lead::query()->whereNull('leads.archived_at')
            ->whereHas('contact', fn ($c) => $c->whereNull('merged_into_id'));
        $groups = array_values(array_filter((array) ($definition['groups'] ?? []), fn ($g) => ! empty($g['rules'])));
        if (! $groups) {
            return $q;
        }
        $outerAny = ($definition['match'] ?? 'all') === 'any';
        $q->where(function (Builder $w) use ($groups, $outerAny) {
            foreach ($groups as $g) {
                $innerAny = ($g['match'] ?? 'all') === 'any';
                $fn = function (Builder $gw) use ($g, $innerAny) {
                    foreach ($g['rules'] as $r) {
                        $rule = fn (Builder $rw) => $this->apply($rw, $r);
                        $innerAny ? $gw->orWhere($rule) : $gw->where($rule);
                    }
                };
                $outerAny ? $w->orWhere($fn) : $w->where($fn);
            }
        });

        return $q;
    }

    private function apply(Builder $q, array $r): void
    {
        $op = $r['op'] ?? '';
        $v = $r['value'] ?? null;
        $list = array_map('strval', (array) $v);
        $in = fn (string $col) => $op === 'not_in' ? $q->whereNotIn($col, $list) : $q->whereIn($col, $list);
        $num = fn (string $col) => match ($op) { 'gte' => $q->where($col, '>=', (float) $v), 'lte' => $q->where($col, '<=', (float) $v), default => $q->where($col, '=', (float) $v) };
        $days = function (string $col) use ($q, $op, $v) {
            $cut = now()->subDays(max(0, (int) $v));
            $op === 'within' ? $q->where($col, '>=', $cut) : $q->where($col, '<', $cut);
        };
        $text = function (string $col, ?Builder $on = null) use ($q, $op, $v) {
            $b = $on ?? $q;
            match ($op) {
                'empty' => $b->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
                'not_empty' => $b->whereNotNull($col)->where($col, '!=', ''),
                default => $b->whereRaw("LOWER($col) LIKE ?", ['%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower((string) $v)).'%']),
            };
        };
        $bool = filter_var($v, FILTER_VALIDATE_BOOLEAN);

        switch ($r['field'] ?? '') {
            case 'status':
                $q->whereHas('status', fn ($s) => $op === 'not_in' ? $s->whereNotIn('semantic', $list) : $s->whereIn('semantic', $list));
                break;
            case 'lifecycle':
                $q->whereHas('status', fn ($s) => $op === 'not_in' ? $s->whereNotIn('category', $list) : $s->whereIn('category', $list));
                break;
            case 'source':
                $q->whereHas('source', fn ($s) => $op === 'not_in' ? $s->whereNotIn('key', $list) : $s->whereIn('key', $list));
                break;
            case 'platform':
                $op === 'not_in' ? $q->whereDoesntHave('firstTouch', fn ($a) => $a->whereIn('platform', $list)) : $q->whereHas('firstTouch', fn ($a) => $a->whereIn('platform', $list));
                break;
            case 'campaign':
                $op === 'not_in' ? $q->whereDoesntHave('firstTouch', fn ($a) => $a->whereIn('campaign_id', $list)) : $q->whereHas('firstTouch', fn ($a) => $a->whereIn('campaign_id', $list));
                break;
            case 'priority': $in('leads.priority'); break;
            case 'score_label': $in('leads.score_label'); break;
            case 'tag':
                $op === 'not_in' ? $q->whereDoesntHave('tags', fn ($t) => $t->whereIn('tags.id', $list)) : $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $list));
                break;
            case 'assigned': $bool ? $q->whereNotNull('leads.assigned_user_id') : $q->whereNull('leads.assigned_user_id'); break;
            case 'contacted': $bool ? $q->whereNotNull('leads.first_contacted_at') : $q->whereNull('leads.first_contacted_at'); break;
            case 'responded': $bool ? $q->whereNotNull('leads.first_responded_at') : $q->whereNull('leads.first_responded_at'); break;
            case 'has_whatsapp': $q->whereHas('contact', fn ($c) => $bool ? $c->whereNotNull('whatsapp_e164') : $c->whereNull('whatsapp_e164')); break;
            case 'has_email': $q->whereHas('contact', fn ($c) => $bool ? $c->whereNotNull('email_normalized') : $c->whereNull('email_normalized')); break;
            case 'created_days': $days('leads.created_at'); break;
            case 'last_activity_days': $days('leads.last_activity_at'); break;
            case 'last_contact_days': $days('leads.last_contacted_at'); break;
            case 'contact_attempts': $num('leads.contact_attempts'); break;
            case 'unanswered_attempts': $num('leads.unanswered_attempts'); break;
            case 'budget_amount': $num('leads.budget_amount'); break;
            case 'product_interest': $text('leads.product_interest'); break;
            case 'city': $q->whereHas('contact', fn ($c) => $text('contacts.city', $c)); break;
            default: $q->whereRaw('1 = 0'); // unknown field never matches (validated upstream anyway)
        }
    }
}
