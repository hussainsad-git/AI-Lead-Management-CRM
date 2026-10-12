<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leads.create');
    }

    public function rules(): array
    {
        $org = app(CurrentOrganization::class)->id();
        $inOrg = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $org);

        return [
            'contact_id' => ['nullable', 'integer', $inOrg('contacts')],
            'contact_strategy' => ['nullable', 'in:link,new'],
            'contact' => ['required_without:contact_id', 'array'],
            'contact.type' => ['nullable', 'in:individual,business'],
            'contact.name' => ['nullable', 'string', 'max:160'],
            'contact.company_name' => ['nullable', 'string', 'max:160'],
            'contact.email' => ['nullable', 'string', 'max:191'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.whatsapp' => ['nullable', 'string', 'max:40'],
            'contact.phone_region' => ['nullable', 'string', 'size:2', 'alpha'],
            'contact.city' => ['nullable', 'string', 'max:120'],
            'contact.preferred_contact_method' => ['nullable', 'in:phone,whatsapp,email'],
            'lead_source_id' => ['required', 'integer', $inOrg('lead_sources')],
            'lead_status_id' => ['nullable', 'integer', $inOrg('lead_statuses')],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $org)->where('is_active', true)],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'product_interest' => ['nullable', 'string', 'max:191'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'budget_currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'location' => ['nullable', 'string', 'max:160'],
            'source_notes' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'attribution' => ['nullable', 'array'],
            'attribution.platform' => ['nullable', 'in:facebook,instagram,meta,whatsapp,google,web,other'],
            'attribution.campaign_id' => ['nullable', 'integer', $inOrg('campaigns')],
            'attribution.source' => ['nullable', 'string', 'max:80'],
            'attribution.medium' => ['nullable', 'string', 'max:80'],
            'attribution.ad_set_name' => ['nullable', 'string', 'max:191'],
            'attribution.ad_name' => ['nullable', 'string', 'max:191'],
            'consents' => ['nullable', 'array'],
            'consents.whatsapp' => ['nullable', 'boolean'],
            'consents.phone' => ['nullable', 'boolean'],
            'consents.email' => ['nullable', 'boolean'],
            'consent_evidence' => ['nullable', 'string', 'max:500'],
            'allow_duplicate_open_lead' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($this->input('contact_strategy') === 'new' && ! $this->user()->hasPermission('contacts.merge')) {
                $v->errors()->add('contact_strategy', 'Only users who can merge contacts may create a deliberate duplicate contact.');
            }
            if ($this->filled('assigned_user_id') && (int) $this->input('assigned_user_id') !== $this->user()->id && ! $this->user()->hasPermission('leads.assign')) {
                $v->errors()->add('assigned_user_id', 'You can only assign new leads to yourself.');
            }
        }];
    }
}
