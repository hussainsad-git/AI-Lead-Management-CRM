<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['do_not_contact' => 'boolean'];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ConsentRecord::class)->orderByDesc('recorded_at')->orderByDesc('id');
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(SuppressionRecord::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'merged_into_id');
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->company_name ?: ($this->phone_e164 ?: ($this->whatsapp_e164 ?: ($this->email ?: 'Contact #'.$this->id))));
    }

    /** Active suppression for a channel ('all' always applies). */
    public function isSuppressed(string $channel): bool
    {
        return $this->do_not_contact || $this->suppressions()->whereNull('lifted_at')->whereIn('channel', ['all', $channel])->exists();
    }
}
