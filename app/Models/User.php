<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Users are not globally scoped (authentication must resolve them before the
 * organization is known). Every user query in application code goes through
 * scopeInCurrentOrganization().
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'is_active', 'accepts_assignments', 'timezone', 'locale', 'team_id'];

    protected $hidden = ['password', 'remember_token'];

    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'accepts_assignments' => 'boolean',
            'is_platform_admin' => 'boolean',
            'notification_prefs' => 'array',
        ];
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value !== null ? mb_strtolower(trim($value)) : null;
    }

    public function scopeInCurrentOrganization(Builder $q): Builder
    {
        return $q->where($this->qualifyColumn('organization_id'), app(\App\Support\Tenancy\CurrentOrganization::class)->id() ?? 0);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function managedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'manager_user_id');
    }

    public function permissionKeys(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }
        if ($this->roles()->where('key', 'super_admin')->exists()) {
            return $this->permissionCache = Permission::query()->pluck('key')->all();
        }

        return $this->permissionCache = Permission::query()
            ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->where('user_roles.user_id', $this->id)
            ->distinct()->pluck('permissions.key')->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function hasAnyPermission(string ...$keys): bool
    {
        return (bool) array_intersect($keys, $this->permissionKeys());
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    /** IDs of users whose leads this user may see under leads.view_team. */
    public function teamMemberIds(): array
    {
        $teamIds = $this->managedTeams()->pluck('id');

        return User::query()->where('organization_id', $this->organization_id)
            ->whereIn('team_id', $teamIds)->pluck('id')->push($this->id)->unique()->values()->all();
    }
}
