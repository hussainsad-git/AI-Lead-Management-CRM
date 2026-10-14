<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('followups.manage_own', 'followups.manage_all');
    }

    protected function prepareForValidation(): void
    {
        foreach (['next_follow_up_at', 'callback_at'] as $f) {
            if ($this->has($f)) {
                $this->merge([$f => \App\Support\LocalTime::toUtc($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $org = app(CurrentOrganization::class)->id();

        return [
            'call_outcome_id' => ['required', 'integer', Rule::exists('call_outcomes', 'id')->where('organization_id', $org)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['nullable', 'numeric', 'min:0', 'max:600'],
            'next_action' => ['nullable', 'string', 'max:191'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
            'callback_at' => ['nullable', 'date', 'after:now'],
            'purpose' => ['nullable', 'string', 'max:191'],
            'telephony_call_id' => ['nullable', 'integer', Rule::exists('telephony_calls', 'id')->where('organization_id', $org)],
        ];
    }
}
