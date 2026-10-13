<?php

namespace App\Services\Ai;

use App\Models\AiAssistant;
use App\Support\Tenancy\CurrentOrganization;

class AssistantConfig
{
    public function __construct(private CurrentOrganization $org) {}

    /** The organization's assistant, created from its industry preset on first use. */
    public function get(): AiAssistant
    {
        return AiAssistant::first() ?? $this->createFromPreset($this->org->require()->industry ?: 'general');
    }

    public function createFromPreset(string $industry): AiAssistant
    {
        $p = config("ai.industries.$industry") ?? config('ai.industries.general');
        $business = $this->org->require()->name;

        return AiAssistant::create([
            'name' => $business.' Assistant',
            'industry' => array_key_exists($industry, config('ai.industries')) ? $industry : 'general',
            'provider' => 'openai',
            'persona' => str_replace('{business}', $business, $p['persona']),
            'instructions' => $p['instructions'],
            'greeting' => 'Assalam-o-Alaikum! Thanks for contacting '.$business.'. How can we help you today?',
            'fallback_message' => 'Thanks for your question. Let me connect you with our team so they can give you the exact details.',
            'handoff_message' => 'Thank you! A member of our team will reply to you shortly.',
            'mode' => 'suggest',
            'languages' => ['en', 'ur', 'roman_ur'],
            'default_language' => 'en',
            'handoff_keywords' => ['agent', 'human', 'representative', 'call me', 'complaint', 'insaan', 'banda'],
            'qualification_fields' => $p['fields'],
        ]);
    }

    public function applyPreset(AiAssistant $a, string $industry): AiAssistant
    {
        $p = config("ai.industries.$industry");
        $a->update([
            'industry' => $industry,
            'persona' => str_replace('{business}', $this->org->require()->name, $p['persona']),
            'instructions' => $p['instructions'],
            'qualification_fields' => $p['fields'],
        ]);

        return $a;
    }
}
