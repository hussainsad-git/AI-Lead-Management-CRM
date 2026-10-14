<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebFormSubmission extends TenantModel
{
    use Prunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    /** Raw submissions are diagnostic only; the lead holds the durable copy. */
    public function prunable()
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDays((int) config('leadflow.web_form_submission_retention_days', 90)));
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(WebForm::class, 'web_form_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
