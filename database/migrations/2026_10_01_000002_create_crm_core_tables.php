<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->string('platform', 30)->nullable(); // facebook, instagram, whatsapp, web, offline
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            // semantic stage drives automation + metrics regardless of display name
            $table->string('semantic', 30);
            $table->string('category', 10); // open | won | lost
            $table->string('color', 20)->default('secondary');
            $table->string('requires_reason', 20)->default('none'); // none | lost_reason | opt_out_reason
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'semantic']);
        });

        Schema::create('call_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->boolean('is_connected')->default(false);   // a human conversation happened
            $table->boolean('creates_callback')->default(false);
            $table->string('advances_to_semantic', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30); // meta, facebook, instagram, google, other
            $table->string('external_id', 64)->nullable();
            $table->string('name', 191);
            $table->string('objective', 60)->nullable();
            $table->string('status', 30)->default('active');
            $table->char('currency', 3);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('data_source', 20)->default('manual'); // manual | meta_api | import
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'platform', 'external_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->string('name', 191);
            $table->timestamps();
            $table->unique(['organization_id', 'campaign_id', 'external_id']);
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->string('name', 191);
            $table->timestamps();
            $table->unique(['organization_id', 'campaign_id', 'external_id']);
        });

        Schema::create('campaign_spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->date('spend_date');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('data_source', 20)->default('manual');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['campaign_id', 'spend_date', 'data_source']);
            $table->index(['organization_id', 'spend_date']);
        });

        // Anonymous / aggregate ad activity. Deliberately has NO contact or lead FK.
        Schema::create('campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40); // ctwa_click, link_click, conversation_started
            $table->date('event_date');
            $table->unsignedInteger('event_count')->default(1);
            $table->string('data_source', 20);
            $table->string('external_event_id', 128)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'data_source', 'external_event_id'], 'campaign_events_org_source_event_unique');
            $table->index(['organization_id', 'event_date', 'event_type']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12)->default('individual'); // individual | business
            $table->string('name', 160)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('email_normalized', 191)->nullable();
            $table->string('phone_raw', 40)->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('whatsapp_raw', 40)->nullable();
            $table->string('whatsapp_e164', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('preferred_contact_method', 20)->nullable(); // phone | whatsapp | email
            $table->boolean('do_not_contact')->default(false);
            $table->foreignId('merged_into_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'phone_e164']);
            $table->index(['organization_id', 'whatsapp_e164']);
            $table->index(['organization_id', 'email_normalized']);
            $table->index(['organization_id', 'name']);
        });

        Schema::create('contact_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30); // whatsapp_wa_id, meta_leadgen, meta_psid
            $table->string('external_id', 128);
            $table->timestamps();
            $table->unique(['organization_id', 'provider', 'external_id']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_status_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 10)->default('normal');
            $table->string('product_interest', 191)->nullable();
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->char('budget_currency', 3)->nullable();
            $table->string('location', 160)->nullable();
            $table->text('source_notes')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->decimal('deal_value', 14, 2)->nullable();
            $table->char('deal_currency', 3)->nullable();
            $table->string('external_lead_id', 128)->nullable();
            $table->unsignedSmallInteger('contact_attempts')->default(0);
            $table->unsignedSmallInteger('unanswered_attempts')->default(0);
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'lead_status_id']);
            $table->index(['organization_id', 'assigned_user_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'next_follow_up_at']);
            $table->index(['organization_id', 'lead_source_id']);
            $table->index(['organization_id', 'contact_id']);
            $table->unique(['organization_id', 'external_lead_id']);
        });

        Schema::create('lead_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('touch', 10); // first | last
            $table->string('platform', 30)->nullable();
            $table->string('source', 80)->nullable();
            $table->string('medium', 80)->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_campaign_id', 64)->nullable();
            $table->string('external_ad_set_id', 64)->nullable();
            $table->string('external_ad_id', 64)->nullable();
            $table->string('campaign_name_snapshot', 191)->nullable();
            $table->string('ad_set_name_snapshot', 191)->nullable();
            $table->string('ad_name_snapshot', 191)->nullable();
            $table->json('referral')->nullable();
            $table->string('completeness', 10)->default('partial'); // complete | partial | unknown
            $table->string('captured_via', 30); // manual, import, whatsapp_referral, meta_leadgen, utm
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->unique(['lead_id', 'touch']);
            $table->index(['organization_id', 'campaign_id']);
        });

        Schema::create('lead_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('lead_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_via', 20)->default('user'); // user | call_outcome | import | automation
            $table->timestamp('created_at')->useCurrent();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('secondary');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('lead_tag_assignments', function (Blueprint $table) {
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['lead_id', 'tag_id']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('visibility', 10)->default('internal'); // internal | customer
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['lead_id', 'occurred_at']);
            $table->index(['contact_id', 'occurred_at']);
            $table->index(['organization_id', 'type', 'occurred_at']);
        });

        Schema::create('call_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('priority', 10)->default('normal');
            $table->string('purpose', 191)->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('status', 12)->default('pending'); // pending | completed | missed | cancelled
            $table->foreignId('call_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('duration_seconds')->nullable(); // only when entered by a user
            $table->text('notes')->nullable();
            $table->string('next_action', 191)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 191)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'scheduled_at']);
            $table->index(['organization_id', 'assigned_user_id', 'status']);
            $table->index(['lead_id', 'status']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('meeting'); // meeting | site_visit | video | call
            $table->string('title', 191);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location', 191)->nullable();
            $table->string('status', 12)->default('scheduled'); // scheduled | completed | cancelled | no_show
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'assigned_user_id', 'starts_at']);
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30); // whatsapp | phone | sms | email | ads_audience
            $table->string('status', 12);  // granted | withdrawn
            $table->string('source', 40);  // verbal, web_form, whatsapp_message, import, instant_form
            $table->text('evidence')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contact_id', 'channel', 'recorded_at']);
        });

        Schema::create('suppression_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_e164', 20)->nullable();
            $table->string('email_normalized', 191)->nullable();
            $table->string('channel', 20); // all | whatsapp | phone | email | ads_audience
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'phone_e164']);
            $table->index(['organization_id', 'contact_id']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 30);
            $table->string('name', 80);
            $table->json('filters');
            $table->json('columns')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'module', 'name']);
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename', 191);
            $table->string('stored_path', 255);
            $table->string('file_type', 8);
            $table->string('sheet_name', 120)->nullable();
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->string('status', 20)->default('uploaded'); // uploaded | validated | processing | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('normalized')->nullable();
            $table->string('action', 12); // create | update | new_lead | skip | review | error
            $table->foreignId('matched_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->json('messages')->nullable();
            $table->string('result', 12)->nullable(); // created | updated | skipped | failed
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['import_job_id', 'action']);
            $table->index(['import_job_id', 'row_number']);
        });
    }

    public function down(): void
    {
        foreach ([
            'import_rows', 'import_jobs', 'saved_views', 'suppression_records', 'consent_records', 'appointments',
            'call_tasks', 'activities', 'lead_tag_assignments', 'tags', 'lead_assignments', 'lead_status_history',
            'lead_attributions', 'leads', 'contact_identities', 'contacts', 'campaign_events', 'campaign_spend',
            'ads', 'ad_sets', 'campaigns', 'call_outcomes', 'lead_statuses', 'lead_sources',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
