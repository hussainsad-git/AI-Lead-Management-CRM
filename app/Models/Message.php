<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends TenantModel
{
    protected function casts(): array
    {
        return [
            'media' => 'array', 'meta' => 'array', 'occurred_at' => 'datetime', 'sent_at' => 'datetime',
            'delivered_at' => 'datetime', 'read_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class, 'ai_interaction_id');
    }
}
