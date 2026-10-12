<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ImportJob;
use App\Services\Import\ImportFields;
use App\Services\Import\ImportService;
use App\Services\LeadExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends ApiController
{
    public function __construct(private ImportService $imports)
    {
    }

    private function job(ImportJob $job): ImportJob
    {
        abort_unless($this->user()->hasPermission('imports.run'), 403);
        // Users see their own imports; admins see all.
        abort_unless($job->user_id === $this->user()->id || $this->user()->hasPermission('settings.manage'), 404);

        return $job;
    }

    public function index()
    {
        abort_unless($this->user()->hasPermission('imports.run'), 403);
        $q = ImportJob::with('user:id,name')->latest('id')->limit(50);
        if (! $this->user()->hasPermission('settings.manage')) {
            $q->where('user_id', $this->user()->id);
        }

        return $this->ok($q->get()->map(fn ($j) => $this->present($j)));
    }

    public function store(Request $request)
    {
        abort_unless($this->user()->hasPermission('imports.run'), 403);
        $request->validate(['file' => ['required', 'file', 'max:'.config('leadflow.import.max_upload_kb'), 'mimes:csv,txt,xlsx']]);
        $r = $this->imports->upload($request->file('file'), $this->user());

        return $this->ok($this->present($r['job']) + ['sheets' => $r['sheets'], 'sample' => $r['sample'], 'fields' => ImportFields::forUi()], [], 201);
    }

    public function show(ImportJob $import)
    {
        $this->job($import);

        $editable = in_array($import->status, ['uploaded', 'validated'], true);

        return $this->ok($this->present($import) + [
            'summary' => $this->imports->summary($import), 'fields' => ImportFields::forUi(),
            'sample' => $editable ? $this->imports->sample($import) : [], 'sheets' => $editable ? $this->imports->sheets($import) : [],
        ]);
    }

    public function sheet(Request $request, ImportJob $import)
    {
        $this->job($import);
        $data = $request->validate(['sheet' => ['required', 'string', 'max:120']]);
        $r = $this->imports->selectSheet($import, $data['sheet']);

        return $this->ok($this->present($r['job']) + ['sample' => $r['sample']]);
    }

    public function configure(Request $request, ImportJob $import)
    {
        $this->job($import);
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'options' => ['nullable', 'array'],
            'options.duplicate_strategy' => ['nullable', 'in:'.implode(',', ImportService::DUPLICATE_STRATEGIES)],
            'options.default_region' => ['nullable', 'in:'.implode(',', config('leadflow.phone_regions'))],
            'options.default_source_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('lead_sources', 'id')->where('organization_id', $import->organization_id)],
            'options.default_assignee_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('organization_id', $import->organization_id)->where('is_active', true)],
            'options.date_order' => ['nullable', 'in:dmy,mdy'],
        ]);
        if (! empty($data['options']['default_assignee_id']) && ! $this->user()->hasPermission('leads.assign') && (int) $data['options']['default_assignee_id'] !== $this->user()->id) {
            abort(403, 'You can only assign imported leads to yourself.');
        }
        $job = $this->imports->configure($import, $data['mapping'], array_filter($data['options'] ?? [], fn ($v) => $v !== null));

        return $this->ok($this->present($job) + ['summary' => $this->imports->summary($job)]);
    }

    public function rows(Request $request, ImportJob $import)
    {
        $this->job($import);
        $q = $import->rows()->orderBy('row_number');
        if ($a = $request->input('action')) {
            $q->whereIn('action', (array) $a);
        }
        if ($r = $request->input('result')) {
            $q->where('result', $r);
        }
        $page = $q->paginate($this->perPage());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => [
                'id' => $row->id, 'row_number' => $row->row_number, 'action' => $row->action, 'result' => $row->result,
                'messages' => $row->messages ?? [], 'raw' => $row->raw, 'matched_contact_id' => $row->matched_contact_id, 'lead_id' => $row->lead_id,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function resolve(Request $request, ImportJob $import)
    {
        $this->job($import);
        $data = $request->validate(['action' => ['required', 'in:skip,new_lead,update,create'], 'row_ids' => ['array'], 'row_ids.*' => ['integer'], 'all_review' => ['boolean']]);
        $n = $this->imports->resolve($import, $data['row_ids'] ?? [], (bool) ($data['all_review'] ?? false), $data['action']);

        return $this->ok(['updated' => $n, 'summary' => $this->imports->summary($import->fresh())]);
    }

    public function commit(ImportJob $import)
    {
        $this->job($import);

        return $this->ok($this->present($this->imports->commit($import)->fresh()));
    }

    /** Downloadable report of every row that was not imported, with reasons and original values. */
    public function errorReport(ImportJob $import)
    {
        $this->job($import);
        $headers = $import->headers ?? [];

        return response()->streamDownload(function () use ($import, $headers) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['Row', 'Action', 'Result', 'Messages'], $headers), ',', '"', '');
            $import->rows()->where(fn ($q) => $q->whereIn('action', ['error', 'review', 'skip'])->orWhere('result', 'failed'))
                ->orderBy('row_number')->chunk(500, function ($rows) use ($out, $headers) {
                    foreach ($rows as $row) {
                        $line = array_merge([$row->row_number, $row->action, $row->result ?? '', implode(' | ', $row->messages ?? [])],
                            array_map(fn ($h) => (string) ($row->raw[$h] ?? ''), $headers));
                        fputcsv($out, array_map([LeadExportService::class, 'neutralize'], array_map('strval', $line)), ',', '"', '');
                    }
                });
            fclose($out);
        }, 'import-'.$import->id.'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function present(ImportJob $j): array
    {
        return [
            'id' => $j->id, 'file' => $j->original_filename, 'type' => $j->file_type, 'sheet' => $j->sheet_name,
            'status' => $j->status, 'headers' => $j->headers, 'mapping' => $j->mapping, 'options' => $j->options,
            'total_rows' => $j->total_rows, 'created' => $j->created_count, 'updated' => $j->updated_count,
            'skipped' => $j->skipped_count, 'review' => $j->review_count, 'errors' => $j->error_count,
            'failure_message' => $j->failure_message, 'user' => $j->user?->name,
            'created_at' => $j->created_at?->toIso8601ZuluString(), 'finished_at' => $j->finished_at?->toIso8601ZuluString(),
        ];
    }
}
