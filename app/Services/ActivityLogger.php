<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;

/** Writes timeline events. Internal notes are 'internal'; customer-facing messages are 'customer'. */
class ActivityLogger
{
    public function forLead(Lead $lead, string $type, ?string $body = null, array $meta = [], string $visibility = 'internal', ?int $userId = null): Activity
    {
        $activity = Activity::create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'user_id' => $userId ?? auth()->id(),
            'type' => $type,
            'visibility' => $visibility,
            'body' => $body,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
        $lead->forceFill(['last_activity_at' => $activity->occurred_at])->saveQuietly();

        return $activity;
    }

    public function forContact(int $contactId, string $type, ?string $body = null, array $meta = []): Activity
    {
        return Activity::create([
            'contact_id' => $contactId,
            'user_id' => auth()->id(),
            'type' => $type,
            'visibility' => 'internal',
            'body' => $body,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }
}
