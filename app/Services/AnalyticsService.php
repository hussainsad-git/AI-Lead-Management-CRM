<?php

namespace App\Services;

use App\Models\AiInteraction;
use App\Models\CallTask;
use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\Conversation;
use App\Models\KbDocument;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Organization-wide business insights. Every figure is computed from stored records for the range. */
class AnalyticsService
{
    public function __construct(private CurrentOrganization $org) {}

    public function build(DateRange $r, array $f = []): array
    {
        return [
            'range' => $r->toArray(),
            'messaging' => $this->messaging($r),
            'ai' => $this->ai($r),
            'sources' => $this->sources($r, $f),
            'campaigns' => $this->campaigns($r, $f),
            'salespeople' => $this->salespeople($r, $f),
            'scores' => $this->scores(),
            'definitions' => [
                'first_response' => 'Median minutes from a new conversation to the first reply (human, AI or auto-reply).',
                'ai_resolution' => 'Conversations where the AI replied and no human handoff was needed ÷ conversations the AI replied in.',
                'first_contact' => 'Average hours from lead creation to the first logged contact attempt or first outbound message.',
                'cpl' => 'Recorded ad spend in range (organization currency) ÷ leads in range first-touch attributed to that campaign.',
            ],
        ];
    }

    private function in(Builder $q, string $col, DateRange $r): Builder
    {
        return $r->start ? $q->where($col, '>=', $r->start)->where($col, '<', $r->end) : $q;
    }

    private function leads(DateRange $r, array $f): Builder
    {
        $q = $this->in(Lead::query()->whereNull('archived_at'), 'leads.created_at', $r);
        if (! empty($f['source_id'])) {
            $q->where('lead_source_id', (int) $f['source_id']);
        }
        if (! empty($f['user_id'])) {
            $q->where('assigned_user_id', (int) $f['user_id']);
        }
        if (! empty($f['campaign_id'])) {
            $q->whereHas('firstTouch', fn ($a) => $a->where('campaign_id', (int) $f['campaign_id']));
        }
        if (! empty($f['platform'])) {
            $q->whereHas('firstTouch', fn ($a) => $a->where('platform', $f['platform']));
        }

        return $q;
    }

    public function messaging(DateRange $r): array
    {
        $tz = $this->org->require()->timezone;
        $msgs = $this->in(Message::query(), 'occurred_at', $r);
        $in = (clone $msgs)->where('direction', 'in')->count();
        $out = (clone $msgs)->where('direction', 'out')->selectRaw('sender_type, COUNT(*) as c')->groupBy('sender_type')->pluck('c', 'sender_type');
        $failed = (clone $msgs)->where('direction', 'out')->where('status', 'failed')->count();
        $newConvs = $this->in(Conversation::query(), 'created_at', $r);
        $convCount = (clone $newConvs)->count();
        $mins = (clone $newConvs)->whereNotNull('first_response_at')->get(['created_at', 'first_response_at'])
            ->map(fn ($c) => max(0, $c->created_at->diffInSeconds($c->first_response_at, true) / 60))->sort()->values();

        $series = [];
        (clone $msgs)->get(['direction', 'occurred_at'])->each(function ($m) use (&$series, $tz) {
            $d = $m->occurred_at->copy()->setTimezone($tz)->toDateString();
            $series[$d] ??= ['in' => 0, 'out' => 0];
            $series[$d][$m->direction]++;
        });
        if ($r->start && $r->start->diffInDays($r->end) <= 92) {
            for ($d = $r->start->setTimezone($tz); $d->lt($r->end->setTimezone($tz)); $d = $d->addDay()) {
                $series[$d->toDateString()] ??= ['in' => 0, 'out' => 0];
            }
        }
        ksort($series);

        return [
            'inbound' => $in, 'outbound' => (int) $out->sum(), 'outbound_by' => $out->map(fn ($c) => (int) $c), 'failed' => $failed,
            'new_conversations' => $convCount,
            'whatsapp_leads' => $this->in(Lead::query()->whereHas('conversations'), 'created_at', $r)->count(),
            'median_first_response_min' => $mins->count() ? round($mins[intdiv($mins->count(), 2)], 1) : null,
            'awaiting_human' => Conversation::whereNotNull('handoff_at')->where('status', '!=', 'closed')->count(),
            'languages' => (clone $newConvs)->whereNotNull('language')->selectRaw('language, COUNT(*) as c')->groupBy('language')->pluck('c', 'language'),
            'series' => ['labels' => array_keys($series), 'in' => array_column($series, 'in'), 'out' => array_column($series, 'out')],
        ];
    }

