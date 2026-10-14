<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelephonyCall extends TenantModel
{
    public const OPEN = ['queued', 'initiated', 'ringing', 'in-progress'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'answered_at' => 'datetime', 'ended_at' => 'datetime', 'outcome_logged' => 'boolean', 'price' => 'decimal:5'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
