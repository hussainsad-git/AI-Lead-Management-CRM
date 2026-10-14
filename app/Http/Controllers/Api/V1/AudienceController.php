<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusSemantic;
use App\Exceptions\DomainRuleException;
use App\Jobs\SyncAudienceToMeta;
use App\Models\AudienceSegment;
use App\Models\AudienceSyncJob;
use App\Models\Campaign;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Services\Audiences\AudienceService;
use App\Services\Audiences\MetaAudienceSync;
use App\Services\Audiences\SegmentValidator;
use App\Services\AuditLogger;
use App\Services\LeadExportService;
use App\Services\Meta\MetaAdsClient;
use App\Services\Meta\MetaException;
use Illuminate\Http\Request;

class AudienceController extends ApiController
{
    public function __construct(private AudienceService $audiences, private SegmentValidator $validator, private AuditLogger $audit) {}

    private function guard(): void
    {
        abort_unless($this->user()->hasPermission('audiences.manage'), 403);
    }

    public function meta()
    {
        $this->guard();
        $cfg = config('audiences');
        $opts = [
            'statuses' => LeadStatus::where('is_active', true)->orderBy('sort_order')->get()->unique(fn ($s) => $s->semantic->value)
                ->map(fn ($s) => ['value' => $s->semantic->value, 'label' => $s->semantic === StatusSemantic::Custom ? 'Custom stages' : $s->name])->values(),
            'sources' => LeadSource::orderBy('sort_order')->get()->map(fn ($s) => ['value' => $s->key, 'label' => $s->name]),
            'campaigns' => Campaign::orderBy('name')->limit(500)->get()->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name]),
            'tags' => Tag::orderBy('name')->get()->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name]),
        ];
        $fields = collect($cfg['fields'])->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'type' => $f[1],
            'options' => isset($f[2]) ? (is_string($f[2]) ? $opts[$f[2]] : collect($f[2])->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()) : null])->values();
        $meta = app(MetaAudienceSync::class)->integration();

        return $this->ok([
            'fields' => $fields, 'operators' => $cfg['operators'], 'consent_policies' => $cfg['consent_policies'],
            'exclusion_segments' => AudienceSegment::where('kind', 'exclusion')->orderBy('name')->get(['id', 'name']),
            'reasons' => AudienceService::REASONS,
            'meta' => ['connected' => (bool) $meta, 'account_name' => $meta?->cfg('account_name')],
            'can_export' => $this->user()->hasPermission('leads.export'),
            'limits' => ['max_groups' => $cfg['max_groups'], 'max_rules_per_group' => $cfg['max_rules_per_group']],
        ]);
    }

    public function index()
    {
        $this->guard();

        return $this->ok(AudienceSegment::orderBy('kind')->orderBy('name')->get()->map(fn ($s) => $this->present($s)));
    }

    public function show(AudienceSegment $segment)
    {
        $this->guard();

        return $this->ok($this->present($segment) + ['preview' => $this->audiences->preview($segment, (int) config('audiences.preview_rows'))]);
    }

    public function store(Request $request)
    {
        $this->guard();
        $s = AudienceSegment::create($this->validator->validate($request->all()) + ['created_by' => $this->user()->id]);
        $this->audiences->refresh($s);
        $this->audit->log('audience.created', $s, [], ['name' => $s->name]);

        return $this->ok($this->present($s->fresh()), [], 201);
    }

    public function update(Request $request, AudienceSegment $segment)
    {
        $this->guard();
        $segment->fill($this->validator->validate($request->all(), $segment));
        $changes = array_keys($segment->getDirty());
        $segment->save();
        if (array_intersect($changes, ['definition', 'consent_policy', 'exclusion_segment_ids', 'kind'])) {
            $this->audiences->refresh($segment);
        }
        $this->audit->log('audience.updated', $segment, $changes, ['name' => $segment->name]);

        return $this->ok($this->present($segment->fresh()));
    }

    public function destroy(AudienceSegment $segment)
    {
        $this->guard();
        $used = AudienceSegment::where('kind', 'audience')->get()->filter(fn ($s) => in_array($segment->id, (array) $s->exclusion_segment_ids, true));
        if ($used->isNotEmpty()) {
            throw new DomainRuleException('This exclusion list is attached to: '.$used->pluck('name')->implode(', ').'. Detach it first.', 'in_use', 409);
        }
        $this->audit->log('audience.deleted', $segment, [], ['name' => $segment->name, 'meta_audience_id' => $segment->meta_audience_id]);
        $segment->delete(); // the Meta audience (if any) is left in Ads Manager untouched; we never delete remote assets silently

        return $this->ok(['deleted' => true]);
    }

    /** Live estimate for the builder (no memberships written). */
    public function estimate(Request $request)
    {
        $this->guard();
        $v = $this->validator->validate($request->all() + ['name' => 'estimate']);

        return $this->ok($this->audiences->estimate($v['definition'], $v['consent_policy'], $v['exclusion_segment_ids'], $v['kind']));
    }

    public function refresh(AudienceSegment $segment)
    {
        $this->guard();

        return $this->ok($this->audiences->refresh($segment) + ['segment' => $this->present($segment->fresh())]);
    }

    /** CSV of eligible people (audited). "hashed" gives a Meta-ready customer file (SHA-256). */
    public function export(Request $request, AudienceSegment $segment, MetaAudienceSync $meta)
    {
        $this->guard();
        abort_unless($this->user()->hasPermission('leads.export'), 403);
        $hashed = $request->boolean('hashed');
        $this->audiences->refresh($segment);
        $n = $this->audiences->eligibleContacts($segment)->count();
        AudienceSyncJob::create(['audience_segment_id' => $segment->id, 'kind' => 'csv_export', 'status' => 'success', 'members' => $segment->last_count ?? 0,
            'eligible' => $n, 'uploaded' => $n, 'created_by' => $this->user()->id, 'started_at' => now(), 'finished_at' => now()]);
        $this->audit->log('audience.exported', $segment, [], ['rows' => $n, 'hashed' => $hashed]);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $segment->name).($hashed ? '-meta-hashed' : '').'-'.now()->format('Ymd-Hi').'.csv';
        $headers = $hashed ? ['phone', 'email', 'country'] : ['lead_id', 'name', 'phone', 'whatsapp', 'email', 'city', 'country'];

        return response()->streamDownload(function () use ($segment, $hashed, $headers, $meta) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            $this->audiences->eligibleContacts($segment)->orderBy('id')->chunkById(1000, function ($rows) use ($out, $hashed, $meta) {
                foreach ($rows as $m) {
                    $c = $m->contact;
                    $row = $hashed ? $meta->hashRow($c) : [$m->lead_id, $c?->displayName(), $c?->phone_e164, $c?->whatsapp_e164, $c?->email, $c?->city, $c?->country_code];
                    if ($row) {
                        fputcsv($out, array_map(fn ($v) => LeadExportService::neutralize((string) $v), $row), ',', '"', '');
                    }
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Queues a full replace of the Meta Custom Audience (creating it first if needed). */
    public function sync(AudienceSegment $segment, MetaAudienceSync $meta)
    {
        $this->guard();
        if ($segment->isExclusion()) {
            throw new DomainRuleException('Exclusion lists are not uploaded to Meta. Attach them to an audience segment instead.', 'exclusion_segment', 422);
        }
        $meta->integration() ?? throw new DomainRuleException('Connect the Meta Marketing API (with ads_management) on Integrations first.', 'meta_not_connected', 422);
        if (AudienceSyncJob::where('audience_segment_id', $segment->id)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHours(2))->exists()) {
            throw new DomainRuleException('A sync for this segment is already in progress.', 'sync_in_progress', 409);
        }
        $job = AudienceSyncJob::create(['audience_segment_id' => $segment->id, 'kind' => 'meta_replace', 'status' => 'queued', 'created_by' => $this->user()->id]);
        SyncAudienceToMeta::dispatch($job->id, $segment->organization_id)->afterCommit();

        return $this->ok(['job' => $this->job($job)], [], 202);
    }

    public function jobs(Request $request)
    {
        $this->guard();
        $p = AudienceSyncJob::with(['segment:id,name', 'creator:id,name'])->latest('id')
            ->when($request->integer('segment_id'), fn ($q, $id) => $q->where('audience_segment_id', $id))->paginate($this->perPage());

        return response()->json(['data' => $p->getCollection()->map(fn ($j) => $this->job($j)),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]]);
    }

    /** Live status of the linked Meta audiences (approximate sizes come from Meta, never from us). */
    public function metaStatus(MetaAudienceSync $meta)
    {
        $this->guard();
        $i = $meta->integration();
        $out = [];
        foreach (AudienceSegment::whereNotNull('meta_audience_id')->orderBy('name')->get() as $s) {
            $row = ['segment_id' => $s->id, 'name' => $s->name, 'meta_audience_id' => $s->meta_audience_id, 'meta_audience_name' => $s->meta_audience_name,
                'last_synced_at' => $s->last_synced_at?->toIso8601ZuluString(), 'remote' => null, 'error' => null];
            if ($i) {
                try {
                    $r = MetaAdsClient::for($i)->customAudience($s->meta_audience_id);
                    $row['remote'] = ['name' => $r['name'] ?? null, 'lower' => $r['approximate_count_lower_bound'] ?? null, 'upper' => $r['approximate_count_upper_bound'] ?? null,
                        'delivery' => $r['delivery_status']['description'] ?? ($r['delivery_status']['code'] ?? null), 'operation' => $r['operation_status']['description'] ?? null, 'updated' => $r['time_updated'] ?? null];
                } catch (MetaException $e) {
                    $row['error'] = $e->getMessage();
                }
            }
            $out[] = $row;
        }

        return $this->ok($out, ['connected' => (bool) $i]);
    }

    private function present(AudienceSegment $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'kind' => $s->kind, 'definition' => $s->definition,
            'consent_policy' => $s->consent_policy, 'exclusion_segment_ids' => (array) $s->exclusion_segment_ids, 'auto_sync' => $s->auto_sync,
            'template_key' => $s->template_key, 'meta_audience_id' => $s->meta_audience_id, 'meta_audience_name' => $s->meta_audience_name,
            'last_count' => $s->last_count, 'last_eligible' => $s->last_eligible, 'last_counted_at' => $s->last_counted_at?->toIso8601ZuluString(),
            'last_synced_at' => $s->last_synced_at?->toIso8601ZuluString(),
            'skipped' => $s->memberships()->whereNull('removed_at')->where('eligible', false)->selectRaw('ineligible_reason, COUNT(*) as c')->groupBy('ineligible_reason')->pluck('c', 'ineligible_reason'),
            'last_job' => ($j = $s->syncJobs()->where('kind', 'meta_replace')->latest('id')->first()) ? $this->job($j) : null,
        ];
    }

    private function job(AudienceSyncJob $j): array
    {
        return ['id' => $j->id, 'segment_id' => $j->audience_segment_id, 'segment' => $j->segment?->name, 'kind' => $j->kind, 'status' => $j->status,
            'members' => $j->members, 'eligible' => $j->eligible, 'uploaded' => $j->uploaded, 'skipped' => $j->skipped, 'error' => $j->error,
            'by' => $j->creator?->name, 'created_at' => $j->created_at?->toIso8601ZuluString(), 'finished_at' => $j->finished_at?->toIso8601ZuluString()];
    }
}
