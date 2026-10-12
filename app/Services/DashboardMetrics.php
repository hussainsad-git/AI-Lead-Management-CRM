<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CallTask;
use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures computed from live records.
 *
 * Cohort definition: rates and funnel counts use the cohort of leads CREATED in the selected
 * range and track what has happened to them since. Queue figures (awaiting first contact,
 * overdue follow-ups) are current-state and ignore the range. Advertising metrics are only
 * reported when spend data exists, in the organization currency, for an org-wide scope.
 */
class DashboardMetrics
{
    public function __construct(private LeadVisibility $visibility, private CurrentOrganization $org) {}

    public function build(User $user, DateRange $range): array
    {
        $org = $this->org->require();
        $orgWide = $user->hasAnyPermission('leads.view_all', 'reports.view');
        $scopeIds = $orgWide ? null : $this->visibility->assigneeScope($user);

        $base = fn (): Builder => $this->scoped(Lead::query()->whereNull('archived_at'), $scopeIds);
        $cohort = fn (): Builder => $this->inRange($base(), 'leads.created_at', $range);

        $tz = $org->timezone;
        $now = CarbonImmutable::now($tz);
        $count = fn (Builder $q) => (clone $q)->count();

        $cohortTotal = $count($cohort());
        $contacted = $count($cohort()->whereNotNull('first_contacted_at'));
        $responded = $count($cohort()->whereNotNull('first_responded_at'));
        $qualified = $count($cohort()->whereNotNull('qualified_at'));
        $converted = $count($cohort()->whereNotNull('converted_at'));
        $lost = $count($cohort()->whereHas('status', fn ($q) => $q->where('category', 'lost')));
        $unresponsive = $count($cohort()->whereNotNull('first_contacted_at')->whereNull('first_responded_at'));
        $withAppointment = $count($cohort()->whereHas('appointments', fn ($q) => $q->where('status', '!=', 'cancelled')));

        $appointmentsScheduled = $this->inRange(
            Appointment::query()->where('status', '!=', 'cancelled')->whereHas('lead', fn ($q) => $this->scoped($q, $scopeIds)),
            'appointments.created_at', $range
        )->count();

        $overdueQuery = CallTask::query()->where('status', 'pending')->where('scheduled_at', '<', now())
            ->whereHas('lead', fn ($q) => $this->scoped($q->whereNull('archived_at'), $scopeIds));

        $summary = [
            'total_leads' => $count($base()),
            'new_leads_in_range' => $cohortTotal,
            'new_today' => $count($base()->where('created_at', '>=', $now->startOfDay()->utc())),
            'new_this_week' => $count($base()->where('created_at', '>=', $now->startOfWeek()->utc())),
            'new_this_month' => $count($base()->where('created_at', '>=', $now->startOfMonth()->utc())),
            'awaiting_first_contact' => $count($base()->whereNull('first_contacted_at')->whereHas('status', fn ($q) => $q->where('category', 'open'))),
            'contacted' => $contacted,
            'responded' => $responded,
            'unresponsive' => $unresponsive,
            'qualified' => $qualified,
            'appointments_scheduled' => $appointmentsScheduled,
            'converted' => $converted,
            'lost' => $lost,
            'overdue_follow_ups' => (clone $overdueQuery)->count(),
            'conversion_rate' => $this->pct($converted, $cohortTotal),
            'contact_rate' => $this->pct($contacted, $cohortTotal),
            'response_rate' => $this->pct($responded, $cohortTotal),
            'response_rate_of_contacted' => $this->pct($responded, $contacted),
        ];

        return [
            'range' => $range->toArray(),
            'scope' => $orgWide ? 'organization' : (($scopeIds === [$user->id]) ? 'own' : 'team'),
            'currency' => $org->currency,
            'summary' => $summary,
            'advertising' => $this->advertising($user, $orgWide, $range, $cohort, $org->currency),
            'charts' => [
                'acquisition' => $this->acquisition($cohort(), $range, $tz),
                'by_source' => $this->bySource($cohort()),
                'by_campaign' => $this->byCampaign($cohort()),
                'funnel' => [
                    ['label' => 'Leads', 'value' => $cohortTotal],
                    ['label' => 'Contacted', 'value' => $contacted],
                    ['label' => 'Responded', 'value' => $responded],
                    ['label' => 'Qualified', 'value' => $qualified],
                    ['label' => 'Appointment', 'value' => $withAppointment],
                    ['label' => 'Converted', 'value' => $converted],
                ],
                'status_distribution' => $this->statusDistribution($cohort()),
                'salespeople' => $this->salespeople($cohort(), $scopeIds, $range),
            ],
            'overdue_preview' => $overdueQuery->with(['lead.contact', 'assignee'])->orderBy('scheduled_at')->limit(8)->get()
                ->map(fn (CallTask $t) => [
                    'id' => $t->id, 'lead_id' => $t->lead_id, 'contact' => $t->lead?->contact?->displayName(),
                    'purpose' => $t->purpose, 'scheduled_at' => $t->scheduled_at->toIso8601ZuluString(),
                    'assignee' => $t->assignee?->name, 'priority' => $t->priority,
                ]),
            'definitions' => [
                'cohort' => 'Rates and funnel use leads created in the selected range.',
                'awaiting_first_contact' => 'Open leads with no recorded contact attempt (current, ignores range).',
                'overdue_follow_ups' => 'Pending follow-ups past their scheduled time (current, ignores range).',
                'responded' => 'Lead has a recorded customer response: a connected call, or a stage at/after Responded.',
                'conversion_rate' => 'Converted leads ÷ leads created in range × 100.',
                'cost_per_lead' => 'Ad spend ÷ leads (in range) first-touch attributed to campaigns that have spend recorded in range.',
                'customer_acquisition_cost' => 'Ad spend only ÷ converted attributed leads. Other acquisition costs are not tracked.',
            ],
        ];
    }

