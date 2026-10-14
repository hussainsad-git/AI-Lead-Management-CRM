<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\WebForm;
use App\Models\WebFormSubmission;
use Tests\TestCase;

class WebFormTest extends TestCase
{
    private function form(array $attrs = []): WebForm
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $src = $this->inOrg($org, fn () => LeadSource::where('key', 'website_form')->first());
        $res = $this->api($admin, 'POST', 'web-forms', array_merge(['name' => 'Contact page', 'lead_source_id' => $src->id, 'allowed_origins' => ['https://example.com'], 'tags' => ['web']], $attrs))->assertCreated();

        return WebForm::withoutGlobalScopes()->find($res->json('data.id'));
    }

    public function test_submission_creates_lead_with_attribution_consent_and_tags(): void
    {
        $form = $this->form();
        $r = $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/webhooks/forms/'.$form->key, [
            'Full Name' => 'Ali Raza', 'Mobile' => '0300 1234567', 'Email' => 'ali@example.com', 'message' => 'Need a 5 marla plot', 'interest' => 'Plots',
            'budget' => '2,500,000', 'consent_whatsapp' => 'yes', 'utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'Spring Launch', 'fbclid' => 'abc', 'page_url' => 'https://example.com/plots',
            'website' => '', // empty honeypot
        ]);
        $r->assertStatus(201)->assertJson(['ok' => true, 'status' => 'received'])->assertHeader('Access-Control-Allow-Origin', 'https://example.com');
        $this->assertArrayNotHasKey('lead_id', $r->json());

        $lead = $this->inOrg($form->organization, fn () => Lead::with(['contact', 'tags', 'lastTouch'])->latest('id')->first());
        $this->assertSame('Ali Raza', $lead->contact->name);
        $this->assertSame('+923001234567', $lead->contact->phone_e164);
        $this->assertSame('Plots', $lead->product_interest);
        $this->assertSame('2500000.00', (string) $lead->budget_amount);
        $this->assertSame($form->lead_source_id, $lead->lead_source_id);
        $this->assertSame(['web'], $lead->tags->pluck('name')->all());
        $this->assertSame('facebook', $lead->lastTouch->platform);
        $this->assertSame('cpc', $lead->lastTouch->medium);
        $this->assertSame('Spring Launch', $lead->lastTouch->campaign_name_snapshot);
        $this->assertSame('web_form', $lead->lastTouch->captured_via);
        $this->assertSame('https://example.com/plots', $lead->lastTouch->referral['page_url']);
        $this->assertTrue($this->inOrg($form->organization, fn () => $lead->contact->consents()->where('channel', 'whatsapp')->where('status', 'granted')->where('source', 'web_form')->exists()));
        $this->assertFalse($this->inOrg($form->organization, fn () => $lead->contact->consents()->where('channel', 'email')->exists()));
        $note = $this->inOrg($form->organization, fn () => Activity::where('lead_id', $lead->id)->where('type', 'lead_created')->first());
        $this->assertStringContainsString('5 marla', $note->body);
        $this->assertSame(1, WebFormSubmission::withoutGlobalScopes()->where('status', 'created')->count());
        $this->assertSame(1, $form->fresh()->submissions_count);
    }

    public function test_origin_allow_list_and_cors_preflight(): void
    {
        $form = $this->form();
        $this->withHeaders(['Origin' => 'https://evil.test'])->postJson('/webhooks/forms/'.$form->key, ['phone' => '03001234567'])
            ->assertStatus(403)->assertJsonPath('error.code', 'origin_not_allowed')->assertHeaderMissing('Access-Control-Allow-Origin');
        // plain HTML post carries only a Referer
        $this->withHeaders(['Referer' => 'https://example.com/contact?x=1'])->post('/webhooks/forms/'.$form->key, ['phone' => '03001234568'])->assertStatus(201);
        $this->call('OPTIONS', '/webhooks/forms/'.$form->key, [], [], [], ['HTTP_ORIGIN' => 'https://example.com'])->assertNoContent()->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $this->call('OPTIONS', '/webhooks/forms/'.$form->key, [], [], [], ['HTTP_ORIGIN' => 'https://evil.test'])->assertForbidden();
        $this->assertSame(0, Lead::withoutGlobalScopes()->whereHas('contact', fn ($c) => $c->where('phone_e164', '+923001234567'))->count());
    }

    public function test_honeypot_is_rejected_silently_and_invalid_phone_returns_422(): void
    {
        $form = $this->form();
        $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/webhooks/forms/'.$form->key, ['phone' => '03001234567', 'website' => 'http://spam'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
        $this->assertSame(0, WebFormSubmission::withoutGlobalScopes()->count()); // nothing stored for bots

        $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/webhooks/forms/'.$form->key, ['name' => 'X', 'phone' => '12'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation')->assertJsonStructure(['error' => ['fields' => ['phone']]]);
        $this->assertSame('invalid', WebFormSubmission::withoutGlobalScopes()->latest('id')->first()->status);
    }

    public function test_repeat_inquiry_links_to_open_lead_and_double_submit_is_absorbed(): void
    {
        $form = $this->form();
        $h = ['Origin' => 'https://example.com'];
        $this->withHeaders($h)->postJson('/webhooks/forms/'.$form->key, ['phone' => '03001234567', 'message' => 'first'])->assertStatus(201);
        $this->withHeaders($h)->postJson('/webhooks/forms/'.$form->key, ['phone' => '03001234567', 'message' => 'first'])->assertOk(); // within 10 min → duplicate
        $this->assertSame('duplicate', WebFormSubmission::withoutGlobalScopes()->latest('id')->first()->status);

        $this->travel(11)->minutes();
        $this->withHeaders($h)->postJson('/webhooks/forms/'.$form->key, ['phone' => '+92 300 1234567', 'message' => 'second question', 'interest' => 'Villas'])->assertOk();
        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
        $lead = $this->inOrg($form->organization, fn () => Lead::first());
        $act = $this->inOrg($form->organization, fn () => Activity::where('lead_id', $lead->id)->where('type', 'web_form_submission')->first());
        $this->assertSame('second question', $act->body);
        $this->assertSame('Villas', $act->meta['interest']);
        $this->assertSame('linked', WebFormSubmission::withoutGlobalScopes()->latest('id')->first()->status);
    }

    public function test_inactive_or_unknown_key_is_404_and_rotation_invalidates_old_key(): void
    {
        $form = $this->form();
        $admin = $this->inOrg($form->organization, fn () => \App\Models\User::first());
        $this->postJson('/webhooks/forms/'.str_repeat('a', 40), ['phone' => '03001234567'])->assertNotFound();
        $old = $form->key;
        $new = $this->api($admin, 'POST', 'web-forms/'.$form->id.'/rotate-key')->assertOk()->json('data.key');
        $this->assertNotSame($old, $new);
        $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/webhooks/forms/'.$old, ['phone' => '03001234567'])->assertNotFound();
        $this->api($admin, 'PATCH', 'web-forms/'.$form->id, ['is_active' => false])->assertOk();
        $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/webhooks/forms/'.$new, ['phone' => '03001234567'])->assertNotFound();
    }

    public function test_management_requires_permission_and_is_tenant_scoped(): void
    {
        $form = $this->form();
        $rep = $this->makeUser($form->organization, 'sales_rep');
        $this->api($rep, 'GET', 'web-forms')->assertForbidden();
        $other = $this->makeOrg('Other');
        $otherAdmin = $this->makeUser($other);
        $this->api($otherAdmin, 'GET', 'web-forms')->assertOk()->assertJsonCount(0, 'data.forms');
        $this->api($otherAdmin, 'PATCH', 'web-forms/'.$form->id, ['name' => 'hijack'])->assertNotFound();
        $this->api($otherAdmin, 'GET', 'web-forms/'.$form->id.'/submissions')->assertNotFound();
        $admin = $this->inOrg($form->organization, fn () => \App\Models\User::first());
        $this->api($admin, 'PATCH', 'web-forms/'.$form->id, ['redirect_url' => 'https://elsewhere.test/thanks'])->assertStatus(422);
        $this->api($admin, 'PATCH', 'web-forms/'.$form->id, ['allowed_origins' => ['https://example.com/path']])->assertStatus(422);
        $this->api($admin, 'GET', 'web-forms')->assertOk()->assertJsonPath('data.forms.0.name', 'Contact page')->assertJsonStructure(['data' => ['forms' => [['embed_html', 'endpoint', 'last_7_days']]]]);
    }

    public function test_plain_html_post_redirects_to_thank_you_page(): void
    {
        $form = $this->form(['redirect_url' => 'https://example.com/thanks']);
        $this->withHeaders(['Referer' => 'https://example.com/contact', 'Accept' => 'text/html'])->post('/webhooks/forms/'.$form->key, ['phone' => '03001234567'])
            ->assertStatus(303)->assertRedirect('https://example.com/thanks');
        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
    }
}
