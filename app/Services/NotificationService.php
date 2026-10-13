<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

/** In-app notifications. The dedupe key makes webhook retries and job re-runs notify once. */
class NotificationService
{
    public function notify(int|User|null $user, string $type, string $title, ?string $body, ?string $url, string $dedupeKey): void
    {
        $id = $user instanceof User ? $user->id : $user;
        if (! $id) {
            return;
        }
        $orgId = app(\App\Support\Tenancy\CurrentOrganization::class)->id();
        Notification::query()->insertOrIgnore([
            'organization_id' => $orgId, 'user_id' => $id, 'type' => $type, 'title' => mb_substr($title, 0, 191),
            'body' => $body ? mb_substr($body, 0, 500) : null, 'url' => $url, 'dedupe_key' => mb_substr($dedupeKey, 0, 191),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Managers of the user's team, or org admins when there is no team manager. */
    public function escalationTargets(?int $userId): array
    {
        $user = $userId ? User::query()->inCurrentOrganization()->find($userId) : null;
        $manager = $user?->team?->manager_user_id;
        if ($manager) {
            return [$manager];
        }

        return User::query()->inCurrentOrganization()->where('is_active', true)
            ->whereHas('roles', fn ($r) => $r->whereIn('key', ['business_admin', 'super_admin', 'sales_manager']))->pluck('id')->all();
    }
}
