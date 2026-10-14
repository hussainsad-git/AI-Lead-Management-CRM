<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indexes for the scans and segment queries added in Phases 3–5.
        Schema::table('leads', function (Blueprint $t) {
            $t->index(['organization_id', 'first_contacted_at'], 'leads_org_first_contacted_idx');
            $t->index(['organization_id', 'last_activity_at'], 'leads_org_last_activity_idx');
            $t->index(['organization_id', 'score_label'], 'leads_org_score_idx');
            $t->index(['organization_id', 'archived_at', 'qualified_at'], 'leads_org_archived_qualified_idx');
        });
        Schema::table('automation_executions', function (Blueprint $t) {
            $t->index(['lead_id', 'created_at'], 'automation_executions_lead_idx');
            $t->index(['automation_rule_id', 'status', 'created_at'], 'automation_executions_rule_status_idx');
        });
        Schema::table('webhook_events', function (Blueprint $t) {
            $t->index(['status', 'created_at'], 'webhook_events_status_created_idx');
        });

        // Personal API tokens (hashed; the plaintext is shown once).
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('token_hash', 64)->unique();
            $t->string('token_prefix', 12);
            $t->timestamp('last_used_at')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'revoked_at']);
        });

        // Erasure (right-to-be-forgotten style) marker: PII is overwritten, record skeleton is kept for audit/metrics.
        Schema::table('contacts', function (Blueprint $t) {
            $t->timestamp('erased_at')->nullable()->after('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('erased_at'));
        Schema::dropIfExists('api_tokens');
        Schema::table('webhook_events', fn (Blueprint $t) => $t->dropIndex('webhook_events_status_created_idx'));
        Schema::table('automation_executions', function (Blueprint $t) {
            $t->dropIndex('automation_executions_lead_idx');
            $t->dropIndex('automation_executions_rule_status_idx');
        });
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex('leads_org_first_contacted_idx');
            $t->dropIndex('leads_org_last_activity_idx');
            $t->dropIndex('leads_org_score_idx');
            $t->dropIndex('leads_org_archived_qualified_idx');
        });
    }
};
