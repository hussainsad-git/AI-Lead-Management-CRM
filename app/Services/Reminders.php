<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CallTask;

/** Built-in reminders (not rules): due callbacks and upcoming appointments. De-duplicated per item. */
class Reminders
{
    public function __construct(private NotificationService $notify) {}

    public function send(): int
    {
        $n = 0;
        CallTask::with('lead.contact')->where('status', 'pending')->whereNotNull('assigned_user_id')
            ->whereBetween('scheduled_at', [now(), now()->addMinutes(15)])->get()
            ->each(function (CallTask $t) use (&$n) {
                $this->notify->notify($t->assigned_user_id, 'callback_due', ($t->purpose === 'Requested callback' ? 'Callback' : 'Follow-up').' in '.max(1, (int) now()->diffInMinutes($t->scheduled_at)).' min: '.$t->lead->contact->displayName(),
                    $t->purpose, '/leads/'.$t->lead_id, 'remind:task:'.$t->id.':'.$t->scheduled_at->timestamp);
                $n++;
            });
        Appointment::with('lead.contact')->where('status', 'scheduled')->whereNotNull('assigned_user_id')
            ->whereBetween('starts_at', [now(), now()->addMinutes(60)])->get()
            ->each(function (Appointment $a) use (&$n) {
                $this->notify->notify($a->assigned_user_id, 'appointment_soon', 'Appointment at '.$a->starts_at->copy()->setTimezone(app(\App\Support\Tenancy\CurrentOrganization::class)->require()->timezone)->format('H:i').': '.$a->lead->contact->displayName(),
                    $a->title.($a->location ? ' · '.$a->location : ''), '/leads/'.$a->lead_id, 'remind:appt:'.$a->id.':'.$a->starts_at->timestamp);
                $n++;
            });

        return $n;
    }
}
