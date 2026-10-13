<?php

namespace App\Models;


/**
 * credentials: encrypted JSON (APP_KEY). Never serialized: $hidden + every API response is built explicitly.
 */
class Integration extends TenantModel
{
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'verified_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function secret(string $key): ?string
    {
        $v = ($this->credentials ?? [])[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    /** Masked hint for the UI, e.g. "••••3f9a". */
    public function secretHint(string $key): ?string
    {
        $v = $this->secret($key);

        return $v ? '••••'.substr($v, -4) : null;
    }

    public function markSuccess(): void
    {
        $this->forceFill(['status' => 'connected', 'last_success_at' => now(), 'verified_at' => $this->verified_at ?? now()])->saveQuietly();
    }

    public function markError(string $message): void
    {
        $this->forceFill(['status' => 'error', 'last_error' => mb_substr($message, 0, 500), 'last_error_at' => now()])->saveQuietly();
    }
}