    private function advertising(User $user, bool $orgWide, DateRange $range, \Closure $cohort, string $currency): array
    {
        if (! $orgWide || ! $user->hasPermission('campaigns.view')) {
            return ['status' => 'restricted', 'message' => 'Advertising metrics are available to organization-wide reporting roles.'];
        }
        [$from, $to] = $range->localDates();
        $spendQ = CampaignSpend::query()
            ->when($from, fn ($q) => $q->whereBetween('spend_date', [$from, $to]));
        $foreign = (clone $spendQ)->where('currency', '!=', $currency)->count();
        $spendRows = (clone $spendQ)->where('currency', $currency);
        if (! (clone $spendRows)->exists()) {
            return [
                'status' => 'unavailable',
                'message' => 'No ad spend recorded for this period. Record spend on Campaigns (Meta sync arrives in Phase 4).',
                'excluded_foreign_currency_rows' => $foreign,
            ];
        }
        $spendByCampaign = (clone $spendRows)->groupBy('campaign_id')->select('campaign_id', DB::raw('SUM(amount) as total'))->pluck('total', 'campaign_id');
        $campaignIds = $spendByCampaign->keys()->all();
        $spend = round((float) $spendByCampaign->sum(), 2);

        $attributed = fn () => $cohort()->whereHas('firstTouch', fn ($q) => $q->whereIn('campaign_id', $campaignIds));
        $leads = $attributed()->count();
        $qualified = $attributed()->whereNotNull('qualified_at')->count();
        $converted = $attributed()->whereNotNull('converted_at')->count();

        $names = Campaign::whereIn('id', $campaignIds)->pluck('name', 'id');
        $perCampaign = [];
        foreach ($spendByCampaign as $cid => $amount) {
            $l = $cohort()->whereHas('firstTouch', fn ($q) => $q->where('campaign_id', $cid));
            $cl = (clone $l)->count();
            $cq = (clone $l)->whereNotNull('qualified_at')->count();
            $perCampaign[] = [
                'campaign_id' => $cid, 'name' => $names[$cid] ?? ('#'.$cid), 'spend' => round((float) $amount, 2),
                'leads' => $cl, 'qualified' => $cq,
                'cpl' => $cl ? round($amount / $cl, 2) : null, 'cpql' => $cq ? round($amount / $cq, 2) : null,
            ];
        }
        usort($perCampaign, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return [
            'status' => 'available',
            'source' => 'Manually recorded spend',
            'currency' => $currency,
            'spend' => $spend,
            'attributed_leads' => $leads,
            'cost_per_lead' => $leads ? round($spend / $leads, 2) : null,
            'cost_per_qualified_lead' => $qualified ? round($spend / $qualified, 2) : null,
            'customer_acquisition_cost' => $converted ? round($spend / $converted, 2) : null,
            'per_campaign' => $perCampaign,
            'excluded_foreign_currency_rows' => $foreign,
            'notes' => array_values(array_filter([
                $leads ? null : 'No leads in this range are attributed to campaigns with spend; cost per lead cannot be calculated.',
                $foreign ? "$foreign spend row(s) in another currency were excluded." : null,
            ])),
        ];
    }

    private function acquisition(Builder $cohort, DateRange $range, string $tz): array
    {
        $dates = (clone $cohort)->pluck('leads.created_at');
        $weekly = $range->start === null || $range->start->diffInDays($range->end) > 92;
        $buckets = [];
        foreach ($dates as $d) {
            $local = CarbonImmutable::parse($d)->setTimezone($tz);
            $key = $weekly ? $local->startOfWeek()->toDateString() : $local->toDateString();
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }
        if (! $weekly && $range->start) {
            for ($d = $range->start->setTimezone($tz); $d->lt($range->end->setTimezone($tz)); $d = $d->addDay()) {
                $buckets[$d->toDateString()] ??= 0;
            }
        }
        ksort($buckets);

        return ['granularity' => $weekly ? 'week' : 'day', 'labels' => array_keys($buckets), 'values' => array_values($buckets)];
    }

    private function bySource(Builder $cohort): array
    {
        $rows = (clone $cohort)->select('lead_source_id', DB::raw('COUNT(*) as c'))->groupBy('lead_source_id')->pluck('c', 'lead_source_id');
        $names = LeadSource::pluck('name', 'id');

        return $rows->map(fn ($c, $id) => ['label' => $names[$id] ?? 'Unspecified', 'value' => (int) $c])->sortByDesc('value')->values()->all();
    }

    private function byCampaign(Builder $cohort): array
    {
        $rows = (clone $cohort)->leftJoin('lead_attributions as la', fn ($j) => $j->on('la.lead_id', '=', 'leads.id')->where('la.touch', 'first'))
            ->select('la.campaign_id', DB::raw('COUNT(*) as c'))->groupBy('la.campaign_id')->pluck('c', 'campaign_id');
        $names = Campaign::pluck('name', 'id');

        return $rows->map(fn ($c, $id) => ['label' => $id ? ($names[$id] ?? '#'.$id) : 'No campaign attribution', 'value' => (int) $c])
            ->sortByDesc('value')->values()->take(12)->all();
    }

    private function statusDistribution(Builder $cohort): array
    {
        $rows = (clone $cohort)->select('lead_status_id', DB::raw('COUNT(*) as c'))->groupBy('lead_status_id')->pluck('c', 'lead_status_id');

        return LeadStatus::orderBy('sort_order')->get()
            ->filter(fn ($s) => isset($rows[$s->id]))
            ->map(fn ($s) => ['label' => $s->name, 'value' => (int) $rows[$s->id], 'color' => $s->color])->values()->all();
    }

    private function salespeople(Builder $cohort, ?array $scopeIds, DateRange $range): array
    {
        $rows = (clone $cohort)->whereNotNull('assigned_user_id')
            ->select('assigned_user_id',
                DB::raw('COUNT(*) as leads'),
                DB::raw('SUM(CASE WHEN first_contacted_at IS NOT NULL THEN 1 ELSE 0 END) as contacted'),
                DB::raw('SUM(CASE WHEN first_responded_at IS NOT NULL THEN 1 ELSE 0 END) as responded'),
                DB::raw('SUM(CASE WHEN qualified_at IS NOT NULL THEN 1 ELSE 0 END) as qualified'),
                DB::raw('SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted'))
            ->groupBy('assigned_user_id')->get()->keyBy('assigned_user_id');

        $calls = $this->inRange(CallTask::query()->where('status', 'completed'), 'call_tasks.completed_at', $range)
            ->select('completed_by', DB::raw('COUNT(*) as c'))->groupBy('completed_by')->pluck('c', 'completed_by');

        $users = User::query()->inCurrentOrganization()
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))
            ->whereIn('id', $rows->keys()->merge($calls->keys())->unique())->pluck('name', 'id');

        return $users->map(function ($name, $id) use ($rows, $calls) {
            $r = $rows[$id] ?? null;

            return [
                'user_id' => $id, 'name' => $name, 'leads' => (int) ($r->leads ?? 0), 'contacted' => (int) ($r->contacted ?? 0),
                'responded' => (int) ($r->responded ?? 0), 'qualified' => (int) ($r->qualified ?? 0),
                'converted' => (int) ($r->converted ?? 0), 'calls_logged' => (int) ($calls[$id] ?? 0),
            ];
        })->sortByDesc('leads')->values()->all();
    }

    private function scoped(Builder $q, ?array $ids): Builder
    {
        if ($ids === null) {
            return $q;
        }

        return $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn($q->getModel()->qualifyColumn('assigned_user_id'), $ids);
    }

    private function inRange(Builder $q, string $column, DateRange $range): Builder
    {
        if ($range->start) {
            $q->where($column, '>=', $range->start)->where($column, '<', $range->end);
        }

        return $q;
    }

    private function pct(int $num, int $den): ?float
    {
        return $den > 0 ? round($num / $den * 100, 1) : null;
    }
}
