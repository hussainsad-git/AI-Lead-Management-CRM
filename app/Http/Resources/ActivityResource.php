<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Activity */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'visibility' => $this->visibility,
            'body' => $this->body,
            'meta' => $this->meta,
            'lead_id' => $this->lead_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'occurred_at' => $this->occurred_at?->toIso8601ZuluString(),
        ];
    }
}
