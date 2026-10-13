<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusSemantic;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CallOutcome;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\MessageTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Automation\RuleValidator;
use Illuminate\Http\Request;

class AutomationController extends ApiController
{
    public function __construct(private RuleValidator $validator, private AuditLogger $audit) {}

    private function guard(): void
    {
        abort_unless($this->user()->hasPermission('automation.manage'), 403);
    }

    /** Builder catalogue: triggers, fields (with resolved options), operators, actions and pickers. */
    public function meta()
    {
        $this->guard();
        $cfg = config('automation');
        $opts = [
            'statuses' => LeadStatus::where('is_active', true)->orderBy('sort_order')->get()->unique(fn ($s) => $s->semantic->value)
                ->map(fn ($s) => ['value' => $s->semantic->value, 'label' => $s->semantic === StatusSemantic::Custom ? 'Custom stages' : $s->name])->values(),
            'sources' => LeadSource::orderBy('sort_order')->get()->map(fn ($s) => ['value' => $s->key, 'label' => $s->name]),
            'campaigns' => Campaign::orderBy('name')->limit(500)->get()->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name]),
            'outcomes' => CallOutcome::where('is_active', true)->orderBy('sort_order')->get()->map(fn ($o) => ['value' => $o->key, 'label' => $o->name]),
        ];
        $fields = collect($cfg['fields'])->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'type' => $f[1],
            'options' => isset($f[2]) ? (is_string($f[2]) ? $opts[$f[2]] : collect($f[2])->map(fn ($v) => ['value' => $v, 'label' => ucfirst(str_replace('_', ' ', $v))])->values()) : null])->values();

        return $this->ok([
            'triggers' => collect($cfg['triggers'])->map(fn ($t, $k) => ['key' => $k] + $t)->values(),
            'fields' => $fields, 'operators' => $cfg['operators'],
            'actions' => collect($cfg['actions'])->map(fn ($a, $k) => ['key' => $k] + $a)->values(),
            'users' => User::query()->inCurrentOrganization()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'open_statuses' => LeadStatus::where('is_active', true)->where('category', 'open')->orderBy('sort_order')->get(['id', 'name']),
            'templates' => MessageTemplate::where('status', 'APPROVED')->orderBy('name')->get(['id', 'name', 'language']),
            'placeholders' => ['{name}', '{phone}', '{owner}', '{interest}', '{lead_id}'],
            'max_depth' => $cfg['max_depth'],
        ]);
    }

    public function index()
    {
        $this->guard();

        return $this->ok(AutomationRule::orderByDesc('is_active')->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $this->guard();
        $rule = AutomationRule::create($this->validator->validate($request->all()) + ['created_by' => $this->user()->id]);
        $this->audit->log('automation.created', $rule, [], ['name' => $rule->name]);

        return $this->ok($rule, [], 201);
    }

    public function update(Request $request, AutomationRule $rule)
    {
        $this->guard();
        // A bare {is_active} toggle keeps everything else as stored.
        $input = $request->keys() === ['is_active'] ? array_merge($rule->only('name', 'description', 'trigger', 'delay_minutes', 'conditions', 'actions'), $request->only('is_active')) : $request->all();
        $rule->fill($this->validator->validate($input));
        $changes = $rule->getDirty();
        $rule->save();
        $this->audit->log('automation.updated', $rule, array_keys($changes), ['name' => $rule->name]);

        return $this->ok($rule);
    }

    public function destroy(AutomationRule $rule)
    {
        $this->guard();
        $this->audit->log('automation.deleted', $rule, [], ['name' => $rule->name]);
        $rule->delete();

        return $this->ok(['deleted' => true]);
    }

    /** Dry run against one lead: shows which conditions pass. Never performs actions. */
    public function test(Request $request, AutomationRule $rule, ConditionEvaluator $eval)
    {
        $this->guard();
        $data = $request->validate(['lead_id' => ['required', 'integer']]);
        $lead = $this->visibleLead(Lead::findOrFail($data['lead_id']));
        $ctx = [];
        if ($rule->trigger === 'followup.overdue') {
            $ctx['task_priority'] = $lead->callTasks()->where('status', 'pending')->orderBy('scheduled_at')->value('priority');
        }
        if ($rule->trigger === 'call.logged') {
            $ctx['outcome_key'] = $lead->callTasks()->where('status', 'completed')->latest('completed_at')->first()?->outcome?->key;
        }
        $checks = $eval->explain($rule->conditions, $lead, $ctx);

        return $this->ok([
            'lead' => ['id' => $lead->id, 'name' => $lead->contact->displayName()],
            'checks' => $checks,
            'would_run' => $eval->passes($rule->conditions, $lead, $ctx) && ! $lead->archived_at,
            'note' => 'Dry run only — no actions were performed. Event context (e.g. the call outcome) is taken from the lead\'s latest record.',
        ]);
    }

    public function executions(Request $request)
    {
        $this->guard();
        $q = AutomationExecution::with(['rule:id,name', 'lead.contact'])->latest('id')
            ->when($request->integer('rule_id'), fn ($q, $id) => $q->where('automation_rule_id', $id))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s));
        $p = $q->paginate($this->perPage());

        return response()->json([
            'data' => $p->getCollection()->map(fn ($e) => [
                'id' => $e->id, 'rule' => $e->rule?->name, 'rule_id' => $e->automation_rule_id, 'trigger' => $e->trigger,
                'lead_id' => $e->lead_id, 'lead' => $e->lead?->contact?->displayName(), 'status' => $e->status,
                'results' => $e->results, 'error' => $e->error, 'depth' => $e->depth, 'created_at' => $e->created_at,
            ]),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }
}
