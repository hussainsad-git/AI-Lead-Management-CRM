<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permission-checked, audited exports. Values are written as text and neutralized against
 * spreadsheet formula injection (CSV) or typed explicitly as strings (XLSX).
 */
class LeadExportService
{
    public const MAX_ROWS = 50000;

    public const COLUMNS = [
        'id' => 'Lead ID', 'name' => 'Name', 'company' => 'Company', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp',
        'email' => 'Email', 'source' => 'Source', 'platform' => 'Platform', 'campaign' => 'Campaign', 'ad_set' => 'Ad set',
        'ad' => 'Ad', 'status' => 'Status', 'priority' => 'Priority', 'assignee' => 'Assigned to',
        'contact_attempts' => 'Contact attempts', 'last_contacted_at' => 'Last contacted', 'next_follow_up_at' => 'Next follow-up',
        'created_at' => 'Created', 'last_activity_at' => 'Last activity', 'product_interest' => 'Interest',
        'tags' => 'Tags', 'do_not_contact' => 'Do not contact',
    ];

    public function __construct(private LeadFilter $filter, private LeadVisibility $visibility, private AuditLogger $audit, private CurrentOrganization $org) {}

    public function export(User $user, array $filters, array $columns, string $format, ?array $ids = null): StreamedResponse
    {
        $columns = array_values(array_intersect($columns ?: array_keys(self::COLUMNS), array_keys(self::COLUMNS)));
        $q = $this->visibility->apply(Lead::query(), $user);
        $ids ? $q->whereIn('leads.id', $ids)->whereNull('leads.archived_at') : $this->filter->apply($q, $filters);
        $q->with(['contact', 'status', 'source', 'assignee', 'tags', 'firstTouch'])->orderBy('leads.id');
        $count = (clone $q)->count();
        if ($count > self::MAX_ROWS) {
            throw new \App\Exceptions\DomainRuleException('Export is limited to '.self::MAX_ROWS.' rows; narrow your filters.', 'export_too_large');
        }

        $this->audit->log('leads.exported', null, [], [
            'format' => $format, 'rows' => $count, 'columns' => $columns, 'filters' => $ids ? ['ids' => count($ids)] : $filters,
        ]);

        $tz = $this->org->require()->timezone;
        $name = 'leads-'.now($tz)->format('Ymd-His').'.'.$format;
        $rows = function () use ($q, $columns, $tz) {
            foreach ($q->lazy(500) as $lead) {
                yield array_map(fn ($c) => $this->value($lead, $c, $tz), $columns);
            }
        };
        $headers = array_map(fn ($c) => self::COLUMNS[$c], $columns);

        return $format === 'xlsx' ? $this->xlsx($name, $headers, $rows) : $this->csv($name, $headers, $rows);
    }

    public static function neutralize(string $v): string
    {
        if ($v === '') {
            return $v;
        }
        $first = $v[0];
        // Phone numbers like "+923001234567" or "-" are legitimate; anything else starting with a
        // formula trigger is prefixed with an apostrophe so spreadsheet apps treat it as text.
        if (in_array($first, ['+', '-'], true) && preg_match('/^[+-][\d\s().-]*$/', $v)) {
            return $v;
        }

        return in_array($first, ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    private function value(Lead $lead, string $col, string $tz): string
    {
        $c = $lead->contact;
        $t = $lead->firstTouch;
        $date = fn ($d) => $d ? $d->setTimezone($tz)->format('Y-m-d H:i') : '';

        return (string) match ($col) {
            'id' => $lead->id, 'name' => $c?->name, 'company' => $c?->company_name,
            'phone' => $c?->phone_e164 ?? $c?->phone_raw, 'whatsapp' => $c?->whatsapp_e164 ?? $c?->whatsapp_raw, 'email' => $c?->email,
            'source' => $lead->source?->name, 'platform' => $t?->platform, 'campaign' => $t?->campaign_name_snapshot,
            'ad_set' => $t?->ad_set_name_snapshot, 'ad' => $t?->ad_name_snapshot, 'status' => $lead->status?->name,
            'priority' => $lead->priority, 'assignee' => $lead->assignee?->name, 'contact_attempts' => $lead->contact_attempts,
            'last_contacted_at' => $date($lead->last_contacted_at), 'next_follow_up_at' => $date($lead->next_follow_up_at),
            'created_at' => $date($lead->created_at), 'last_activity_at' => $date($lead->last_activity_at),
            'product_interest' => $lead->product_interest, 'tags' => $lead->tags->pluck('name')->implode(', '),
            'do_not_contact' => $c?->do_not_contact ? 'yes' : 'no',
            default => '',
        };
    }

    private function csv(string $name, array $headers, \Closure $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            foreach ($rows() as $r) {
                fputcsv($out, array_map([self::class, 'neutralize'], $r), ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function xlsx(string $name, array $headers, \Closure $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Leads');
            $r = 1;
            foreach (array_merge([$headers], iterator_to_array($rows(), false)) as $line) {
                foreach (array_values($line) as $i => $value) {
                    $sheet->getCell([$i + 1, $r])->setValueExplicit((string) $value, DataType::TYPE_STRING);
                }
                $r++;
            }
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }
}
