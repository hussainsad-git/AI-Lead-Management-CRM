<?php

namespace Tests\Feature;

use App\Models\AiAssistant;
use App\Models\AiInteraction;
use App\Models\AutoReplyRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Services\Ai\KnowledgeBase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppAiTest extends TestCase
{
    private const SECRET = 'app-secret-123';

    private function connect(Organization $org, array $ai = []): Integration
    {
        return $this->inOrg($org, function () use ($ai) {
            $wa = Integration::create(['provider' => 'whatsapp_cloud', 'status' => 'connected', 'webhook_key' => Str::random(40),
                'config' => ['phone_number_id' => '1112223334', 'waba_id' => '5556667778', 'api_version' => 'v24.0'],
                'credentials' => ['access_token' => 'EAAG-test-token', 'app_secret' => self::SECRET, 'verify_token' => 'verify-me']]);
            Integration::create(['provider' => 'openai', 'status' => 'connected', 'config' => ['default_model' => 'test-chat-model'], 'credentials' => ['api_key' => 'sk-test']]);
            AiAssistant::query()->update($ai + ['mode' => 'auto', 'provider' => 'openai', 'auto_qualify' => false]);

            return $wa;
        });
    }

    private function payload(string $from, string $text, string $wamid, array $extra = []): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '5556667778', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '923000000000', 'phone_number_id' => '1112223334'],
            'contacts' => [['profile' => ['name' => 'Bilal'], 'wa_id' => $from]],
            'messages' => [array_merge(['from' => $from, 'id' => $wamid, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]], $extra)],
        ]]]]]];
    }

    private function deliver(Integration $wa, array $payload, ?string $secret = null)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp/'.$wa->webhook_key, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? self::SECRET)], $raw);
    }

    private function fakeApis(array $aiReply, int &$sent = 0): void
    {
        Http::fake(function (Request $r) use ($aiReply, &$sent) {
            if (str_contains($r->url(), 'graph.facebook.com')) {
                if (str_ends_with($r->url(), '/messages') && ($r['type'] ?? null)) {
                    $sent++;

                    return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT'.$sent]]]);
                }

                return Http::response(['success' => true]);
            }
            if (str_contains($r->url(), '/chat/completions')) {
                return Http::response(['model' => 'test-chat-model', 'choices' => [['message' => ['content' => json_encode($aiReply)]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20]]);
            }
            if (str_contains($r->url(), '/embeddings')) {
                return Http::response(['data' => collect($r['input'])->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0, 0.5]])->all()]);
            }

            return Http::response([], 404);
        });
    }

    public function test_webhook_verification_handshake(): void
    {
        $org = $this->makeOrg();
        $wa = $this->connect($org);
        $this->get("/webhooks/whatsapp/{$wa->webhook_key}?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345")->assertOk()->assertSee('12345');
        $this->get("/webhooks/whatsapp/{$wa->webhook_key}?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345")->assertForbidden();
        $this->get('/webhooks/whatsapp/'.str_repeat('a', 40).'?hub.mode=subscribe&hub.verify_token=verify-me')->assertNotFound();
    }

    public function test_bad_signature_is_rejected_and_nothing_stored(): void
    {
        $org = $this->makeOrg();
        $wa = $this->connect($org);
        $this->deliver($wa, $this->payload('923001234567', 'hi', 'wamid.1'), 'wrong-secret')->assertStatus(401);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_inbound_message_creates_contact_lead_conversation_with_ad_attribution_and_ai_reply(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $wa = $this->connect($org);
        $this->inOrg($org, fn () => app(KnowledgeBase::class)->addText('Payment plans', 'DHA Phase 9 plots: 5 marla plot price is PKR 45 lakh with a 3-year installment plan.'));
        $sent = 0;
        $this->fakeApis(['reply' => 'Ji, 5 marla plot PKR 45 lakh hai, 3 saal ki qiston par.', 'language' => 'roman_ur', 'answerable' => true, 'needs_human' => false, 'off_topic' => false], $sent);

        $referral = ['referral' => ['source_url' => 'https://fb.me/abc', 'source_id' => '120200000001', 'source_type' => 'ad', 'headline' => 'DHA Phase 9 Plots', 'ctwa_clid' => 'ARAkLk']];
        $this->deliver($wa, $this->payload('923001234567', '5 marla ki qeemat kya hai?', 'wamid.IN1', $referral))->assertOk();

        $this->inOrg($org, function () use (&$sent) {
            $contact = Contact::first();
            $this->assertSame('+923001234567', $contact->whatsapp_e164);
            $this->assertSame('Bilal', $contact->name);
            $lead = Lead::with('source', 'firstTouch')->first();
            $this->assertSame('facebook_ads', $lead->source->key);
            $this->assertSame('120200000001', $lead->firstTouch->external_ad_id);
            $this->assertSame('whatsapp_referral', $lead->firstTouch->captured_via);
            $this->assertSame('ARAkLk', $lead->firstTouch->referral['ctwa_clid']);
            $c = Conversation::first();
            $this->assertTrue($c->windowOpen());
            $out = Message::where('direction', 'out')->first();
            $this->assertSame('ai', $out->sender_type);
            $this->assertSame('accepted', $out->status); // accepted by API ≠ delivered
            $this->assertSame('wamid.OUT1', $out->provider_message_id);
            $this->assertSame(1, $sent);
            $this->assertNotEmpty(AiInteraction::where('kind', 'reply')->first()->retrieved_chunk_ids);
        });

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/chat/completions') && str_contains($r['messages'][0]['content'], '45 lakh')
            && $r->hasHeader('Authorization', 'Bearer sk-test'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v24.0/1112223334/messages' && $r['to'] === '923001234567');
    }

    public function test_duplicate_webhook_delivery_is_idempotent(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $wa = $this->connect($org, ['mode' => 'off']);
        $this->fakeApis([]);
        $p = $this->payload('923001234567', 'Hello', 'wamid.DUP');
        $this->deliver($wa, $p)->assertOk();
        $this->deliver($wa, $p)->assertOk();
        // same message id in a different envelope (Meta retry with a new body)
        $p2 = $p;
        $p2['entry'][0]['id'] = 'other';
        $this->deliver($wa, $p2)->assertOk();
        $this->inOrg($org, function () use ($admin) {
            $this->assertSame(1, Message::count());
            $this->assertSame(1, Lead::count());
            $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        });
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_status_webhooks_advance_forward_only_and_record_failures(): void
    {
        $org = $this->makeOrg();
        $wa = $this->connect($org);
        $msg = $this->inOrg($org, function () {
            $contact = Contact::create(['whatsapp_e164' => '+923001234567']);
            $c = Conversation::create(['contact_id' => $contact->id, 'external_id' => '923001234567']);

            return Message::create(['conversation_id' => $c->id, 'direction' => 'out', 'sender_type' => 'user', 'body' => 'x', 'provider_message_id' => 'wamid.S1', 'status' => 'accepted', 'occurred_at' => now()]);
        });
        $status = fn ($s, $extra = []) => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1112223334'], 'statuses' => [array_merge(['id' => 'wamid.S1', 'status' => $s, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '923001234567'], $extra)]]]]]]];
        $this->deliver($wa, $status('read'));
        $this->deliver($wa, $status('delivered')); // late, out of order
        $this->assertSame('read', $msg->fresh()->status);
        $this->assertNotNull($msg->fresh()->delivered_at);
        $this->deliver($wa, $status('failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]));
        $this->assertSame('failed', $msg->fresh()->status);
        $this->assertStringContainsString('undeliverable', $msg->fresh()->error);
    }

    public function test_ai_handoff_when_knowledge_missing(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $wa = $this->connect($org);
        $sent = 0;
        $this->fakeApis(['reply' => 'Let me check', 'answerable' => false, 'needs_human' => true, 'handoff_reason' => 'Price not in knowledge base', 'off_topic' => false], $sent);
        $this->deliver($wa, $this->payload('923001234567', 'What is the price of a 10 marla house?', 'wamid.H1'))->assertOk();
        $this->inOrg($org, function () use ($admin, &$sent) {
            $c = Conversation::first();
            $this->assertNotNull($c->handoff_at);
            $this->assertSame('Price not in knowledge base', $c->handoff_reason);
            $out = Message::where('direction', 'out')->first();
            $this->assertSame('system', $out->sender_type); // the handoff message, not the AI's guess
            $this->assertSame(AiAssistant::first()->handoff_message, $out->body);
            $this->assertSame(1, Notification::where('type', 'handoff')->count());
            $this->assertSame(1, $sent);
        });
        // after handoff the AI stays quiet until a human resolves it
        $this->deliver($wa, $this->payload('923001234567', 'hello?', 'wamid.H2'))->assertOk();
        $this->assertSame(1, $sent);
        $this->inOrg($org, fn () => $this->assertSame(1, AiInteraction::where('kind', 'reply')->count()));
    }

    public function test_unparseable_ai_output_is_never_sent(): void
    {
        $org = $this->makeOrg();
        $this->makeUser($org);
        $wa = $this->connect($org);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'chat/completions')
            ? Http::response(['choices' => [['message' => ['content' => 'Sure! Our price is 10 lakh.']]]])
            : Http::response(['messages' => [['id' => 'wamid.X']]]));
        $this->deliver($wa, $this->payload('923001234567', 'price?', 'wamid.U1'));
        $this->inOrg($org, function () {
            $this->assertFalse(Message::where('direction', 'out')->where('body', 'like', '%10 lakh%')->exists());
            $this->assertNotNull(Conversation::first()->handoff_at);
        });
    }

    public function test_opt_out_keyword_suppresses_contact_and_blocks_sending(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $wa = $this->connect($org);
        $sent = 0;
        $this->fakeApis(['reply' => 'x', 'answerable' => true, 'needs_human' => false], $sent);
        $this->deliver($wa, $this->payload('923001234567', 'STOP', 'wamid.O1'));
        $this->assertSame(0, $sent);
        $cid = $this->inOrg($org, fn () => Conversation::first()->id);
        $this->inOrg($org, fn () => $this->assertTrue(Contact::first()->isSuppressed('whatsapp')));
        $this->api($admin, 'POST', "conversations/$cid/messages", ['body' => 'Hello again'])->assertStatus(422)->assertJsonPath('error.code', 'contact_suppressed');
    }

    public function test_keyword_auto_reply_with_cooldown_runs_before_ai(): void
    {
        $org = $this->makeOrg();
        $this->makeUser($org);
        $wa = $this->connect($org);
        $this->inOrg($org, fn () => AutoReplyRule::create(['name' => 'Location', 'trigger' => 'keyword', 'keywords' => ['location', 'address'], 'response' => 'We are at {business}, Blue Area.', 'stop_ai' => true, 'cooldown_minutes' => 60]));
        $sent = 0;
        $this->fakeApis(['reply' => 'AI', 'answerable' => true], $sent);
        $this->deliver($wa, $this->payload('923001234567', 'What is your location?', 'wamid.K1'));
        $this->deliver($wa, $this->payload('923001234567', 'location please', 'wamid.K2'));
        $this->inOrg($org, function () {
            $auto = Message::where('sender_type', 'auto_reply')->get();
            $this->assertCount(1, $auto);
            $this->assertStringContainsString('Acme Realty', $auto[0]->body);
            $this->assertSame(0, AiInteraction::where('kind', 'reply')->count()); // stop_ai
        });
    }

    public function test_agent_send_respects_24h_window_and_templates(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->connect($org, ['mode' => 'off']);
        $c = $this->inOrg($org, function () {
            $contact = Contact::create(['whatsapp_e164' => '+923001234567']);

            return Conversation::create(['contact_id' => $contact->id, 'external_id' => '923001234567', 'last_inbound_at' => now()->subHours(30)]);
        });
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T1']]])]);
        $this->api($admin, 'POST', "conversations/{$c->id}/messages", ['body' => 'Hi'])->assertStatus(422)->assertJsonPath('error.code', 'window_closed');
        $tpl = $this->inOrg($org, fn () => \App\Models\MessageTemplate::create(['name' => 'follow_up', 'language' => 'en', 'status' => 'APPROVED', 'body_params' => 1,
            'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, are you still interested?']]]));
        $this->api($admin, 'POST', "conversations/{$c->id}/messages", ['template_id' => $tpl->id, 'params' => ['Bilal']])->assertCreated()
            ->assertJsonPath('data.0.body', 'Hi Bilal, are you still interested?');
        Http::assertSent(fn (Request $r) => ($r['type'] ?? null) === 'template' && $r['template']['components'][0]['parameters'][0]['text'] === 'Bilal');
    }

    public function test_expired_token_marks_integration_error_and_message_failed(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $wa = $this->connect($org, ['mode' => 'off']);
        $c = $this->inOrg($org, fn () => Conversation::create(['contact_id' => Contact::create(['whatsapp_e164' => '+923001234567'])->id, 'external_id' => '923001234567', 'last_inbound_at' => now()]));
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190]], 401)]);
        $this->api($admin, 'POST', "conversations/{$c->id}/messages", ['body' => 'Hi'])->assertStatus(502);
        $this->assertSame('error', $wa->fresh()->status);
        $this->assertStringContainsString('expired', $wa->fresh()->last_error);
        $this->inOrg($org, fn () => $this->assertSame('failed', Message::first()->status));
    }

    public function test_integration_secrets_are_write_only(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'PUT', 'integrations/openai', ['secrets' => ['api_key' => 'sk-live-secret-9999'], 'config' => ['embedding_model' => 'text-embedding-3-small']])->assertOk();
        $res = $this->api($admin, 'GET', 'integrations/status')->assertOk();
        $this->assertStringNotContainsString('sk-live-secret', $res->getContent());
        $res->assertJsonPath('data.1.secrets.api_key.hint', '••••9999');
        $raw = \Illuminate\Support\Facades\DB::table('integrations')->where('provider', 'openai')->value('credentials');
        $this->assertStringNotContainsString('sk-live-secret', $raw); // encrypted at rest
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'GET', 'integrations/status')->assertForbidden();
    }

    public function test_deepseek_provider_uses_its_endpoint_and_default_model(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->inOrg($org, function () {
            Integration::create(['provider' => 'deepseek', 'status' => 'connected', 'config' => [], 'credentials' => ['api_key' => 'ds-key']]);
            AiAssistant::query()->update(['provider' => 'deepseek', 'model' => null, 'kb_only' => false]);
        });
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => '{"reply":"Hello","answerable":true,"needs_human":false,"off_topic":false,"language":"en"}']]]])]);
        $this->api($admin, 'POST', 'ai/playground', ['question' => 'hi'])->assertOk()->assertJsonPath('data.reply', 'Hello');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.deepseek.com/chat/completions' && $r['model'] === 'deepseek-v4-flash' && isset($r['max_tokens']));
    }

    public function test_knowledge_base_ingest_search_and_learning(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'POST', 'kb/documents', ['type' => 'text', 'title' => 'Fees', 'content' => "MBA program fee is PKR 350,000 per semester.\n\nBBA fee is PKR 180,000 per semester."])->assertCreated();
        $this->api($admin, 'POST', 'kb/documents', ['type' => 'faq', 'question' => 'Do you have hostel?', 'answer' => 'Yes, separate hostels for boys and girls, PKR 25,000 per month.'])->assertCreated();
        $this->api($admin, 'POST', 'kb/documents', ['type' => 'text', 'title' => 'اوقات', 'content' => 'دفتر کے اوقات صبح نو بجے سے شام پانچ بجے تک ہیں۔'])->assertCreated();
        $this->inOrg($org, fn () => $this->assertSame(3, KbDocument::where('status', 'ready')->count()));

        $hits = $this->api($admin, 'GET', 'kb/search?q='.urlencode('what is the MBA fee'))->assertOk()->json('data');
        $this->assertStringContainsString('MBA', $hits[0]['content']);
        $hits = $this->api($admin, 'GET', 'kb/search?q='.urlencode('hostel charges'))->json('data');
        $this->assertStringContainsString('25,000', $hits[0]['content']);
        $hits = $this->api($admin, 'GET', 'kb/search?q='.urlencode('دفتر کے اوقات'))->json('data');
        $this->assertStringContainsString('نو بجے', $hits[0]['content']);
        $this->assertSame([], $this->api($admin, 'GET', 'kb/search?q=quantum+physics')->json('data'));

        // learning: handed-off question appears in queue; approving it adds a learned KB entry
        $inter = $this->inOrg($org, fn () => AiInteraction::create(['kind' => 'reply', 'status' => 'ok', 'outcome' => 'handoff', 'input' => 'Is transport available?', 'response' => 'A team member will confirm.']));
        $this->api($admin, 'GET', 'ai/learning')->assertJsonPath('data.0.id', $inter->id);
        $this->api($admin, 'POST', "ai-interactions/{$inter->id}/feedback", ['feedback' => 'corrected', 'corrected_text' => 'Yes, buses cover all major routes.', 'teach' => true])->assertOk();
        $this->api($admin, 'GET', 'ai/learning')->assertJsonCount(0, 'data');
        $hits = $this->api($admin, 'GET', 'kb/search?q='.urlencode('transport buses'))->json('data');
        $this->assertStringContainsString('buses cover', $hits[0]['content']);
    }

    public function test_embeddings_are_stored_when_openai_connected(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->connect($org);
        $this->fakeApis([]);
        $this->api($admin, 'POST', 'kb/documents', ['type' => 'text', 'title' => 'Doc', 'content' => 'Some product information about solar panels.'])->assertCreated();
        $this->inOrg($org, function () {
            $chunk = KbChunk::first();
            $this->assertSame([1.0, 0.0, 0.5], $chunk->vector());
            $this->assertSame('text-embedding-3-small', KbDocument::first()->embedding_model);
        });
    }

    public function test_qualifier_fills_only_blank_fields_and_scores(): void
    {
        $org = $this->makeOrg();
        $this->makeUser($org);
        $wa = $this->connect($org, ['mode' => 'off', 'auto_qualify' => true]);
        $this->inOrg($org, function () {
            $c = Contact::create(['name' => 'Existing Name', 'whatsapp_e164' => '+923001234567']);
            \App\Models\ContactIdentity::create(['contact_id' => $c->id, 'provider' => 'whatsapp_wa_id', 'external_id' => '923001234567']);
        });
        Http::fake(fn (Request $r) => Http::response(['choices' => [['message' => ['content' => json_encode([
            'name' => 'Different Name', 'email' => 'bilal@example.com', 'city' => 'Lahore', 'product_interest' => '10 marla house', 'budget_amount' => 25000000,
            'budget_currency' => 'PKR', 'intent' => 'high', 'language' => 'roman_ur', 'summary' => 'Wants a 10 marla house in Lahore', 'wants_no_contact' => false])]]]]));
        $this->deliver($wa, $this->payload('923001234567', 'Mujhe Lahore mein 10 marla ghar chahiye, budget 2.5 crore. bilal@example.com', 'wamid.Q1'));
        $this->inOrg($org, function () {
            $lead = Lead::with('contact')->first();
            $this->assertSame('Existing Name', $lead->contact->name); // never overwritten
            $this->assertSame('bilal@example.com', $lead->contact->email);
            $this->assertSame('Lahore', $lead->contact->city);
            $this->assertSame('10 marla house', $lead->product_interest);
            $this->assertEquals(25000000, $lead->budget_amount);
            $this->assertSame('hot', $lead->score_label);
            $keys = array_column($lead->score_factors, 'key');
            $this->assertContains('explicit_interest', $keys);
            $this->assertContains('budget_stated', $keys);
            $this->assertContains('recent_inbound', $keys);
        });
    }

    public function test_conversation_visibility_follows_lead_visibility(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep1 = $this->makeUser($org, 'sales_rep');
        $rep2 = $this->makeUser($org, 'sales_rep');
        $c = $this->inOrg($org, fn () => Conversation::create(['contact_id' => Contact::create(['whatsapp_e164' => '+923001234567'])->id, 'external_id' => '923001234567', 'assigned_user_id' => $rep1->id]));
        $this->api($rep1, 'GET', 'conversations')->assertJsonCount(1, 'data');
        $this->api($rep2, 'GET', 'conversations')->assertJsonCount(0, 'data');
        $this->api($rep2, 'GET', "conversations/{$c->id}")->assertNotFound();
        $other = $this->makeOrg('Other Org');
        $otherAdmin = $this->makeUser($other);
        $this->api($otherAdmin, 'GET', "conversations/{$c->id}")->assertNotFound();
    }

    public function test_round_robin_assigns_new_whatsapp_leads(): void
    {
        $org = $this->makeOrg();
        $this->makeUser($org);
        $r1 = $this->makeUser($org, 'sales_rep');
        $r2 = $this->makeUser($org, 'sales_rep');
        $wa = $this->connect($org, ['mode' => 'off']);
        $this->inOrg($org, fn () => app(\App\Services\OrganizationSettings::class)->set('auto_assign', ['enabled' => true]));
        $this->fakeApis([]);
        $this->deliver($wa, $this->payload('923001111111', 'hi', 'wamid.R1'));
        $this->deliver($wa, $this->payload('923002222222', 'hi', 'wamid.R2'));
        $this->deliver($wa, $this->payload('923003333333', 'hi', 'wamid.R3'));
        $owners = $this->inOrg($org, fn () => Lead::orderBy('id')->pluck('assigned_user_id')->all());
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id], array_unique(array_slice($owners, 0, 2)));
        $this->assertSame($owners[0], $owners[2]);
    }

    public function test_platform_admin_creates_isolated_business(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'GET', 'platform')->assertForbidden();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->api($admin, 'POST', 'platform/organizations', ['name' => 'City School', 'industry' => 'education', 'timezone' => 'Asia/Karachi', 'currency' => 'PKR', 'country' => 'PK',
            'admin_name' => 'Principal', 'admin_email' => 'principal@school.test', 'admin_password' => 'Password123!'])->assertCreated();
        $new = Organization::where('name', 'City School')->first();
        $this->inOrg($new, fn () => $this->assertStringContainsString('admissions assistant', AiAssistant::first()->persona));
        $this->api($admin, 'GET', 'platform')->assertOk()->assertJsonCount(2, 'data.organizations');
        $this->post('/logout');
        $this->post('/login', ['email' => 'principal@school.test', 'password' => 'Password123!'])->assertRedirect('/');
    }

    public function test_locale_switch_renders_rtl_layout(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->actingAs($admin)->post('/locale', ['locale' => 'ur'])->assertRedirect();
        $html = $this->actingAs($admin->fresh())->get('/leads')->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('bootstrap.rtl.min.css', $html);
        $this->assertStringContainsString('لیڈز', $html);
    }
}
