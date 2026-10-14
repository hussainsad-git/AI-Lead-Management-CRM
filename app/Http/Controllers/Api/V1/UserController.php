<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends ApiController
{
    public function index()
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);
        $users = User::query()->inCurrentOrganization()->with(['roles:id,key,name', 'team:id,name'])->orderBy('name')->get();
        $open = DB::table('leads')->where('organization_id', app(CurrentOrganization::class)->id())->whereNull('deleted_at')->whereNull('archived_at')
            ->whereNull('closed_at')->select('assigned_user_id', DB::raw('COUNT(*) as c'))->groupBy('assigned_user_id')->pluck('c', 'assigned_user_id');

        return $this->ok($users->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_active' => $u->is_active,
            'accepts_assignments' => $u->accepts_assignments, 'team' => $u->team?->only(['id', 'name']),
            'roles' => $u->roles->map->only(['id', 'key', 'name'])->values(), 'last_login_at' => $u->last_login_at?->toIso8601ZuluString(), 'two_factor' => (bool) $u->totp_confirmed_at,
            'open_leads' => (int) ($open[$u->id] ?? 0),
        ]));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);
        $data = $this->validated($request, null);
        $this->guardSuperAdmin($data['role_ids'], new User);
        $user = DB::transaction(function () use ($data) {
            $u = new User(collect($data)->only(['name', 'email', 'phone', 'team_id', 'accepts_assignments'])->all());
            $u->password = $data['password'];
            $u->organization_id = app(CurrentOrganization::class)->id();
            $u->save();
            $u->roles()->sync($data['role_ids']);

            return $u;
        });
        $audit->log('user.created', $user, [], ['email' => $user->email, 'roles' => $data['role_ids']]);

        return $this->ok(['id' => $user->id], [], 201);
    }

    /** Admin reset when a user lost their device; the user is signed out and must sign in with password only, then re-enrol. */
    public function resetTwoFactor(User $user, \App\Services\TwoFactorService $tf)
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);
        abort_unless($user->organization_id === app(CurrentOrganization::class)->id(), 404);
        $tf->disable($user, $this->user());
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return $this->ok(['reset' => true]);
    }

    public function update(Request $request, User $user, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);
        abort_unless($user->organization_id === app(CurrentOrganization::class)->id(), 404);
        $data = $this->validated($request, $user);
        if ($user->id === $this->user()->id && (($data['is_active'] ?? true) === false || isset($data['role_ids']))) {
            abort(422, 'You cannot deactivate yourself or change your own roles.');
        }
        $this->guardSuperAdmin($data['role_ids'] ?? null, $user);
        DB::transaction(function () use ($user, $data, $audit) {
            $before = ['roles' => $user->roles()->pluck('key')->all(), 'is_active' => $user->is_active];
            $user->fill(collect($data)->only(['name', 'email', 'phone', 'team_id', 'is_active', 'accepts_assignments'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            if (isset($data['role_ids'])) {
                $user->roles()->sync($data['role_ids']);
            }
            if (($data['is_active'] ?? true) === false || ! empty($data['password'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete(); // force re-login
            }
            $audit->log('user.updated', $user, ['before' => $before, 'after' => ['roles' => $user->roles()->pluck('key')->all(), 'is_active' => $user->is_active],
                'password_changed' => ! empty($data['password'])]);
        });

        return $this->ok(['id' => $user->id]);
    }

    private function validated(Request $request, ?User $user): array
    {
        $org = app(CurrentOrganization::class)->id();

        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'sometimes' : 'required', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => [$user ? 'nullable' : 'required', Password::min(10)->letters()->numbers()],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('organization_id', $org)],
            'is_active' => ['sometimes', 'boolean'],
            'accepts_assignments' => ['sometimes', 'boolean'],
            'role_ids' => [$user ? 'sometimes' : 'required', 'array', 'min:1'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('organization_id', $org)],
        ]);
    }

    /** Only super admins can grant or revoke the super admin role. */
    private function guardSuperAdmin(?array $roleIds, User $target): void
    {
        if ($roleIds === null) {
            return;
        }
        $superId = Role::where('key', 'super_admin')->value('id');
        $touches = in_array($superId, $roleIds) || ($target->exists && $target->roles()->where('key', 'super_admin')->exists());
        if ($touches && ! $this->user()->roles()->where('key', 'super_admin')->exists()) {
            abort(403, 'Only a Super Administrator can change Super Administrator access.');
        }
    }
}
