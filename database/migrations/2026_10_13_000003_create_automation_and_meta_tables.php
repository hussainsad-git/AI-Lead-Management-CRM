<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->string('trigger', 40);           // see config/automation.php
            $table->unsignedInteger('delay_minutes')->default(0); // for time-based triggers
            $table->json('conditions')->nullable();  // {"match":"all|any","rules":[{"field","op","value"}]}
            $table->json('actions');                 // [{"type": "...", ...params}]
            $table->boolean('is_active')->default(true);
            $table->string('template_key', 40)->nullable(); // seeded default rules
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'trigger', 'is_active']);
        });

        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 40);
            $table->string('dedupe_key', 120);       // the same rule never runs twice for the same event
            $table->string('status', 12);            // success | partial | skipped | failed
            $table->json('results')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['automation_rule_id', 'dedupe_key']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable()->after('locale');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable()->after('read_at');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('ad_account_id', 40)->nullable()->after('external_id');
        });
        Schema::table('ad_sets', function (Blueprint $table) {
            $table->string('status', 30)->nullable()->after('name');
        });
        Schema::table('ads', function (Blueprint $table) {
            $table->string('status', 30)->nullable()->after('name');
        });

        // Meta Instant Forms seen on the connected Page
        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 40);
            $table->string('page_id', 40)->nullable();
            $table->string('name', 191)->nullable();
            $table->string('status', 30)->nullable();
            $table->unsignedInteger('leads_received')->default(0);
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_forms');
        Schema::table('ads', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('ad_sets', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('ad_account_id'));
        Schema::table('notifications', fn (Blueprint $t) => $t->dropColumn('emailed_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_prefs'));
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_rules');
    }
};
