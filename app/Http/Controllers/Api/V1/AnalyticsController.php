<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AnalyticsService;
use App\Services\AuditLogger;
use App\Services\LeadExportService;
use App\Support\DateRange;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;

class AnalyticsController extends ApiController
{
    private function input(Request $request, CurrentOrganization $org): array
    {
        abort_unless($this->user()->hasPermission('reports.view'), 403);
        $d = $request->validate([
            'range' => ['nullable', 'in:'.implode(',', DateRange::PRESETS)], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'source_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'], 'campaign_id' => ['nullable', 'integer'],
            'platform' => ['nullable', 'in:facebook,instagram,meta,whatsapp,google,web,other'],
        ]);

        return [DateRange::resolve($d['range'] ?? 'last_30_days', $org->require()->timezone, $d['from'] ?? null, $d['to'] ?? null),
            array_intersect_key($d, array_flip(['source_id', 'user_id', 'campaign_id', 'platform']))];
    }

    public function index(Request $request, AnalyticsService $svc, CurrentOrganization $org)
    {
        [$r, $f] = $this->input($request, $org);

        return $this->ok($svc->build($r, $f));
    }

    public function export(Request $request, AnalyticsService $svc, CurrentOrganization $org, AuditLogger $audit)
    {
        [$r, $f] = $this->input($request, $org);
        $section = $request->validate(['section' => ['required', 'in:sources,campaigns,salespeople']])['section'];
        $rows = $svc->{$section}($r, $f);
        $audit->log('reports.exported', null, [], ['section' => $section, 'range' => $r->toArray()]);
        $name = "leadflow-$section-".($r->localDates()[0] ?? 'all').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            if ($rows) {
                fputcsv($out, array_map(fn ($k) => ucwords(str_replace('_', ' ', $k)), array_keys($rows[0])), ',', '"', '');
            }
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => LeadExportService::neutralize(is_array($v) ? json_encode($v) : (string) $v), $row), ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
