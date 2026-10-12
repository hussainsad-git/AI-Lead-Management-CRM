<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\DashboardMetrics;
use App\Support\DateRange;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request, DashboardMetrics $metrics, CurrentOrganization $org)
    {
        $data = $request->validate([
            'range' => ['nullable', 'in:'.implode(',', DateRange::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $range = DateRange::resolve($data['range'] ?? 'last_30_days', $org->require()->timezone, $data['from'] ?? null, $data['to'] ?? null);

        return $this->ok($metrics->build($this->user(), $range));
    }
}
