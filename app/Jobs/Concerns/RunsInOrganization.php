<?php

namespace App\Jobs\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Auth;

/** Queued jobs carry only ids; tenancy and acting user are re-established server-side. */
trait RunsInOrganization
{
    protected function withinOrganization(int $organizationId, ?int $userId, callable $callback): mixed
    {
        $org = Organization::findOrFail($organizationId);
        $user = $userId ? User::where('organization_id', $organizationId)->find($userId) : null;
        $previousUser = Auth::user();
        if ($user) {
            Auth::setUser($user);
        }
        try {
            return app(CurrentOrganization::class)->runAs($org, fn () => $callback($user));
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }
    }
}
