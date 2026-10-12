<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leads.update');
    }

    public function rules(): array
    {
        $org = app(CurrentOrganization::class)->id();

        return [
            'lead_source_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lead_sources', 'id')->where('organization_id', $org)],
            'priority' => ['sometimes', Rule::in(Priority::values())],
            'product_interest' => ['sometimes', 'nullable', 'string', 'max:191'],
            'budget_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'budget_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'deal_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'deal_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'source_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'contact' => ['sometimes', 'array'],
            'contact.type' => ['nullable', 'in:individual,business'],
            'contact.name' => ['nullable', 'string', 'max:160'],
            'contact.company_name' => ['nullable', 'string', 'max:160'],
            'contact.email' => ['nullable', 'string', 'max:191'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.whatsapp' => ['nullable', 'string', 'max:40'],
            'contact.phone_region' => ['nullable', 'string', 'size:2', 'alpha'],
            'contact.city' => ['nullable', 'string', 'max:120'],
            'contact.preferred_contact_method' => ['nullable', 'in:phone,whatsapp,email'],
        ];
    }
}
