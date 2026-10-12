<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ImportJob;
use App\Models\Lead;
use App\Services\LeadExportService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DataExchangeTest extends TestCase
{
    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('leads.csv', $content);
    }

    public function test_csv_import_dry_run_reports_every_row_and_commits_valid_ones(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org, 'business_admin', ['name' => 'Sara Admin']);
        $existing = $this->createLead($org, $admin, ['contact' => ['phone' => '03005556677']]);

        $csv = "\xEF\xBB\xBFName,Mobile,Status,Campaign,Date,Call Status,Notes,Owner\n".
            "Ahmed,0300 1234567,Interested,Spring Promo,05/10/2026,not answered,Wants 2 bed,Sara Admin\n".
            "Bad Phone,12345,,,,,,\n".
            "Dup In File,+923001234567,,,,,,\n".
            "Existing,03005556677,,,,,,\n".
            "Unknown status,03111111111,Hot lead,,,,,\n".
            "Nobody,03112222222,,,,,,Ghost User\n".
            "Future,03113333333,,,01/01/2099,,,\n";
        $up = $this->actingAs($admin)->post('/api/v1/imports', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])->assertCreated();
        $id = $up->json('data.id');
        $this->assertSame(7, $up->json('data.total_rows'));
        $mapping = $up->json('data.mapping');
        $this->assertSame('contact.name', $mapping['0']);
        $this->assertSame('contact.phone', $mapping['1']);
        $this->assertSame('call.last_outcome', $mapping['5']);

        $mapping['3'] = 'attribution.campaign_name';
        $res = $this->api($admin, 'POST', "imports/$id/configure", ['mapping' => $mapping, 'options' => ['duplicate_strategy' => 'review']])->assertOk();
        $p = $res->json('data.summary.planned');
        $this->assertSame(['create' => 1, 'new_lead' => 0, 'update' => 0, 'skip' => 0, 'review' => 2, 'error' => 4], $p);

        $errors = collect($this->api($admin, 'GET', "imports/$id/rows?action=error")->json('data'))->pluck('messages')->flatten()->implode(' ');
        $this->assertStringContainsString('Unknown status "Hot lead"', $errors);
        $this->assertStringContainsString('No active user matches "Ghost User"', $errors);
        $this->assertStringContainsString('future', $errors);

        $this->api($admin, 'POST', "imports/$id/resolve", ['action' => 'skip', 'all_review' => true])->assertOk();
        $this->api($admin, 'POST', "imports/$id/commit")->assertOk();
        $job = $this->inOrg($org, fn () => ImportJob::find($id));
        $this->assertSame('completed', $job->status);
        $this->assertSame(1, $job->created_count);
        $this->assertSame(4, $job->error_count);

        $this->inOrg($org, function () use ($admin) {
            $lead = Lead::with(['status', 'contact', 'firstTouch'])->whereHas('contact', fn ($q) => $q->where('phone_e164', '+923001234567'))->first();
            $this->assertSame('interested', $lead->status->key);   // explicit status column wins
            $this->assertSame(1, $lead->contact_attempts);         // historical unanswered call
            $this->assertSame(1, $lead->unanswered_attempts);
            $this->assertNotNull($lead->first_responded_at); // the explicit "Interested" status implies a response
            $this->assertSame('Spring Promo', $lead->firstTouch->campaign_name_snapshot);
            $this->assertSame($admin->id, $lead->assigned_user_id);
            $this->assertSame('2026-10-05', $lead->created_at->setTimezone('Asia/Karachi')->toDateString());
            $this->assertSame(1, Campaign::where('name', 'Spring Promo')->count());
            $this->assertSame(2, Contact::count());
        });

        $report = $this->actingAs($admin)->get("/api/v1/imports/$id/report");
        $report->assertOk();
        $this->assertStringContainsString('Bad Phone', $report->streamedContent());
    }

    public function test_xlsx_import_reads_numeric_phone_cells(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Name', 'Phone', 'Email'], ['Zara', 923001239876, 'zara@example.com']]);
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'leads.xlsx', null, null, true);

        $up = $this->actingAs($admin)->post('/api/v1/imports', ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $id = $up->json('data.id');
        $this->api($admin, 'POST', "imports/$id/configure", ['mapping' => $up->json('data.mapping')])->assertJsonPath('data.summary.planned.create', 1);
        $this->api($admin, 'POST', "imports/$id/commit")->assertOk();
        $this->assertSame('+923001239876', $this->inOrg($org, fn () => Contact::first()->phone_e164));
    }

    public function test_reimport_with_external_id_is_idempotent(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $csv = "Phone,Lead ID\n03001231231,L-1\n";
        foreach ([1, 2] as $run) {
            $up = $this->actingAs($admin)->post('/api/v1/imports', ['file' => $this->csv($csv)], ['Accept' => 'application/json']);
            $id = $up->json('data.id');
            $res = $this->api($admin, 'POST', "imports/$id/configure", ['mapping' => $up->json('data.mapping')]);
            $this->assertSame($run === 1 ? 1 : 0, $res->json('data.summary.planned.create'));
            if ($run === 1) {
                $this->api($admin, 'POST', "imports/$id/commit");
            }
        }
        $this->assertSame(1, $this->inOrg($org, fn () => Lead::count()));
    }

    public function test_import_requires_identifier_mapping_and_permission(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->actingAs($rep)->post('/api/v1/imports', ['file' => $this->csv("Name\nA\n")], ['Accept' => 'application/json'])->assertForbidden();
        $up = $this->actingAs($admin)->post('/api/v1/imports', ['file' => $this->csv("Name\nA\n")], ['Accept' => 'application/json'])->assertCreated();
        $this->api($admin, 'POST', 'imports/'.$up->json('data.id').'/configure', ['mapping' => ['0' => 'contact.name']])->assertStatus(422);
    }

    public function test_export_is_permissioned_audited_and_formula_safe(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->createLead($org, $admin, ['contact' => ['name' => '=HYPERLINK("http://evil")', 'phone' => '03001234567']]);
        $res = $this->api($admin, 'POST', 'leads/export', ['format' => 'csv', 'columns' => ['name', 'phone']])->assertOk();
        $body = $res->streamedContent();
        $this->assertStringContainsString("\"'=HYPERLINK", $body);
        $this->assertStringContainsString('+923001234567', $body);
        $this->assertStringNotContainsString("'+92", $body);
        $this->assertSame(1, $this->inOrg($org, fn () => \App\Models\AuditLog::where('action', 'leads.exported')->count()));

        $this->assertSame("'@SUM(A1)", LeadExportService::neutralize('@SUM(A1)'));
        $this->assertSame("'-2+3", LeadExportService::neutralize('-2+3'));
        $this->assertSame('-', LeadExportService::neutralize('-'));

        $xlsx = $this->api($admin, 'POST', 'leads/export', ['format' => 'xlsx'])->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
    }
}