    public function ai(DateRange $r): array
    {
        $q = $this->in(AiInteraction::query(), 'created_at', $r);
        $replies = (clone $q)->where('kind', 'reply')->where('status', 'ok');
        $aiConvs = (clone $replies)->where('outcome', 'answered')->distinct()->pluck('conversation_id')->filter();
        $handoffConvs = Conversation::whereIn('id', $aiConvs)->whereNotNull('handoff_at')->count();

        return [
            'interactions' => (clone $q)->count(),
            'by_kind' => (clone $q)->selectRaw('kind, COUNT(*) as c')->groupBy('kind')->pluck('c', 'kind'),
            'by_outcome' => (clone $q)->whereNotNull('outcome')->selectRaw('outcome, COUNT(*) as c')->groupBy('outcome')->pluck('c', 'outcome'),
            'errors' => (clone $q)->where('status', 'error')->count(),
            'ai_conversations' => $aiConvs->count(),
            'resolution_rate' => $aiConvs->count() ? round(($aiConvs->count() - $handoffConvs) / $aiConvs->count() * 100, 1) : null,
            'feedback' => (clone $q)->whereNotNull('feedback')->selectRaw('feedback, COUNT(*) as c')->groupBy('feedback')->pluck('c', 'feedback'),
            'tokens' => (int) (clone $q)->sum(DB::raw('COALESCE(prompt_tokens,0) + COALESCE(completion_tokens,0)')),
            'avg_latency_ms' => ($v = (clone $q)->where('status', 'ok')->avg('latency_ms')) ? (int) $v : null,
            'top_documents' => KbDocument::where('status', 'ready')->withSum('chunks', 'times_retrieved')->orderByDesc('chunks_sum_times_retrieved')->limit(8)->get()
                ->filter(fn ($d) => $d->chunks_sum_times_retrieved > 0)->map(fn ($d) => ['title' => $d->title, 'uses' => (int) $d->chunks_sum_times_retrieved])->values(),
            'unanswered' => (clone $q)->where('outcome', 'handoff')->whereNotNull('input')->latest('id')->limit(10)->pluck('input'),
        ];
    }

    public function sources(DateRange $r, array $f): array
    {
        $names = LeadSource::pluck('name', 'id');

        return $this->group($this->leads($r, $f)->get(), 'lead_source_id')
            ->map(fn ($g, $id) => ['source' => $names[$id] ?? 'Unspecified'] + $this->funnel($g))->sortByDesc('leads')->values()->all();
    }

    public function campaigns(DateRange $r, array $f): array
    {
        $leads = $this->leads($r, $f)->with('firstTouch:id,lead_id,campaign_id')->get();
        $cur = $this->org->require()->currency;
        [$from, $to] = $r->localDates();
        $spend = CampaignSpend::where('currency', $cur)->when($from, fn ($q) => $q->whereBetween('spend_date', [$from, $to]))
            ->selectRaw('campaign_id, SUM(amount) as s')->groupBy('campaign_id')->pluck('s', 'campaign_id');
        $names = Campaign::pluck('name', 'id');

        return $leads->groupBy(fn ($l) => $l->firstTouch?->campaign_id ?? 0)
            ->map(function ($g, $cid) use ($spend, $names, $cur) {
                $fn = $this->funnel($g);
                $s = $cid && isset($spend[$cid]) ? round((float) $spend[$cid], 2) : null;

                return ['campaign' => $cid ? ($names[$cid] ?? '#'.$cid) : 'No campaign attribution', 'campaign_id' => $cid ?: null, 'currency' => $cur, 'spend' => $s,
                    'cpl' => $s !== null && $fn['leads'] ? round($s / $fn['leads'], 2) : null,
                    'cpql' => $s !== null && $fn['qualified'] ? round($s / $fn['qualified'], 2) : null,
                    'cac' => $s !== null && $fn['converted'] ? round($s / $fn['converted'], 2) : null] + $fn;
            })->sortByDesc('leads')->values()->all();
    }

