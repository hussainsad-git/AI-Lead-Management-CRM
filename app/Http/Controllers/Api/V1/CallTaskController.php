<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LogCallRequest;
use App\Http\Resources\CallTaskResource;
use App\Models\CallTask;
use App\Services\FollowUpService;
use App\Services\LeadVisibility;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CallTaskController extends ApiController
{
    public function __construct(private LeadVisibility $visibility) {}

    private function scoped(): Builder
    {
        $user = $this->user();
        $q = CallTask::query()->whereHas('lead', fn ($l) => $this->visibility->apply($l, $user));
        if (! $user->hasPermission('followups.manage_all')) {
            $q->where('call_tasks.assigned_user_id', $user->id);
        }

        return $q;
    }

    private function task(CallTask $task): CallTask
    {
        abort_unless($this->scoped()->whereKey($task->id)->exists(), 404);

        return $task;
    }

    public function index(Request $request, CurrentOrganization $org)
    {
        abort_unless($this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all'), 403);
        $tz = $this->user()->timezone ?: $org->require()->timezone;
        $start = now($tz)->startOfDay()->utc();
        $end = now($tz)->endOfDay()->utc();
        $q = $this->scoped()->with(['lead.contact', 'lead.status', 'assignee', 'outcome']);

        $view = $request->input('view', 'today');
        match ($view) {
            'overdue' => $q->where('status', 'pending')->where('scheduled_at', '<', now())->orderBy('scheduled_at'),
            'today' => $q->where('status', 'pending')->whereBetween('scheduled_at', [$start, $end])->orderBy('scheduled_at'),
            'upcoming' => $q->where('status', 'pending')->where('scheduled_at', '>', $end)->orderBy('scheduled_at'),
            'missed' => $q->where('status', 'missed')->orderByDesc('scheduled_at'),
            'completed' => $q->where('status', 'completed')->orderByDesc('completed_at'),
            'cancelled' => $q->where('status', 'cancelled')->orderByDesc('updated_at'),
            default => abort(400, 'Unknown view.'),
        };
        if ($uid = $request->input('assigned_user_id')) {
            $q->where('call_tasks.assigned_user_id', (int) $uid);
        }
        if ($p = $request->input('priority')) {
            $q->whereIn('call_tasks.priority', (array) $p);
        }

        $base = $this->scoped();
        $counts = [
            'overdue' => (clone $base)->where('status', 'pending')->where('scheduled_at', '<', now())->count(),
            'today' => (clone $base)->where('status', 'pending')->whereBetween('scheduled_at', [$start, $end])->count(),
            'upcoming' => (clone $base)->where('status', 'pending')->where('scheduled_at', '>', $end)->count(),
            'missed' => (clone $base)->where('status', 'missed')->count(),
        ];

        return $this->paginated($q->paginate($this->perPage()), CallTaskResource::class, ['counts' => $counts, 'view' => $view]);
    }

    public function complete(LogCallRequest $request, CallTask $task, FollowUpService $followUps)
    {
        $this->task($task);

        return $this->ok(new CallTaskResource($followUps->logCall($task->lead->load('status', 'contact'), $task, $request->validated())->load(['assignee', 'outcome'])));
    }

    public function reschedule(Request $request, CallTask $task, FollowUpService $followUps)
    {
        abort_unless($this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all'), 403);
        $this->task($task);
        $request->merge(['scheduled_at' => LocalTime::toUtc($request->input('scheduled_at'))]);
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now']]);

        return $this->ok(new CallTaskResource($followUps->reschedule($task, $data['scheduled_at'])->load(['assignee', 'outcome'])));
    }

    public function cancel(Request $request, CallTask $task, FollowUpService $followUps)
    {
        abort_unless($this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all'), 403);
        $this->task($task);
        $data = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->ok(new CallTaskResource($followUps->cancel($task, $data['reason'])));
    }
}
