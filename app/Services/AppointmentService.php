<?php

namespace App\Services;

use App\Enums\StatusSemantic;
use App\Exceptions\DomainRuleException;
use App\Models\Appointment;
use App\Models\Lead;

class AppointmentService
{
    public function __construct(private ActivityLogger $activity, private LeadStatusService $statuses) {}

    public function create(Lead $lead, array $data): Appointment
    {
        if ($lead->contact->do_not_contact) {
            throw new DomainRuleException('This contact has opted out.', 'contact_suppressed');
        }
        $appt = Appointment::create([
            'lead_id' => $lead->id,
            'assigned_user_id' => $data['assigned_user_id'] ?? $lead->assigned_user_id ?? auth()->id(),
            'created_by' => auth()->id(),
            'type' => $data['type'] ?? 'meeting',
            'title' => $data['title'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->activity->forLead($lead, 'appointment_scheduled', $appt->title, [
            'appointment_id' => $appt->id, 'type' => $appt->type, 'starts_at' => $appt->starts_at->toIso8601ZuluString(),
        ]);
        $this->statuses->advance($lead->fresh(['status', 'contact']), StatusSemantic::Appointment, null, 'appointment');

        return $appt->refresh();
    }

    public function updateStatus(Appointment $appt, string $status, ?string $notes = null): Appointment
    {
        $appt->forceFill(['status' => $status, 'notes' => $notes ?? $appt->notes])->save();
        $this->activity->forLead($appt->lead, 'appointment_'.$status, $notes, ['appointment_id' => $appt->id]);

        return $appt;
    }
}
