<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('industry', 40)->nullable()->after('name');
            $table->string('logo_path', 255)->nullable()->after('industry');
            $table->string('brand_color', 7)->nullable()->after('logo_path');
            $table->string('default_locale', 8)->default('en')->after('default_country');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('timezone');
            $table->boolean('is_platform_admin')->default(false)->after('is_active');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->smallInteger('score')->nullable()->after('priority');
            $table->string('score_label', 16)->nullable()->after('score'); // hot | warm | cold | needs_review
            $table->json('score_factors')->nullable()->after('score_label');
            $table->timestamp('scored_at')->nullable()->after('score_factors');
            $table->text('ai_summary')->nullable()->after('source_notes');
            $table->string('language', 12)->nullable()->after('location');
            $table->index(['organization_id', 'score_label']);
        });

        // Third-party connections. Secrets live in `credentials` (encrypted with APP_KEY) and never leave the server.
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30); // whatsapp_cloud | openai | deepseek
            $table->string('status', 20)->default('not_configured'); // not_configured | connected | error | disabled
            $table->json('config')->nullable();          // non-secret settings (ids, api version, models)
            $table->text('credentials')->nullable();     // encrypted JSON
            $table->string('webhook_key', 64)->nullable()->unique(); // public, unguessable path segment
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('event_key', 128); // sha256 of the raw body → retries of the same delivery are ignored
            $table->string('status', 12)->default('received'); // received | processed | failed | ignored
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_key']);
            $table->index(['organization_id', 'status', 'created_at']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('external_id', 64); // WhatsApp wa_id
            $table->string('status', 12)->default('open'); // open | pending | closed
            $table->string('ai_mode', 10)->default('inherit'); // inherit | on | off
            $table->unsignedSmallInteger('ai_reply_count')->default(0);
            $table->timestamp('handoff_at')->nullable();
            $table->string('handoff_reason', 255)->nullable();
            $table->string('language', 12)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_inbound_at')->nullable();  // drives the 24-hour customer-service window
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'channel', 'external_id']);
            $table->index(['organization_id', 'status', 'last_message_at']);
            $table->index(['organization_id', 'assigned_user_id']);
        });

        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16); // reply | suggest | qualify | playground
            $table->string('provider', 20)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('status', 12); // ok | error | skipped
            $table->string('outcome', 16)->nullable(); // answered | handoff | off_topic | extracted
            $table->text('input')->nullable();
            $table->text('response')->nullable();
            $table->json('retrieved_chunk_ids')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('feedback', 12)->nullable(); // up | down | corrected
            $table->text('corrected_text')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'kind', 'created_at']);
            $table->index(['organization_id', 'outcome']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->string('sender_type', 12); // contact | user | ai | auto_reply | system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_interaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->json('media')->nullable();
            $table->string('template_name', 120)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            // queued = stored locally, not yet accepted by Meta; sent/delivered/read/failed come only from the provider
            $table->string('status', 12);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->unique(['organization_id', 'provider_message_id']);
            $table->index(['conversation_id', 'occurred_at']);
            $table->index(['organization_id', 'direction', 'occurred_at']);
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->string('name', 120);
            $table->string('language', 16);
            $table->string('category', 20)->nullable();
            $table->string('status', 20); // APPROVED | PENDING | REJECTED | PAUSED | DISABLED
            $table->json('components')->nullable();
            $table->unsignedTinyInteger('body_params')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name', 'language']);
        });

        Schema::create('ai_assistants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('industry', 40)->default('general');
            $table->string('provider', 20)->default('openai'); // openai | deepseek
            $table->string('model', 80)->nullable();
            $table->decimal('temperature', 3, 2)->default(0.30);
            $table->unsignedSmallInteger('max_tokens')->default(400);
            $table->text('persona');          // who the assistant is
            $table->text('instructions')->nullable(); // business rules, tone, what to collect
            $table->text('greeting')->nullable();
            $table->text('fallback_message')->nullable(); // when the knowledge base has no answer
            $table->text('handoff_message')->nullable();
            $table->string('mode', 10)->default('suggest'); // off | suggest | auto
            $table->string('active_hours', 16)->default('always'); // always | outside_hours | in_hours
            $table->boolean('kb_only')->default(true);
            $table->json('languages')->nullable(); // allowed reply languages
            $table->string('default_language', 12)->default('en');
            $table->json('handoff_keywords')->nullable();
            $table->json('qualification_fields')->nullable();
            $table->unsignedSmallInteger('max_auto_replies')->default(8);
            $table->unsignedSmallInteger('history_messages')->default(12);
            $table->boolean('auto_qualify')->default(true);
            $table->timestamps();
            $table->unique('organization_id');
        });

        Schema::create('auto_reply_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 20); // keyword | first_message | outside_hours
            $table->string('match_type', 10)->default('contains'); // contains | exact | regex
            $table->json('keywords')->nullable();
            $table->text('response');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedInteger('cooldown_minutes')->default(720);
            $table->boolean('stop_ai')->default(false); // skip the AI after this reply
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'is_active', 'priority']);
        });

        Schema::create('kb_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191);
            $table->string('source_type', 12); // file | text | faq | learned
            $table->string('original_filename', 191)->nullable();
            $table->string('stored_path', 255)->nullable();
            $table->string('language', 12)->nullable();
            $table->string('status', 12)->default('pending'); // pending | processing | ready | failed
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('char_count')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->string('embedding_model', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('kb_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kb_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('content');
            $table->text('search_text'); // lowercased, normalized tokens for lexical retrieval
            $table->longText('embedding')->nullable(); // base64 packed float32
            $table->unsignedInteger('times_retrieved')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'kb_document_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 191);
            $table->string('body', 500)->nullable();
            $table->string('url', 255)->nullable();
            $table->string('dedupe_key', 191); // duplicate webhooks / retries never notify twice
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'kb_chunks', 'kb_documents', 'auto_reply_rules', 'ai_assistants', 'message_templates', 'messages', 'ai_interactions', 'conversations', 'webhook_events', 'integrations'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['organization_id', 'score_label']);
            $t->dropColumn(['score', 'score_label', 'score_factors', 'scored_at', 'ai_summary', 'language']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['locale', 'is_platform_admin']));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['industry', 'logo_path', 'brand_color', 'default_locale']));
    }
};
