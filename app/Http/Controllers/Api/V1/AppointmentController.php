<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Services\LeadVisibility;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AppointmentController extends ApiController
{
    public function __construct(private LeadVisibility $visibility) {}

    private function scoped()
    {
        $q = Appointment::query()->whereHas('lead', fn ($l) => $this->visibility->apply($l, $this->user()));
        if (! $this->user()->hasPermission('followups.manage_all')) {
            $q->where('appointments.assigned_user_id', $this->user()->id);
        }

        return $q;
    }

    public function index(Request $request, CurrentOrganization $org)
    {
        abort_unless($this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all'), 403);
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tz = $this->user()->timezone ?: $org->require()->timezone;
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], $tz);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], $tz)->addDay();
        abort_if($from->diffInDays($to) > 62, 400, 'Range too large.');

        $items = $this->scoped()->with(['lead.contact', 'assignee'])
            ->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc())
            ->when($request->input('assigned_user_id'), fn ($q, $u) => $q->where('appointments.assigned_user_id', (int) $u))
            ->orderBy('starts_at')->get();

        return $this->ok(AppointmentResource::collection($items));
    }

    public function update(Request $request, Appointment $appointment, AppointmentService $service)
    {
        abort_unless($this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all'), 403);
        abort_unless($this->scoped()->whereKey($appointment->id)->exists(), 404);
        $data = $request->validate(['status' => ['required', 'in:scheduled,completed,cancelled,no_show'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->ok(new AppointmentResource($service->updateStatus($appointment, $data['status'], $data['notes'] ?? null)->load('assignee')));
    }
}
