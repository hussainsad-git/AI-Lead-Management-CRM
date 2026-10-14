<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends TenantModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
            'deal_value' => 'decimal:2',
            'first_contacted_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'qualified_at' => 'datetime',
            'converted_at' => 'datetime',
            'closed_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
            'score_factors' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(LeadAttribution::class);
    }

    public function firstTouch(): HasOne
    {
        return $this->hasOne(LeadAttribution::class)->where('touch', 'first');
    }

    public function lastTouch(): HasOne
    {
        return $this->hasOne(LeadAttribution::class)->where('touch', 'last');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'lead_tag_assignments');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function telephonyCalls(): HasMany
    {
        return $this->hasMany(TelephonyCall::class);
    }

    public function callTasks(): HasMany
    {
        return $this->hasMany(CallTask::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(LeadStatusHistory::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isOpen(): bool
    {
        return $this->status?->category === 'open';
    }
}
