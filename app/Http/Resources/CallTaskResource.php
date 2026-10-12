<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CallTask */
class CallTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lead = $this->relationLoaded('lead') ? $this->lead : null;

        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'lead' => $lead ? [
                'id' => $lead->id, 'priority' => $lead->priority,
                'contact' => $lead->relationLoaded('contact') ? [
                    'name' => $lead->contact->displayName(), 'phone' => $lead->contact->phone_e164,
                    'whatsapp' => $lead->contact->whatsapp_e164, 'do_not_contact' => $lead->contact->do_not_contact,
                ] : null,
                'status' => $lead->relationLoaded('status') ? ['name' => $lead->status->name, 'color' => $lead->status->color] : null,
            ] : null,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'scheduled_at' => $this->scheduled_at?->toIso8601ZuluString(),
            'priority' => $this->priority,
            'purpose' => $this->purpose,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'is_overdue' => $this->status === 'pending' && $this->scheduled_at?->isPast(),
            'outcome' => $this->whenLoaded('outcome', fn () => $this->outcome ? ['id' => $this->outcome->id, 'name' => $this->outcome->name, 'key' => $this->outcome->key] : null),
            'duration_seconds' => $this->duration_seconds,
            'notes' => $this->notes,
            'next_action' => $this->next_action,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
