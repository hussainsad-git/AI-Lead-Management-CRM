<?php

namespace App\Services;

use App\Support\DateRange;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/** Server-side lead filtering shared by list, pipeline, bulk actions and export. */
class LeadFilter
{
    public const SORTABLE = [
        'id' => 'leads.id', 'created_at' => 'leads.created_at', 'last_activity_at' => 'leads.last_activity_at',
        'next_follow_up_at' => 'leads.next_follow_up_at', 'last_contacted_at' => 'leads.last_contacted_at',
        'priority' => 'leads.priority', 'name' => 'contacts.name', 'status' => 'lead_statuses.sort_order', 'score' => 'leads.score',
    ];

    public function __construct(private CurrentOrganization $org) {}

    public function apply(Builder $q, array $f): Builder
    {
        $tz = $this->org->require()->timezone;

        if (($f['archived'] ?? 'exclude') === 'only') {
            $q->whereNotNull('leads.archived_at');
        } elseif (($f['archived'] ?? 'exclude') !== 'include') {
            $q->whereNull('leads.archived_at');
        }

        if ($s = trim((string) ($f['q'] ?? ''))) {
            $digits = preg_replace('/\D/', '', $s);
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($s)).'%';
            $q->where(function (Builder $w) use ($s, $digits, $like) {
                $w->whereHas('contact', fn (Builder $outer) => $outer->where(function (Builder $c) use ($digits, $like) {
                    $c->whereRaw('LOWER(contacts.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(contacts.company_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(contacts.email) LIKE ?', [$like]);
                    if (strlen($digits) >= 4) {
                        // match on the national significant part so "0300..." finds "+92300..."
                        $tail = ltrim($digits, '0');
                        $c->orWhere('contacts.phone_e164', 'like', '%'.$tail.'%')
                            ->orWhere('contacts.whatsapp_e164', 'like', '%'.$tail.'%')
                            ->orWhere('contacts.phone_raw', 'like', '%'.$digits.'%');
                    }
                }))
                    ->orWhereRaw('LOWER(leads.source_notes) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(leads.product_interest) LIKE ?', [$like])
                    ->orWhereHas('activities', fn ($a) => $a->where('type', 'note')->whereRaw('LOWER(body) LIKE ?', [$like]));
                if (ctype_digit(ltrim($s, '#'))) {
                    $w->orWhere('leads.id', (int) ltrim($s, '#'));
                }
            });
        }

        foreach (['status_ids' => 'leads.lead_status_id', 'source_ids' => 'leads.lead_source_id'] as $key => $col) {
            if ($ids = $this->ids($f[$key] ?? null)) {
                $q->whereIn($col, $ids);
            }
        }
        if (! empty($f['status_category'])) {
            $q->whereHas('status', fn ($s) => $s->whereIn('category', (array) $f['status_category']));
        }
        if ($vals = (array) ($f['assigned_user_ids'] ?? [])) {
            $ids = $this->ids($vals);
            $unassigned = in_array('unassigned', $vals, true);
            $q->where(function ($w) use ($ids, $unassigned) {
                if ($ids) {
                    $w->whereIn('leads.assigned_user_id', $ids);
                }
                if ($unassigned) {
                    $w->orWhereNull('leads.assigned_user_id');
                }
            });
        }
        if ($ids = $this->ids($f['campaign_ids'] ?? null)) {
            $q->whereHas('firstTouch', fn ($a) => $a->whereIn('campaign_id', $ids));
        }
        if ($ids = $this->ids($f['tag_ids'] ?? null)) {
            $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $ids));
        }
        if ($sl = array_intersect((array) ($f['score_label'] ?? []), ['hot', 'warm', 'cold', 'needs_review'])) {
            $q->whereIn('leads.score_label', $sl);
        }
        if ($p = array_intersect((array) ($f['priority'] ?? []), ['low', 'normal', 'high', 'urgent'])) {
            $q->whereIn('leads.priority', $p);
        }
        if (! empty($f['created_from']) || ! empty($f['created_to'])) {
            $r = DateRange::resolve('custom', $tz, $f['created_from'] ?? '2000-01-01', $f['created_to'] ?? now($tz)->toDateString());
            $q->where('leads.created_at', '>=', $r->start)->where('leads.created_at', '<', $r->end);
        }
        $now = now();
        $todayEnd = now($tz)->endOfDay()->utc();
        match ($f['follow_up'] ?? null) {
            'overdue' => $q->where('leads.next_follow_up_at', '<', $now),
            'today' => $q->whereBetween('leads.next_follow_up_at', [now($tz)->startOfDay()->utc(), $todayEnd]),
            'upcoming' => $q->where('leads.next_follow_up_at', '>', $todayEnd),
            'none' => $q->whereNull('leads.next_follow_up_at'),
            default => null,
        };
        match ($f['contacted'] ?? null) {
            'yes' => $q->whereNotNull('leads.first_contacted_at'),
            'no' => $q->whereNull('leads.first_contacted_at'),
            default => null,
        };
        match ($f['responded'] ?? null) {
            'yes' => $q->whereNotNull('leads.first_responded_at'),
            'no' => $q->whereNull('leads.first_responded_at'),
            default => null,
        };
        if (isset($f['attempts_min']) && $f['attempts_min'] !== '') {
            $q->where('leads.contact_attempts', '>=', (int) $f['attempts_min']);
        }
        if (isset($f['attempts_max']) && $f['attempts_max'] !== '') {
            $q->where('leads.contact_attempts', '<=', (int) $f['attempts_max']);
        }
        match ($f['opt_out'] ?? null) {
            'opted_out' => $q->whereHas('contact', fn ($c) => $c->where('do_not_contact', true)),
            'contactable' => $q->whereHas('contact', fn ($c) => $c->where('do_not_contact', false)),
            default => null,
        };

        return $q;
    }

    public function sort(Builder $q, ?string $sort, ?string $dir): Builder
    {
        $col = self::SORTABLE[$sort ?? 'created_at'] ?? 'leads.created_at';
        $dir = strtolower((string) $dir) === 'asc' ? 'asc' : 'desc';
        if (str_starts_with($col, 'contacts.')) {
            $q->leftJoin('contacts', 'contacts.id', '=', 'leads.contact_id');
        }
        if (str_starts_with($col, 'lead_statuses.')) {
            $q->leftJoin('lead_statuses', 'lead_statuses.id', '=', 'leads.lead_status_id');
        }
        if ($col === 'leads.priority') {
            return $q->orderByRaw("CASE leads.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END $dir")->orderBy('leads.id', 'desc');
        }
        // NULLs last for date columns regardless of direction
        if (str_contains($col, '_at')) {
            $q->orderByRaw("CASE WHEN $col IS NULL THEN 1 ELSE 0 END");
        }

        return $q->orderBy($col, $dir)->orderBy('leads.id', 'desc')->select('leads.*');
    }

    private function ids(mixed $v): array
    {
        return array_values(array_filter(array_map('intval', (array) $v), fn ($i) => $i > 0));
    }
}
