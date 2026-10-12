<?php

namespace App\Services\Import;

use App\Exceptions\DomainRuleException;
use App\Jobs\CommitImport;
use App\Jobs\StageImport;
use App\Models\CallOutcome;
use App\Models\CallTask;
use App\Models\Contact;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\ContactMatcher;
use App\Services\FollowUpService;
use App\Services\LeadService;
use App\Services\LeadStatusService;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Upload → preview → map → stage (dry run with per-row action) → resolve reviews → commit.
 * Every row ends up with a visible action/result; invalid rows are reported, never dropped.
 */
class ImportService
{
    public const DUPLICATE_STRATEGIES = ['review', 'skip', 'new_lead', 'update_contact'];

    public const ROW_ACTIONS = ['create', 'new_lead', 'update', 'skip', 'review', 'error'];

    public function __construct(
        private ImportFileReader $reader,
        private LeadService $leads,
        private LeadStatusService $statuses,
        private FollowUpService $followUps,
        private ContactMatcher $matcher,
        private ActivityLogger $activity,
        private AuditLogger $audit,
        private CurrentOrganization $org,
    ) {}

    public function upload(UploadedFile $file, User $user): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $type = match (true) {
            $ext === 'xlsx' => 'xlsx',
            in_array($ext, ['csv', 'txt'], true) => 'csv',
            default => throw new DomainRuleException('Only .csv and .xlsx files are supported.', 'unsupported_file'),
        };
        $orgId = $this->org->require()->id;
        $path = $file->storeAs("imports/{$orgId}", Str::uuid().'.'.$type, 'local');
        $abs = Storage::disk('local')->path($path);

        try {
            $sheets = $this->reader->sheets($abs, $type);
            $sheet = $sheets[0] ?? null;
            $preview = $this->reader->preview($abs, $type, $sheet);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw new DomainRuleException('The file could not be read: '.Str::limit($e->getMessage(), 160), 'unreadable_file');
        }
        $max = config('leadflow.import.max_rows');
        if ($preview['total_rows'] > $max) {
            Storage::disk('local')->delete($path);
            throw new DomainRuleException("The file has {$preview['total_rows']} rows; the limit is {$max}. Split it into smaller files.", 'too_many_rows');
        }