    public function salespeople(DateRange $r, array $f): array
    {
        $leads = $this->leads($r, $f)->whereNotNull('assigned_user_id')->get();
        $calls = $this->in(CallTask::query()->where('status', 'completed'), 'completed_at', $r)->selectRaw('completed_by, COUNT(*) as c')->groupBy('completed_by')->pluck('c', 'completed_by');
        $msgs = $this->in(Message::query()->where('sender_type', 'user'), 'occurred_at', $r)->selectRaw('user_id, COUNT(*) as c')->groupBy('user_id')->pluck('c', 'user_id');
        $overdue = CallTask::where('status', 'pending')->where('scheduled_at', '<', now())->selectRaw('assigned_user_id, COUNT(*) as c')->groupBy('assigned_user_id')->pluck('c', 'assigned_user_id');
        $ids = $leads->pluck('assigned_user_id')->merge($calls->keys())->merge($msgs->keys())->filter()->unique();
        $names = User::query()->inCurrentOrganization()->whereIn('id', $ids)->pluck('name', 'id');
        $byUser = $leads->groupBy('assigned_user_id');

        return $names->map(fn ($n, $id) => ['user_id' => $id, 'name' => $n, 'calls_logged' => (int) ($calls[$id] ?? 0),
            'whatsapp_sent' => (int) ($msgs[$id] ?? 0), 'overdue_follow_ups' => (int) ($overdue[$id] ?? 0)] + $this->funnel($byUser[$id] ?? collect()))
            ->sortByDesc('leads')->values()->all();
    }

    public function scores(): array
    {
        return Lead::whereNull('archived_at')->whereNotNull('score_label')->selectRaw('score_label, COUNT(*) as c')->groupBy('score_label')->pluck('c', 'score_label')->all();
    }

    private function group(Collection $leads, string $key): Collection
    {
        return $leads->groupBy(fn ($l) => $l->{$key} ?? 0);
    }

    private function funnel(Collection $g): array
    {
        $n = $g->count();
        $contacted = $g->whereNotNull('first_contacted_at')->count();
        $hours = $g->whereNotNull('first_contacted_at')->map(fn ($l) => $l->created_at->diffInMinutes($l->first_contacted_at, true) / 60);
        $pct = fn ($a) => $n ? round($a / $n * 100, 1) : null;

        return [
            'leads' => $n, 'contacted' => $contacted, 'responded' => $g->whereNotNull('first_responded_at')->count(),
            'qualified' => $q = $g->whereNotNull('qualified_at')->count(), 'converted' => $c = $g->whereNotNull('converted_at')->count(),
            'lost' => $g->filter(fn ($l) => $l->closed_at && ! $l->converted_at)->count(),
            // null (not 0) when no won deal has a value entered
            'revenue' => $g->whereNotNull('converted_at')->whereNotNull('deal_value')->isEmpty() ? null : round((float) $g->whereNotNull('converted_at')->sum('deal_value'), 2),
            'response_rate' => $pct($g->whereNotNull('first_responded_at')->count()), 'qualified_rate' => $pct($q), 'conversion_rate' => $pct($c),
            'avg_first_contact_h' => $hours->count() ? round($hours->avg(), 1) : null,
            'avg_attempts' => $n ? round($g->avg('contact_attempts'), 1) : null,
        ];
    }
}
