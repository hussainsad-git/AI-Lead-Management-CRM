<?php

namespace App\Services;

use App\Models\Permission;

class PermissionCatalog
{
    /**
     * Upserts the catalogue. Permissions that did not exist before are granted to every organization's
     * system role whose template includes them (admins' later customisations of existing keys are untouched).
     */
    public function sync(): array
    {
        $new = [];
        foreach (config('leadflow.permissions') as $key => [$group, $description]) {
            $p = Permission::updateOrCreate(['key' => $key], ['group' => $group, 'description' => $description]);
            if ($p->wasRecentlyCreated) {
                $new[$key] = $p->id;
            }
        }
        if ($new) {
            foreach (config('leadflow.roles') as $roleKey => [, , $perms]) {
                $grant = $perms === ['*'] ? $new : array_intersect_key($new, array_flip($perms));
                if (! $grant) {
                    continue;
                }
                $roleIds = \Illuminate\Support\Facades\DB::table('roles')->where('key', $roleKey)->where('is_system', true)->pluck('id');
                foreach ($roleIds as $rid) {
                    foreach ($grant as $pid) {
                        \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore(['role_id' => $rid, 'permission_id' => $pid]);
                    }
                }
            }
        }

        return array_keys($new);
    }
}
