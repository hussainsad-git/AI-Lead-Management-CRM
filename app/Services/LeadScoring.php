<?php

namespace App\Services;

use App\Enums\StatusSemantic;
use App\Models\Appointment;
use App\Models\CallTask;
use App\Models\Lead;
use App\Models\Message;

/**
 * Transparent, rule-based lead score (0–100) with the factors shown to users.
 * Uses only behaviour and stated requirements — never demographics or protected characteristics,
 * and an ad click alone never raises the score.
 */
class LeadScoring
{
    public const DEFAULT_WEIGHTS = [
        'recent_inbound' => 15, 'explicit_interest' => 25, 'medium_interest' => 10, 'budget_stated' => 15,
        'callback_requested' => 15, 'appointment_booked' => 20, 'repeated_engagement' => 10,
        'stale_14d' => -15, 'stale_30d' => -25, 'unanswered_3' => -10, 'unanswered_5' => -20,
    ];

    public const THRESHOLDS = ['hot' => 60, 'warm' => 30];

    public function __construct(private OrganizationSettings $settings) {}

    public function weights(): array
    {
        return array_merge(self::DEFAULT_WEIGHTS, array_map('intval', (array) ($this->settings->get('scoring_weights') ?? [])));
    }

    /** Intent from the most recent AI qualification of this lead (what the customer said, not guessed). */
    private function lastAiIntent(Lead $lead): ?string
    {
        $resp = \App\Models\AiInteraction::where('lead_id', $lead->id)->where('kind', 'qualify')->where('status', 'ok')->latest('id')->value('response');
        $d = $resp ? json_decode($resp, true) : null;

        return is_array($d) && in_array($d['intent'] ?? null, ['high', 'medium', 'low', 'none'], true) ? $d['intent'] : null;
    }

    public function score(Lead $lead, array $hints = []): Lead
    {
        $lead->loadMissing('status', 'contact');
        if ($lead->status->category !== 'open' || $lead->contact->do_not_contact) {
            $lead->forceFill(['score' => null, 'score_label' => null, 'score_factors' => null, 'scored_at' => now()])->saveQuietly();

            return $lead;
        }
        $w = $this->weights();
        $f = [];
        $add = function (string $key, string $label) use (&$f, $w) {
            if (($w[$key] ?? 0) !== 0) {
                $f[] = ['key' => $key, 'label' => $label, 'points' => $w[$key]];
            }
        };

        $convIds = $lead->conversations()->pluck('id');
        $inbound = $convIds->isEmpty() ? 0 : Message::whereIn('conversation_id', $convIds)->where('direction', 'in')->count();
        $lastInbound = $convIds->isEmpty() ? null : Message::whereIn('conversation_id', $convIds)->where('direction', 'in')->max('occurred_at');

        if ($lastInbound && now()->diffInHours($lastInbound, true) <= 48) {
            $add('recent_inbound', 'Messaged in the last 48 hours');
        }
        $sem = $lead->status->semantic;
        $intent = $hints['ai_intent'] ?? $this->lastAiIntent($lead);
        if ($sem->rank() >= StatusSemantic::Interested->rank() && $sem !== StatusSemantic::Custom || $intent === 'high') {
            $add('explicit_interest', $intent === 'high' && $sem->rank() < StatusSemantic::Interested->rank() ? 'Clearly asked to buy / book (from conversation)' : 'Marked interested or further');
        } elseif ($intent === 'medium') {
            $add('medium_interest', 'Showed some interest in conversation');
        }
        if ($lead->budget_amount) {
            $add('budget_stated', 'Budget stated');
        }
        if (CallTask::where('lead_id', $lead->id)->where('status', 'pending')->where('purpose', 'Requested callback')->exists()) {
            $add('callback_requested', 'Requested a callback');
        }
        if (Appointment::where('lead_id', $lead->id)->where('status', 'scheduled')->where('starts_at', '>', now())->exists()) {
            $add('appointment_booked', 'Upcoming appointment');
        }
        if ($inbound >= 3) {
            $add('repeated_engagement', "Repeated engagement ($inbound messages)");
        }
        $lastMeaningful = collect([$lastInbound, $lead->first_responded_at && $lead->last_contacted_at ? $lead->last_contacted_at : null, $lead->created_at])->filter()->max();
        $days = $lastMeaningful ? now()->diffInDays($lastMeaningful, true) : 0;
        if ($days > 30) {
            $add('stale_30d', 'No meaningful interaction for '.(int) $days.' days');
        } elseif ($days > 14) {
            $add('stale_14d', 'No meaningful interaction for '.(int) $days.' days');
        }
        if ($lead->unanswered_attempts >= 5) {
            $add('unanswered_5', $lead->unanswered_attempts.' unanswered calls in a row');
        } elseif ($lead->unanswered_attempts >= 3) {
            $add('unanswered_3', $lead->unanswered_attempts.' unanswered calls in a row');
        }

        $hasSignal = $inbound > 0 || $lead->contact_attempts > 0 || $lead->first_responded_at || $lead->budget_amount;
        $score = max(0, min(100, 20 + array_sum(array_column($f, 'points'))));
        $label = ! $hasSignal ? 'needs_review' : ($score >= self::THRESHOLDS['hot'] ? 'hot' : ($score >= self::THRESHOLDS['warm'] ? 'warm' : 'cold'));
        array_unshift($f, ['key' => 'base', 'label' => 'Base score', 'points' => 20]);

        $lead->forceFill(['score' => $score, 'score_label' => $label, 'score_factors' => $f, 'scored_at' => now()])->saveQuietly();

        return $lead;
    }
}
