<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends TenantModel
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} token record and the one-time plaintext */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'lf_'.Str::random(48);
        $t = new self(['name' => $name, 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 12), 'expires_at' => $expiresAt]);
        $t->user_id = $user->id;
        $t->organization_id = $user->organization_id;
        $t->save();

        return [$t, $plain];
    }

    public static function findValid(string $plain): ?self
    {
        $t = self::withoutGlobalScopes()->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
        if (! $t || ($t->expires_at && $t->expires_at->isPast())) {
            return null;
        }

        return $t;
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
