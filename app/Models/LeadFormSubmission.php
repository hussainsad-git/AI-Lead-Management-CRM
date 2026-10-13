<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFormSubmission extends TenantModel
{
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
