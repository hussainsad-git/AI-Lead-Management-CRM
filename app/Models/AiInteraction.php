<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInteraction extends TenantModel
{
    protected function casts(): array
    {
        return ['retrieved_chunk_ids' => 'array', 'feedback_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