        $job = ImportJob::create([
            'user_id' => $user->id, 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 191),
            'stored_path' => $path, 'file_type' => $type, 'sheet_name' => $sheet,
            'headers' => $preview['headers'], 'total_rows' => $preview['total_rows'],
            'mapping' => ImportFields::suggest($preview['headers']),
            'options' => $this->defaultOptions(),
        ]);
        $job->refresh(); // load DB defaults (status, counters)
        $this->audit->log('import.uploaded', $job, [], ['file' => $job->original_filename, 'rows' => $job->total_rows]);

        return ['job' => $job, 'sheets' => $sheets, 'sample' => $preview['sample']];
    }

    public function selectSheet(ImportJob $job, string $sheet): array
    {
        $this->assertStatus($job, ['uploaded', 'validated']);
        $abs = Storage::disk('local')->path($job->stored_path);
        if (! in_array($sheet, $this->reader->sheets($abs, $job->file_type), true)) {
            throw new DomainRuleException('Worksheet not found.', 'sheet_not_found');
        }
        $preview = $this->reader->preview($abs, $job->file_type, $sheet);
        $job->update(['sheet_name' => $sheet, 'headers' => $preview['headers'], 'total_rows' => $preview['total_rows'],
            'mapping' => ImportFields::suggest($preview['headers']), 'status' => 'uploaded']);
        $job->rows()->delete();

        return ['job' => $job, 'sample' => $preview['sample']];
    }

    public function sample(ImportJob $job): array
    {
        try {
            return $this->reader->preview(Storage::disk('local')->path($job->stored_path), $job->file_type, $job->sheet_name)['sample'];
        } catch (Throwable) {
            return [];
        }
    }

    public function sheets(ImportJob $job): array
    {
        try {
            return $this->reader->sheets(Storage::disk('local')->path($job->stored_path), $job->file_type);
        } catch (Throwable) {
            return [];
        }
    }

    public function configure(ImportJob $job, array $mapping, array $options): ImportJob
    {
        $this->assertStatus($job, ['uploaded', 'validated']);
        $mapping = array_filter($mapping, fn ($f) => $f !== null && $f !== '');
        $valid = array_keys(ImportFields::FIELDS);
        foreach ($mapping as $col => $field) {
            if (! in_array($field, $valid, true) || ! isset($job->headers[(int) $col])) {
                throw ValidationException::withMessages(['mapping' => 'Invalid column mapping.']);
            }
        }
        if (count($mapping) !== count(array_unique($mapping))) {
            throw ValidationException::withMessages(['mapping' => 'Each CRM field can be mapped from only one column.']);
        }
        if (! array_intersect($mapping, ['contact.phone', 'contact.whatsapp', 'contact.email'])) {
            throw ValidationException::withMessages(['mapping' => 'Map at least one identifier column: Phone, WhatsApp number or Email.']);
        }
        $job->update(['mapping' => $mapping, 'options' => array_merge($this->defaultOptions(), $options), 'status' => 'validating']);
        StageImport::dispatch($job->id, $job->organization_id, auth()->id());

        return $job->fresh();
    }

    /** Dry run: normalizes each row and decides its action. Runs in a queued job. */
    public function stage(ImportJob $job): void
    {
        $job->rows()->delete();
        $opts = $job->options;
        $abs = Storage::disk('local')->path($job->stored_path);
        $ctx = $this->lookupContext();
        $seen = [];
        $buffer = [];
        $first = true;
        $counts = array_fill_keys(self::ROW_ACTIONS, 0);
        $total = 0;

        foreach ($this->reader->rows($abs, $job->file_type, $job->sheet_name) as $rowNumber => $cells) {
            if ($first) { $first = false; continue; }
            $total++;
            $raw = [];
            foreach ($job->headers as $i => $h) {
                $raw[$h] = $cells[$i] ?? '';
            }
            $values = [];
            foreach ($job->mapping as $col => $field) {
                $values[$field] = trim((string) ($cells[(int) $col] ?? ''));
            }
            [$action, $normalized, $messages, $matchId] = $this->evaluate($values, $opts, $ctx, $seen, $rowNumber);
            $counts[$action]++;
            $buffer[] = [
                'import_job_id' => $job->id, 'row_number' => $rowNumber, 'raw' => json_encode($raw, JSON_UNESCAPED_UNICODE),
                'normalized' => $normalized ? json_encode($normalized, JSON_UNESCAPED_UNICODE) : null, 'action' => $action,
                'matched_contact_id' => $matchId, 'messages' => $messages ? json_encode($messages, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($buffer) >= 500) {
                ImportRow::insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            ImportRow::insert($buffer);
        }
        $job->update([
            'status' => 'validated', 'total_rows' => $total, 'error_count' => $counts['error'], 'review_count' => $counts['review'],
            'created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0,
        ]);
    }

    public function summary(ImportJob $job): array
    {
        $byAction = $job->rows()->select('action', DB::raw('COUNT(*) as c'))->groupBy('action')->pluck('c', 'action');
        $byResult = $job->rows()->whereNotNull('result')->select('result', DB::raw('COUNT(*) as c'))->groupBy('result')->pluck('c', 'result');

        return [
            'planned' => array_merge(array_fill_keys(self::ROW_ACTIONS, 0), $byAction->map(fn ($c) => (int) $c)->all()),
            'results' => $byResult->map(fn ($c) => (int) $c)->all(),
        ];
    }

    public function resolve(ImportJob $job, array $rowIds, bool $allReview, string $action): int
    {
        $this->assertStatus($job, ['validated']);
        if (! in_array($action, ['skip', 'new_lead', 'update', 'create'], true)) {
            throw ValidationException::withMessages(['action' => 'Invalid action.']);
        }
        $q = $job->rows()->where('action', 'review');
        if (! $allReview) {
            $q->whereIn('id', $rowIds);
        }
        if (in_array($action, ['new_lead', 'update'], true)) {
            $q->whereNotNull('matched_contact_id');
        }
        $n = $q->update(['action' => $action, 'updated_at' => now()]);
        $job->update(['review_count' => $job->rows()->where('action', 'review')->count()]);

        return $n;
    }

    public function commit(ImportJob $job): ImportJob
    {
        $this->assertStatus($job, ['validated']);
        $job->update(['status' => 'processing', 'started_at' => now()]);
        $this->audit->log('import.committed', $job, [], ['file' => $job->original_filename]);
        CommitImport::dispatch($job->id, $job->organization_id, auth()->id());

        return $job->fresh();
    }

    /** Processes actionable rows in batches; each row is its own transaction. */
    public function process(ImportJob $job, User $actor): void
    {
        $batch = config('leadflow.import.batch_size');
        $job->rows()->whereIn('action', ['create', 'new_lead', 'update'])->whereNull('result')
            ->orderBy('id')->chunkById($batch, function ($rows) use ($actor) {
                foreach ($rows as $row) {
                    try {
                        DB::transaction(fn () => $this->processRow($row, $actor));
                    } catch (Throwable $e) {
                        $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ')
                            : ($e instanceof DomainRuleException ? $e->getMessage() : 'Unexpected error while importing this row.');
                        if (! $e instanceof ValidationException && ! $e instanceof DomainRuleException) {
                            report($e);
                        }
                        $row->update(['result' => 'failed', 'messages' => array_merge($row->messages ?? [], [$msg])]);
                    }
                }
            });

        $results = $job->rows()->whereNotNull('result')->select('result', DB::raw('COUNT(*) as c'))->groupBy('result')->pluck('c', 'result');
        $unprocessed = $job->rows()->whereIn('action', ['skip', 'review'])->count();
        $job->update([
            'status' => 'completed', 'finished_at' => now(),
            'created_count' => (int) ($results['created'] ?? 0), 'updated_count' => (int) ($results['updated'] ?? 0),
            'skipped_count' => $unprocessed, 'error_count' => $job->rows()->where('action', 'error')->count() + (int) ($results['failed'] ?? 0),
            'review_count' => 0,
        ]);
    }

    private function processRow(ImportRow $row, User $actor): void
    {
        $n = $row->normalized;
        if ($row->action === 'update') {
            $contact = Contact::findOrFail($row->matched_contact_id);
            $this->leads->fillBlanks($contact, $this->leads->contactAttributes($n['contact'], false));
            $this->activity->forContact($contact->id, 'import_update', 'Updated from import (blank fields filled)', ['import_job_id' => $row->import_job_id, 'row' => $row->row_number]);
            $row->update(['result' => 'updated']);

            return;
        }

        $data = [
            'contact' => $n['contact'],
            'contact_id' => $row->action === 'new_lead' ? $row->matched_contact_id : null,
            'contact_strategy' => 'link',
            'lead_source_id' => $n['lead_source_id'],
            'priority' => $n['priority'] ?? 'normal',
            'product_interest' => $n['product_interest'] ?? null,
            'budget_amount' => $n['budget_amount'] ?? null,
            'location' => $n['location'] ?? null,
            'external_lead_id' => $n['external_lead_id'] ?? null,
            'tags' => $n['tags'] ?? [],
            'attribution' => $n['attribution'] ?? [],
            'consents' => $n['consents'] ?? [],
            'consent_evidence' => 'Spreadsheet import #'.$row->import_job_id.' row '.$row->row_number,
            'assigned_user_id' => $n['assigned_user_id'] ?? null,
            'created_at' => $n['created_at'] ?? null,
            'note' => 'Imported from spreadsheet (row '.$row->row_number.')',
        ];
        $lead = $this->leads->create($data, $actor, 'import');

        if (! empty($n['note'])) {
            $this->activity->forLead($lead, 'note', $n['note'], ['import_job_id' => $row->import_job_id]);
        }
        if (! empty($n['call'])) {
            $this->historicalCall($lead, $n['call'], empty($n['lead_status_id']));
        }
        if (! empty($n['lead_status_id']) && $n['lead_status_id'] !== $lead->lead_status_id) {
            $this->statuses->transition($lead->fresh(['status', 'contact']), LeadStatus::findOrFail($n['lead_status_id']), 'Imported from spreadsheet', 'import');
        }
        $lead->refresh();
        if (! empty($n['next_follow_up_at']) && $lead->status->category === 'open' && ! $lead->contact->do_not_contact) {
            $this->followUps->schedule($lead, ['scheduled_at' => $n['next_follow_up_at'], 'purpose' => 'Imported follow-up']);
        }
        $row->update(['result' => 'created', 'lead_id' => $lead->id]);
    }

    private function historicalCall(Lead $lead, array $call, bool $advance): void
    {
        $outcome = CallOutcome::findOrFail($call['outcome_id']);
        $at = $call['called_at'] ?? now()->toDateTimeString();
        CallTask::create([
            'lead_id' => $lead->id, 'assigned_user_id' => $lead->assigned_user_id, 'created_by' => auth()->id(),
            'scheduled_at' => $at, 'purpose' => 'Imported call record', 'attempt_number' => 1, 'status' => 'completed',
            'call_outcome_id' => $outcome->id, 'completed_at' => $at, 'completed_by' => null,
        ]);
        $lead->forceFill([
            'contact_attempts' => $lead->contact_attempts + 1,
            'unanswered_attempts' => $outcome->is_connected ? 0 : 1,
            'first_contacted_at' => $lead->first_contacted_at ?? $at,
            'last_contacted_at' => $at,
            'first_responded_at' => $outcome->is_connected ? ($lead->first_responded_at ?? $at) : $lead->first_responded_at,
        ])->save();
        $this->activity->forLead($lead, 'call_logged', null, ['outcome' => $outcome->name, 'outcome_key' => $outcome->key, 'connected' => $outcome->is_connected, 'imported' => true]);
        if ($advance && $outcome->advances_to_semantic) {
            $target = \App\Enums\StatusSemantic::from($outcome->advances_to_semantic);
            $this->statuses->advance($lead->fresh(['status', 'contact']), $target, $target->category() === 'open' ? null : 'Imported call outcome: '.$outcome->name, 'import');
        }
    }

    /** @return array{0:string,1:?array,2:array,3:?int} */
    private function evaluate(array $v, array $opts, array $ctx, array &$seen, int $rowNumber): array
    {
        $errors = [];
        $warnings = [];
        $n = [];

        $contactInput = [
            'name' => $v['contact.name'] ?? null, 'company_name' => $v['contact.company_name'] ?? null,
            'phone' => $v['contact.phone'] ?? null, 'whatsapp' => $v['contact.whatsapp'] ?? null,
            'email' => $v['contact.email'] ?? null, 'city' => $v['contact.city'] ?? null,
            'phone_region' => $opts['default_region'],
        ];
        $attrs = null;
        try {
            $attrs = $this->leads->contactAttributes($contactInput);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, collect($e->errors())->flatten()->all());
        }
        $n['contact'] = array_filter($contactInput, fn ($x) => $x !== null && $x !== '');

        // Source
        $src = $v['lead.source'] ?? '';
        $n['lead_source_id'] = $src === '' ? ($opts['default_source_id'] ?? null) : ($ctx['sources'][mb_strtolower($src)] ?? null);
        if ($src !== '' && ! $n['lead_source_id']) {
            $errors[] = "Unknown lead source \"{$src}\".";
        }
        // Status
        $st = $v['lead.status'] ?? '';
        if ($st !== '') {
            $n['lead_status_id'] = $ctx['statuses'][mb_strtolower($st)] ?? null;
            if (! $n['lead_status_id']) {
                $errors[] = "Unknown status \"{$st}\".";
            }
        }
        // Owner
        $owner = $v['lead.assigned_to'] ?? '';
        $n['assigned_user_id'] = $owner === '' ? ($opts['default_assignee_id'] ?? null) : ($ctx['users'][mb_strtolower($owner)] ?? null);
        if ($owner !== '' && ! $n['assigned_user_id']) {
            $errors[] = "No active user matches \"{$owner}\".";
        }
        // Priority
        if (($p = mb_strtolower($v['lead.priority'] ?? '')) !== '') {
            $n['priority'] = ['low' => 'low', 'normal' => 'normal', 'medium' => 'normal', 'high' => 'high', 'hot' => 'high', 'urgent' => 'urgent'][$p] ?? null;
            if (! $n['priority']) {
                $errors[] = "Invalid priority \"{$v['lead.priority']}\".";
            }
        }
        // Budget
        if (($b = $v['lead.budget_amount'] ?? '') !== '') {
            $clean = preg_replace('/[^\d.]/', '', str_replace(',', '', $b));
            if ($clean === '' || ! is_numeric($clean)) {
                $errors[] = "Budget \"{$b}\" is not a number.";
            } else {
                $n['budget_amount'] = round((float) $clean, 2);
            }
        }
        foreach (['product_interest', 'location', 'external_lead_id'] as $f) {
            if (($x = $v['lead.'.$f] ?? '') !== '') {
                $n[$f] = mb_substr($x, 0, $f === 'external_lead_id' ? 128 : 191);
            }
        }
        if (($x = $v['lead.note'] ?? '') !== '') {
            $n['note'] = mb_substr($x, 0, 5000);
        }
        if (($x = $v['lead.tags'] ?? '') !== '') {
            $n['tags'] = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $x))));
        }
        // Dates
        foreach (['lead.created_at' => 'created_at', 'lead.next_follow_up_at' => 'next_follow_up_at', 'call.last_called_at' => 'called_at'] as $field => $key) {
            if (($x = $v[$field] ?? '') === '') {
                continue;
            }
            $d = $this->parseDate($x, $opts['date_order'], $ctx['tz']);
            if (! $d) {
                $errors[] = 'Could not read date "'.$x.'" ('.ImportFields::FIELDS[$field][0].').';
            } elseif ($key !== 'next_follow_up_at' && $d->isFuture()) {
                $errors[] = ImportFields::FIELDS[$field][0].' "'.$x.'" is in the future.';
            } else {
                $n[$key] = $d->utc()->toDateTimeString();
                if ($key === 'next_follow_up_at' && $d->isPast()) {
                    $warnings[] = 'Follow-up date is in the past; it will appear as overdue.';
                }
            }
        }
        // Historical call outcome
        if (($o = $v['call.last_outcome'] ?? '') !== '') {
            $oid = $ctx['outcomes'][mb_strtolower($o)] ?? null;
            if ($oid) {
                $n['call'] = ['outcome_id' => $oid, 'called_at' => $n['called_at'] ?? ($n['created_at'] ?? null)];
            } else {
                $warnings[] = "Call outcome \"{$o}\" not recognized; kept in notes.";
                $n['note'] = trim(($n['note'] ?? '')."\nCall outcome (imported): ".$o);
            }
        }
        unset($n['called_at']);
        // Attribution
        $attr = [];
        foreach (['platform', 'campaign_name', 'ad_set_name', 'ad_name', 'external_campaign_id', 'external_ad_set_id', 'external_ad_id'] as $f) {
            if (($x = $v['attribution.'.$f] ?? '') !== '') {
                $attr[$f] = mb_substr($x, 0, 191);
            }
        }
        if ($attr && empty($attr['platform']) && $n['lead_source_id']) {
            $attr['platform'] = $ctx['source_platforms'][$n['lead_source_id']] ?? null;
        }
        $n['attribution'] = $attr;
        if (($c = mb_strtolower($v['consent.whatsapp'] ?? '')) !== '') {
            $yes = in_array($c, ['yes', 'y', 'true', '1', 'granted', 'opted in'], true);
            $no = in_array($c, ['no', 'n', 'false', '0', 'withdrawn', 'opted out'], true);
            if (! $yes && ! $no) {
                $warnings[] = "WhatsApp consent value \"{$v['consent.whatsapp']}\" not recognized; no consent recorded.";
            }
            $n['consents'] = $yes ? ['whatsapp' => true] : [];
        }

        if ($errors) {
            return ['error', $n, array_merge($errors, $warnings), null];
        }

        // External lead id already imported → idempotent skip
        if (! empty($n['external_lead_id']) && Lead::withTrashed()->where('external_lead_id', $n['external_lead_id'])->exists()) {
            return ['skip', $n, array_merge(['A lead with this external ID already exists.'], $warnings), null];
        }

        // In-file duplicates
        $keys = array_filter([$attrs['phone_e164'] ?? null, $attrs['whatsapp_e164'] ?? null, $attrs['email_normalized'] ?? null]);
        foreach ($keys as $k) {
            if (isset($seen[$k])) {
                return ['review', $n, array_merge(["Same contact as row {$seen[$k]} in this file."], $warnings), null];
            }
        }
        foreach ($keys as $k) {
            $seen[$k] = $rowNumber;
        }

        $match = $this->matcher->strongMatches([$attrs['phone_e164'] ?? null, $attrs['whatsapp_e164'] ?? null], $attrs['email_normalized'] ?? null)->first();
        if (! $match) {
            return ['create', $n, $warnings, null];
        }
        $contact = $match['contact'];
        $why = 'Matches existing contact #'.$contact->id.' ('.$contact->displayName().') by '.implode(', ', $match['reasons']).'.';
        $openLead = Lead::where('contact_id', $contact->id)->whereNull('archived_at')->whereHas('status', fn ($q) => $q->where('category', 'open'))->value('id');

        return match ($opts['duplicate_strategy']) {
            'skip' => ['skip', $n, array_merge([$why], $warnings), $contact->id],
            'update_contact' => ['update', $n, array_merge([$why.' Blank contact fields will be filled; no new lead.'], $warnings), $contact->id],
            'new_lead' => $openLead
                ? ['review', $n, array_merge([$why." Contact already has open lead #{$openLead}."], $warnings), $contact->id]
                : ['new_lead', $n, array_merge([$why.' A new inquiry will be added to this contact.'], $warnings), $contact->id],
            default => ['review', $n, array_merge([$why], $warnings), $contact->id],
        };
    }

    private function parseDate(string $x, string $order, string $tz): ?CarbonImmutable
    {
        $x = trim(preg_replace('/\s+/', ' ', $x));
        $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];
        $formats = array_merge($formats, $order === 'mdy'
            ? ['n/j/Y H:i', 'n/j/Y', 'n-j-Y', 'n/j/y']
            : ['j/n/Y H:i', 'j/n/Y', 'j-n-Y', 'j.n.Y', 'j/n/y', 'j-M-Y', 'j M Y', 'j F Y']);
        foreach ($formats as $f) {
            // Strict parse: reject overflow such as 31/02/2026 instead of rolling into March.
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $x, new \DateTimeZone($tz));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return CarbonImmutable::instance($d);
            }
        }

        return null;
    }

    private function lookupContext(): array
    {
        $map = fn ($models, $cols) => $models->flatMap(fn ($m) => collect($cols)->mapWithKeys(fn ($c) => [mb_strtolower((string) $m->{$c}) => $m->id]))->all();
        $sources = LeadSource::where('is_active', true)->get();
        $outcomes = CallOutcome::where('is_active', true)->get();
        $outcomeMap = $map($outcomes, ['name', 'key']);
        $byKey = $outcomes->pluck('id', 'key');
        foreach (['yes' => 'answered', 'responded' => 'answered', 'picked' => 'answered', 'no' => 'no_answer', 'not answered' => 'no_answer',
            'did not answer' => 'no_answer', 'no response' => 'no_answer', 'off' => 'switched_off', 'powered off' => 'switched_off',
            'call back' => 'callback_requested', 'callback' => 'callback_requested', 'wrong' => 'wrong_number'] as $syn => $key) {
            if (isset($byKey[$key])) {
                $outcomeMap[$syn] ??= $byKey[$key];
            }
        }
        $users = User::query()->inCurrentOrganization()->where('is_active', true)->get();

        return [
            'tz' => $this->org->require()->timezone,
            'sources' => $map($sources, ['name', 'key']),
            'source_platforms' => $sources->pluck('platform', 'id')->all(),
            'statuses' => $map(LeadStatus::where('is_active', true)->get(), ['name', 'key']),
            'outcomes' => $outcomeMap,
            'users' => $map($users, ['email', 'name']),
        ];
    }

    private function defaultOptions(): array
    {
        $org = $this->org->require();

        return [
            'duplicate_strategy' => 'review',
            'default_region' => $org->default_country,
            'default_source_id' => LeadSource::where('key', 'import')->value('id'),
            'default_assignee_id' => null,
            'date_order' => 'dmy',
        ];
    }

    private function assertStatus(ImportJob $job, array $allowed): void
    {
        if (! in_array($job->status, $allowed, true)) {
            throw new DomainRuleException("This import is {$job->status} and cannot be changed at this step.", 'import_wrong_state', 409);
        }
    }
}
