<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class RoleController extends ApiController
{
    public function index()
    {
        abort_unless($this->user()->hasAnyPermission('roles.manage', 'users.manage'), 403);

        return $this->ok([
            'roles' => Role::with('permissions:id,key')->withCount('users')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'users_count' => $r->users_count,
                'locked' => $r->key === 'super_admin', 'permissions' => $r->permissions->pluck('key'),
            ]),
            'permissions' => Permission::orderBy('group')->orderBy('key')->get(['key', 'group', 'description']),
        ]);
    }

    public function update(Request $request, Role $role, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('roles.manage'), 403);
        abort_if($role->key === 'super_admin', 422, 'The Super Administrator role always has every permission.');
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'exists:permissions,key']]);
        $before = $role->permissions()->pluck('key')->sort()->values()->all();
        if ($role->key === 'business_admin' && ! in_array('roles.manage', $data['permissions'], true)) {
            abort(422, 'Business Administrator must keep roles.manage to avoid locking the workspace.');
        }
        $role->permissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
        $after = $role->permissions()->pluck('key')->sort()->values()->all();
        $audit->log('role.permissions_changed', $role, ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]);

        return $this->ok(['permissions' => $after]);
    }
}
