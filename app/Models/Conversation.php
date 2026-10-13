<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends TenantModel
{
    protected function casts(): array
    {
        return [
            'handoff_at' => 'datetime', 'last_inbound_at' => 'datetime', 'last_message_at' => 'datetime', 'first_response_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** WhatsApp allows free-form replies only within 24h of the customer's last message. */
    public function windowOpen(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }

    public function windowClosesAt(): ?\Carbon\CarbonInterface
    {
        return $this->last_inbound_at?->copy()->addHours(24);
    }
}
