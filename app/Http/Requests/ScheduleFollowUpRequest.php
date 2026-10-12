<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all');
    }

    protected function prepareForValidation(): void
    {
        foreach (['scheduled_at'] as $f) {
            if ($this->has($f)) {
                $this->merge([$f => \App\Support\LocalTime::toUtc($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $org = app(CurrentOrganization::class)->id();

        return [
            'scheduled_at' => ['required', 'date', 'after:-5 minutes'],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $org)->where('is_active', true)],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'purpose' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($this->filled('assigned_user_id') && (int) $this->input('assigned_user_id') !== $this->user()->id && ! $this->user()->hasPermission('followups.manage_all')) {
                $v->errors()->add('assigned_user_id', 'You can only schedule follow-ups for yourself.');
            }
        }];
    }
}
