<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Http\Requests\LogCallRequest;
use App\Http\Requests\ScheduleFollowUpRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\CallTaskResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\LeadResource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\AppointmentService;
use App\Services\AssignmentService;
use App\Services\AuditLogger;
use App\Services\ConsentService;
use App\Services\FollowUpService;
use App\Services\LeadExportService;
use App\Services\LeadFilter;
use App\Services\LeadService;
use App\Services\LeadStatusService;
use App\Services\LeadVisibility;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class LeadController extends ApiController
{
    private const LIST_RELATIONS = ['contact', 'status', 'source', 'assignee', 'tags', 'firstTouch'];

    public function __construct(private LeadVisibility $visibility, private LeadFilter $filter) {}

    public function index(Request $request)
    {
        $q = $this->visibility->apply(Lead::query(), $this->user());
        $this->filter->apply($q, $request->all());
        $this->filter->sort($q, $request->input('sort'), $request->input('dir'));
        $page = $q->with(self::LIST_RELATIONS)->paginate($this->perPage());

        return $this->paginated($page, LeadResource::class);
    }

    public function store(StoreLeadRequest $request, LeadService $leads)
    {
        $lead = $leads->create($request->validated(), $this->user());

        return $this->ok(new LeadResource($lead), [], 201);
    }

    public function show(Lead $lead, ConsentService $consent)
    {
        $this->visibleLead($lead)->load(array_merge(self::LIST_RELATIONS, ['creator', 'lastTouch.campaign']));
        $other = Lead::where('contact_id', $lead->contact_id)->where('id', '!=', $lead->id)->with('status')->get();
        $visibleOther = $other->filter(fn ($l) => $this->visibility->canSee($this->user(), $l));

        return $this->ok([
            'lead' => new LeadResource($lead),
            'first_touch' => $lead->firstTouch,
            'last_touch' => $lead->lastTouch,
            'consent' => $consent->summary($lead->contact),
            'consent_records' => $lead->contact->consents()->limit(20)->get(['channel', 'status', 'source', 'evidence', 'recorded_at']),
            'other_leads' => $visibleOther->map(fn ($l) => ['id' => $l->id, 'status' => $l->status->name, 'created_at' => $l->created_at->toIso8601ZuluString()])->values(),
            'hidden_other_leads' => $other->count() - $visibleOther->count(),
            'conversation_id' => $this->user()->hasPermission('whatsapp.view') ? \App\Models\Conversation::where('contact_id', $lead->contact_id)->value('id') : null,
            'call_tasks' => CallTaskResource::collection($lead->callTasks()->with(['outcome', 'assignee'])->latest('scheduled_at')->limit(50)->get()),
            'appointments' => AppointmentResource::collection($lead->appointments()->with('assignee')->latest('starts_at')->get()),
            'status_history' => $lead->statusHistory()->with(['fromStatus:id,name', 'toStatus:id,name', 'user:id,name'])->latest('id')->limit(50)->get()
                ->map(fn ($h) => ['from' => $h->fromStatus?->name, 'to' => $h->toStatus?->name, 'reason' => $h->reason, 'by' => $h->user?->name, 'via' => $h->changed_via, 'at' => $h->created_at->toIso8601ZuluString()]),
        ]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead, LeadService $leads)
    {
        $this->visibleLead($lead);
        if ($request->has('contact') && ! $this->user()->hasPermission('contacts.update')) {
            abort(403);
        }

        return $this->ok(new LeadResource($leads->update($lead, $request->validated())));
    }

    public function timeline(Request $request, Lead $lead)
    {
        $this->visibleLead($lead);
        $q = Activity::query()->where(fn ($w) => $w->where('lead_id', $lead->id)->orWhere(fn ($c) => $c->whereNull('lead_id')->where('contact_id', $lead->contact_id)))
            ->with('user:id,name')->orderByDesc('occurred_at')->orderByDesc('id');
        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }

        return $this->paginated($q->paginate(min((int) $request->input('per_page', 50), 100)), ActivityResource::class);
    }

    public function addNote(Request $request, Lead $lead, LeadService $leads)
    {
        abort_unless($this->user()->hasPermission('leads.update'), 403);
        $this->visibleLead($lead);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return $this->ok(new ActivityResource($leads->addNote($lead, $data['body'])->load('user')), [], 201);
    }

    public function changeStatus(Request $request, Lead $lead, LeadStatusService $statuses)
    {
        abort_unless($this->user()->hasPermission('leads.update'), 403);
        $this->visibleLead($lead);
        $data = $request->validate([
            'lead_status_id' => ['required', 'integer', Rule::exists('lead_statuses', 'id')->where('organization_id', $lead->organization_id)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $lead = $statuses->transition($lead->load('status', 'contact'), LeadStatus::findOrFail($data['lead_status_id']), $data['reason'] ?? null);

        return $this->ok(new LeadResource($lead->fresh(self::LIST_RELATIONS)));
    }

    public function assign(Request $request, Lead $lead, AssignmentService $assignment)
    {
        abort_unless($this->user()->hasPermission('leads.assign'), 403);
        $this->visibleLead($lead);
        $data = $request->validate(['assigned_user_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:191']]);
        $this->assertAssignableByManager($data['assigned_user_id'] ?? null);
        $assignment->assign($lead, $data['assigned_user_id'] ?? null, $data['reason'] ?? null);

        return $this->ok(new LeadResource($lead->fresh(self::LIST_RELATIONS)));
    }

    public function archive(Request $request, Lead $lead, LeadService $leads, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('leads.archive'), 403);
        $this->visibleLead($lead);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $leads->archive($lead, $data['reason']);
        $audit->log('lead.archived', $lead, [], ['reason' => $data['reason']]);

        return $this->ok(['archived' => true]);
    }

    public function scheduleFollowUp(ScheduleFollowUpRequest $request, Lead $lead, FollowUpService $followUps)
    {
        $this->visibleLead($lead);
        $task = $followUps->schedule($lead, $request->validated());

        return $this->ok(new CallTaskResource($task->load(['assignee', 'outcome'])), [], 201);
    }

    public function logCall(LogCallRequest $request, Lead $lead, FollowUpService $followUps)
    {
        $this->visibleLead($lead);
        $task = $followUps->logCall($lead->load('status', 'contact'), null, $request->validated());

        return $this->ok(new CallTaskResource($task->load(['assignee', 'outcome'])), [], 201);
    }

    public function createAppointment(Request $request, Lead $lead, AppointmentService $appointments)
    {
        abort_unless($this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all'), 403);
        $this->visibleLead($lead);
        $request->merge(['starts_at' => LocalTime::toUtc($request->input('starts_at')), 'ends_at' => LocalTime::toUtc($request->input('ends_at'))]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:meeting,site_visit,video,call'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $lead->organization_id)->where('is_active', true)],
        ]);
        $appt = $appointments->create($lead->load('contact'), $data);

        return $this->ok(new AppointmentResource($appt->load('assignee')), [], 201);
    }

    /** Bulk actions over explicit ids or the current filter (capped). */
    public function bulk(Request $request, LeadService $leads, LeadStatusService $statuses, AssignmentService $assignment, FollowUpService $followUps, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('leads.bulk_update'), 403);
        $data = $request->validate([
            'action' => ['required', 'in:assign,status,priority,tags_add,follow_up,archive,audience_add'],
            'audience_segment_id' => ['required_if:action,audience_add', 'integer'],
            'ids' => ['required_without:filters', 'array', 'max:5000'],
            'ids.*' => ['integer'],
            'filters' => ['required_without:ids', 'array'],
            'confirm' => ['nullable', 'boolean'],
            'assigned_user_id' => ['nullable', 'integer'],
            'lead_status_id' => ['required_if:action,status', 'integer', Rule::exists('lead_statuses', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'reason' => ['nullable', 'string', 'max:255'],
            'priority' => ['required_if:action,priority', 'in:low,normal,high,urgent'],
            'tags' => ['required_if:action,tags_add', 'array'],
            'tags.*' => ['string', 'max:60'],
            'scheduled_at' => ['required_if:action,follow_up', 'nullable', 'string'],
        ]);
        $perm = ['assign' => 'leads.assign', 'archive' => 'leads.archive', 'follow_up' => 'followups.manage_all', 'audience_add' => 'audiences.manage'][$data['action']] ?? 'leads.update';
        abort_unless($this->user()->hasPermission($perm), 403);
        if ($data['action'] === 'assign') {
            $this->assertAssignableByManager($data['assigned_user_id'] ?? null);
        }

        $status = $data['action'] === 'status' ? LeadStatus::findOrFail($data['lead_status_id']) : null;
        $sensitive = $data['action'] === 'archive' || ($status && $status->category !== 'open');
        if ($sensitive && ! ($data['confirm'] ?? false)) {
            throw new DomainRuleException('Confirm this action before it is applied to the selected leads.', 'confirmation_required', 422);
        }
        if ($data['action'] === 'archive' && empty($data['reason'])) {
            throw new DomainRuleException('A reason is required to archive leads.', 'reason_required');
        }

        $q = $this->visibility->apply(Lead::query(), $this->user());
        isset($data['ids']) ? $q->whereIn('leads.id', $data['ids'])->whereNull('archived_at') : $this->filter->apply($q, $data['filters']);
        $total = (clone $q)->count();
        if ($total > 5000) {
            throw new DomainRuleException("Bulk actions are limited to 5000 leads; {$total} match.", 'bulk_too_large');
        }
        $scheduledAt = isset($data['scheduled_at']) ? LocalTime::toUtc($data['scheduled_at']) : null;
        if ($data['action'] === 'audience_add') {
            $segment = \App\Models\AudienceSegment::where('kind', 'audience')->findOrFail((int) $data['audience_segment_id']);
            $ids = (clone $q)->pluck('leads.id')->all();
            $added = app(\App\Services\Audiences\AudienceService::class)->addLeads($segment, $ids, $this->user()->id);
            $audit->log('leads.bulk_audience_add', $segment, [], ['matched' => $total, 'added' => $added]);
            $skipped = $segment->fresh()->memberships()->whereNull('removed_at')->whereIn('lead_id', $ids)->where('eligible', false)->count();

            return $this->ok(['matched' => $total, 'updated' => $added, 'failed' => [], 'failed_count' => 0,
                'note' => $skipped ? "$skipped of the added leads are in the segment but not eligible for export/upload (opted out, consent, exclusion list or no identifier)." : null]);
        }

        $done = 0;
        $failed = [];
        foreach ($q->with(['status', 'contact'])->lazyById(200, 'leads.id', 'id') as $lead) {
            try {
                match ($data['action']) {
                    'assign' => $assignment->assign($lead, $data['assigned_user_id'] ?? null, $data['reason'] ?? 'Bulk assignment'),
                    'status' => $statuses->transition($lead, $status, $data['reason'] ?? null),
                    'priority' => $lead->forceFill(['priority' => $data['priority']])->save(),
                    'tags_add' => $leads->syncTags($lead, array_merge($lead->tags()->pluck('name')->all(), $data['tags'])),
                    'follow_up' => $followUps->schedule($lead, ['scheduled_at' => $scheduledAt, 'purpose' => $data['reason'] ?? 'Follow-up call']),
                    'archive' => $leads->archive($lead, $data['reason']),
                };
                $done++;
            } catch (Throwable $e) {
                if (! $e instanceof DomainRuleException && ! $e instanceof \Illuminate\Validation\ValidationException) {
                    report($e);
                }
                $failed[] = ['id' => $lead->id, 'message' => $e instanceof DomainRuleException ? $e->getMessage() : 'Could not update this lead.'];
            }
        }
        $audit->log('leads.bulk_'.$data['action'], null, [], ['matched' => $total, 'updated' => $done, 'failed' => count($failed),
            'params' => array_intersect_key($data, array_flip(['assigned_user_id', 'lead_status_id', 'priority', 'tags', 'reason']))]);

        return $this->ok(['matched' => $total, 'updated' => $done, 'failed' => array_slice($failed, 0, 50), 'failed_count' => count($failed)]);
    }

    public function export(Request $request, LeadExportService $exports)
    {
        abort_unless($this->user()->hasPermission('leads.export'), 403);
        $data = $request->validate([
            'format' => ['required', 'in:csv,xlsx'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
            'ids' => ['nullable', 'array', 'max:50000'],
            'ids.*' => ['integer'],
            'filters' => ['nullable', 'array'],
        ]);

        return $exports->export($this->user(), $data['filters'] ?? [], $data['columns'] ?? [], $data['format'], $data['ids'] ?? null);
    }

    /** Managers with only team visibility may assign within their team. */
    private function assertAssignableByManager(?int $userId): void
    {
        if ($userId === null) {
            return;
        }
        $scope = $this->visibility->assigneeScope($this->user());
        if ($scope !== null && ! in_array($userId, $scope, true)) {
            throw new DomainRuleException('You can only assign leads to members of your team.', 'assignee_out_of_scope', 403);
        }
    }
}
