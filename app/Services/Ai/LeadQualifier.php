<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\Conversation;
use App\Services\ActivityLogger;
use App\Services\ContactMatcher;
use App\Services\LeadScoring;
use Throwable;

/**
 * Extracts qualification data the CUSTOMER actually stated in the conversation and fills
 * blank lead/contact fields. Never overwrites existing values and never infers what was not said.
 */
class LeadQualifier
{
    public function __construct(
        private ProviderRegistry $providers,
        private AssistantConfig $assistants,
        private ActivityLogger $activity,
        private LeadScoring $scoring,
    ) {}

    public function qualify(Conversation $c): ?array
    {
        $lead = $c->lead?->loadMissing('contact');
        $a = $this->assistants->get();
        $client = $this->providers->forAssistant($a);
        $model = $this->providers->modelFor($a);
        if (! $lead || ! $client || ! $model) {
            return null;
        }
        $said = $c->messages()->where('direction', 'in')->whereNotNull('body')->latest('occurred_at')->limit(20)->get()->reverse()
            ->pluck('body')->map(fn ($b) => '- '.mb_substr($b, 0, 600))->implode("\n");
        if ($said === '') {
            return null;
        }

        $system = 'You extract lead details for a CRM from WhatsApp messages written by a customer. '
            .'Only include a value if the customer explicitly stated it; otherwise use null. Do not guess, translate names, or infer from tone. '
            .'Respond with JSON: {"name": str|null, "email": str|null, "city": str|null, "product_interest": str|null, '
            .'"budget_amount": number|null, "budget_currency": "ISO-4217"|null, "timeline": str|null, "preferred_contact_time": str|null, '
            .'"intent": "high"|"medium"|"low"|"none", "language": "ISO code or roman_ur", "summary": "one sentence in English", "wants_no_contact": bool}';
        $inter = new AiInteraction(['conversation_id' => $c->id, 'lead_id' => $lead->id, 'kind' => 'qualify', 'provider' => $a->provider, 'model' => $model, 'input' => mb_substr($said, 0, 4000)]);
        try {
            $r = $client->chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => "Customer messages:\n".$said]], $model, 0.0, 300, json: true);
            $this->providers->track($a->provider);
        } catch (Throwable $e) {
            $this->providers->track($a->provider, $e);
            $inter->fill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return null;
        }
        $d = AiResponder::parseJson($r['content']);
        $inter->fill(['status' => $d ? 'ok' : 'error', 'outcome' => 'extracted', 'response' => mb_substr($r['content'], 0, 4000),
            'prompt_tokens' => $r['prompt_tokens'], 'completion_tokens' => $r['completion_tokens'], 'latency_ms' => $r['latency_ms']])->save();
        if (! $d) {
            return null;
        }

        $filled = [];
        $contact = $lead->contact;
        $str = fn ($v, $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        if (! $contact->name && ($v = $str($d['name'] ?? null, 160))) {
            $contact->name = $v;
            $filled['name'] = $v;
        }
        if (! $contact->email && ($v = ContactMatcher::normalizeEmail($d['email'] ?? null))) {
            $contact->email = $v;
            $contact->email_normalized = $v;
            $filled['email'] = $v;
        }
        if (! $contact->city && ($v = $str($d['city'] ?? null, 120))) {
            $contact->city = $v;
            $filled['city'] = $v;
        }
        $contact->isDirty() && $contact->save();

        if (! $lead->product_interest && ($v = $str($d['product_interest'] ?? null, 191))) {
            $lead->product_interest = $v;
            $filled['product_interest'] = $v;
        }
        if ($lead->budget_amount === null && is_numeric($d['budget_amount'] ?? null) && $d['budget_amount'] > 0) {
            $lead->budget_amount = round((float) $d['budget_amount'], 2);
            $lead->budget_currency = preg_match('/^[A-Z]{3}$/', (string) ($d['budget_currency'] ?? '')) ? $d['budget_currency'] : app(\App\Support\Tenancy\CurrentOrganization::class)->require()->currency;
            $filled['budget'] = $lead->budget_currency.' '.$lead->budget_amount;
        }
        if (! $lead->language && ($v = $str($d['language'] ?? null, 12))) {
            $lead->language = $v;
            $c->language ??= $v;
        }
        $extra = array_filter(['timeline' => $str($d['timeline'] ?? null, 120), 'best time' => $str($d['preferred_contact_time'] ?? null, 120)]);
        if ($v = $str($d['summary'] ?? null, 500)) {
            $lead->ai_summary = $v.($extra ? ' ('.collect($extra)->map(fn ($x, $k) => "$k: $x")->implode('; ').')' : '');
        }
        $lead->save();
        $c->isDirty() && $c->save();

        if ($filled) {
            $this->activity->forLead($lead, 'ai_qualified', null, ['filled' => $filled, 'intent' => $d['intent'] ?? null, 'interaction_id' => $inter->id], 'internal', null);
        }
        $this->scoring->score($lead->fresh(), ['ai_intent' => $d['intent'] ?? null]);

        return ['filled' => $filled, 'intent' => $d['intent'] ?? null, 'wants_no_contact' => (bool) ($d['wants_no_contact'] ?? false), 'summary' => $lead->ai_summary];
    }
}
