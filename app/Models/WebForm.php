<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebForm extends TenantModel
{
    protected function casts(): array
    {
        return ['allowed_origins' => 'array', 'tags' => 'array', 'is_active' => 'boolean', 'last_submission_at' => 'datetime'];
    }

    public static function generateKey(): string
    {
        do {
            $key = Str::random(40);
        } while (static::withoutGlobalScopes()->where('key', $key)->exists());

        return $key;
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(WebFormSubmission::class);
    }

    public function endpoint(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/forms/'.$this->key;
    }

    /** Origin allow-list check on the request's Origin (CORS/JS) or Referer (plain HTML post) header. */
    public function allowsOrigin(?string $origin, ?string $referer): bool
    {
        $allowed = array_values(array_filter(array_map(fn ($o) => strtolower(rtrim((string) $o, '/')), $this->allowed_origins ?? [])));
        if ($allowed === []) {
            return true;
        }
        foreach ([$origin, $referer] as $h) {
            if (! $h) {
                continue;
            }
            $p = parse_url($h);
            if (empty($p['scheme']) || empty($p['host'])) {
                continue;
            }
            $o = strtolower($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''));
            if (in_array($o, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /** The Origin value to echo in CORS headers, or null when the request is not allowed. */
    public function corsOrigin(?string $origin): ?string
    {
        if (! $origin) {
            return null;
        }
        if (! $this->allowed_origins) {
            return '*';
        }

        return $this->allowsOrigin($origin, null) ? rtrim($origin, '/') : null;
    }
}
