<?php

namespace App\Services\Import;

/** CRM fields available for column mapping, with header synonyms for auto-suggestion. */
final class ImportFields
{
    public const FIELDS = [
        'contact.name' => ['Contact name', ['name', 'full name', 'customer', 'customer name', 'client', 'client name', 'lead name', 'contact']],
        'contact.company_name' => ['Company', ['company', 'business', 'business name', 'organization', 'organisation']],
        'contact.phone' => ['Phone', ['phone', 'phone number', 'mobile', 'mobile number', 'cell', 'contact number', 'number', 'tel', 'telephone']],
        'contact.whatsapp' => ['WhatsApp number', ['whatsapp', 'whatsapp number', 'wa', 'wa number', 'whats app']],
        'contact.email' => ['Email', ['email', 'e-mail', 'email address', 'mail']],
        'contact.city' => ['City', ['city', 'town']],
        'lead.source' => ['Lead source', ['source', 'lead source', 'channel']],
        'lead.status' => ['Status', ['status', 'stage', 'lead status']],
        'lead.assigned_to' => ['Assigned to (email or name)', ['assigned to', 'owner', 'salesperson', 'sales person', 'agent', 'rep', 'assigned']],
        'lead.priority' => ['Priority', ['priority']],
        'lead.product_interest' => ['Interested product/service', ['product', 'service', 'interest', 'interested in', 'project', 'requirement']],
        'lead.budget_amount' => ['Budget', ['budget', 'budget amount']],
        'lead.location' => ['Location', ['location', 'area', 'address']],
        'lead.note' => ['Notes', ['notes', 'note', 'comments', 'comment', 'remarks', 'remark', 'feedback']],
        'lead.created_at' => ['Lead date', ['date', 'lead date', 'created', 'created at', 'inquiry date', 'enquiry date']],
        'lead.tags' => ['Tags (comma separated)', ['tags', 'tag', 'labels']],
        'lead.external_lead_id' => ['External lead ID', ['lead id', 'external id', 'leadgen id', 'meta lead id']],
        'lead.next_follow_up_at' => ['Next follow-up date', ['follow up', 'follow-up', 'next follow up', 'callback', 'callback date', 'next call']],
        'call.last_outcome' => ['Last call outcome', ['call status', 'call outcome', 'call result', 'response', 'answered']],
        'call.last_called_at' => ['Last call date', ['last call', 'call date', 'called on', 'last contacted']],
        'attribution.platform' => ['Ad platform', ['platform']],
        'attribution.campaign_name' => ['Campaign name', ['campaign', 'campaign name']],
        'attribution.ad_set_name' => ['Ad set name', ['ad set', 'adset', 'ad set name', 'adset name']],
        'attribution.ad_name' => ['Ad name', ['ad', 'ad name', 'advert', 'creative']],
        'attribution.external_campaign_id' => ['Campaign ID', ['campaign id']],
        'attribution.external_ad_set_id' => ['Ad set ID', ['ad set id', 'adset id']],
        'attribution.external_ad_id' => ['Ad ID', ['ad id']],
        'consent.whatsapp' => ['WhatsApp consent (yes/no)', ['whatsapp consent', 'wa consent', 'consent', 'opt in', 'opt-in']],
    ];

    public static function suggest(array $headers): array
    {
        $map = [];
        $used = [];
        foreach ($headers as $i => $h) {
            $norm = trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($h)));
            foreach (self::FIELDS as $field => [, $syn]) {
                if (! isset($used[$field]) && in_array($norm, $syn, true)) {
                    $map[(string) $i] = $field;
                    $used[$field] = true;
                    break;
                }
            }
        }

        return $map;
    }

    public static function forUi(): array
    {
        return collect(self::FIELDS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0]])->values()->all();
    }
}
