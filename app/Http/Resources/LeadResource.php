<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Lead */
class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $touch = $this->relationLoaded('firstTouch') ? $this->firstTouch : null;

        return [
            'id' => $this->id,
            'contact' => $this->whenLoaded('contact', fn () => new ContactResource($this->contact)),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id, 'name' => $this->status->name, 'color' => $this->status->color,
                'category' => $this->status->category, 'semantic' => $this->status->semantic->value,
            ]),
            'source' => $this->whenLoaded('source', fn () => $this->source ? ['id' => $this->source->id, 'name' => $this->source->name, 'platform' => $this->source->platform] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()),
            'attribution' => $touch ? [
                'platform' => $touch->platform, 'campaign_id' => $touch->campaign_id, 'campaign' => $touch->campaign_name_snapshot,
                'ad_set' => $touch->ad_set_name_snapshot, 'ad' => $touch->ad_name_snapshot, 'completeness' => $touch->completeness,
                'captured_via' => $touch->captured_via,
            ] : null,
            'priority' => $this->priority,
            'score' => $this->score,
            'score_label' => $this->score_label,
            'score_factors' => $this->score_factors,
            'ai_summary' => $this->ai_summary,
            'language' => $this->language,
            'product_interest' => $this->product_interest,
            'budget_amount' => $this->budget_amount,
            'budget_currency' => $this->budget_currency,
            'deal_value' => $this->deal_value,
            'deal_currency' => $this->deal_currency,
            'location' => $this->location,
            'source_notes' => $this->source_notes,
            'lost_reason' => $this->lost_reason,
            'external_lead_id' => $this->external_lead_id,
            'contact_attempts' => $this->contact_attempts,
            'unanswered_attempts' => $this->unanswered_attempts,
            'first_contacted_at' => $this->first_contacted_at?->toIso8601ZuluString(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601ZuluString(),
            'first_responded_at' => $this->first_responded_at?->toIso8601ZuluString(),
            'qualified_at' => $this->qualified_at?->toIso8601ZuluString(),
            'converted_at' => $this->converted_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601ZuluString(),
            'last_activity_at' => $this->last_activity_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
