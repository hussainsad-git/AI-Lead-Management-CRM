<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendNotificationEmail implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $notificationId, public int $organizationId) {}

    public function handle(): void
    {
        $this->withinOrganization($this->organizationId, null, function () {
            // Claim first so a retry after a successful send can't email twice.
            $claimed = Notification::whereKey($this->notificationId)->whereNull('emailed_at')->update(['emailed_at' => now()]);
            if (! $claimed) {
                return;
            }
            $n = Notification::find($this->notificationId);
            $user = User::where('organization_id', $this->organizationId)->find($n->user_id);
            if (! NotificationService::wantsEmail($user, $n->type) || $n->read_at) {
                return;
            }
            try {
                Mail::to($user->email, $user->name)->send(new NotificationMail($n, $user->organization->name));
            } catch (\Throwable $e) {
                Notification::whereKey($n->id)->update(['emailed_at' => null]); // allow the retry
                throw $e;
            }
        });
    }
}
