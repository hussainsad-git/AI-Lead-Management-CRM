<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\LeadFilter;
use App\Services\LeadVisibility;
use Illuminate\Http\Request;

class PipelineController extends ApiController
{
    public function __invoke(Request $request, LeadVisibility $visibility, LeadFilter $filter)
    {
        $limit = min((int) $request->input('per_column', 40), 100);
        $statuses = LeadStatus::where('is_active', true)->orderBy('sort_order')->get();
        $includeClosed = $request->boolean('include_closed');
        $base = function () use ($visibility, $filter, $request) {
            $q = $visibility->apply(Lead::query(), $this->user());

            return $filter->apply($q, $request->except(['status_ids', 'status_category']));
        };
        $counts = $base()->selectRaw('lead_status_id, COUNT(*) as c')->groupBy('lead_status_id')->pluck('c', 'lead_status_id');

        $columns = $statuses->map(function ($s) use ($base, $limit, $counts, $includeClosed) {
            $collapsed = $s->category !== 'open' && ! $includeClosed;
            $cards = $collapsed ? collect() : $base()->where('lead_status_id', $s->id)
                ->with(['contact', 'assignee', 'source', 'firstTouch'])
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
                ->orderByDesc('last_activity_at')->limit($limit)->get();

            return [
                'status' => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'category' => $s->category, 'requires_reason' => $s->requires_reason, 'semantic' => $s->semantic->value],
                'count' => (int) ($counts[$s->id] ?? 0),
                'collapsed' => $collapsed,
                'cards' => LeadResource::collection($cards)->resolve(),
            ];
        });

        return $this->ok($columns);
    }
}
