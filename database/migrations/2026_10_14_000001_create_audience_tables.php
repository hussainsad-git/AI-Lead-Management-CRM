<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('kind', 12)->default('audience'); // audience | exclusion
            $table->json('definition');                       // {match, groups:[{match, rules:[{field, op, value}]}]}
            $table->string('consent_policy', 16)->default('not_withdrawn'); // granted | not_withdrawn
            $table->json('exclusion_segment_ids')->nullable();
            $table->string('template_key', 40)->nullable();
            $table->boolean('auto_sync')->default(false);
            $table->string('meta_audience_id', 40)->nullable();
            $table->string('meta_audience_name', 191)->nullable();
            $table->unsignedInteger('last_count')->nullable();
            $table->unsignedInteger('last_eligible')->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'template_key']);
            $table->index(['organization_id', 'kind']);
        });

        // Current membership snapshot, kept across refreshes so the timeline can show joins/leaves.
        Schema::create('audience_segment_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audience_segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->boolean('eligible')->default(false);      // passes consent + identifier + suppression checks
            $table->string('ineligible_reason', 40)->nullable(); // no_identifier | consent | suppressed | excluded
            $table->timestamp('first_included_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('removed_at')->nullable();
            $table->unique(['audience_segment_id', 'lead_id']);
            $table->index(['audience_segment_id', 'removed_at', 'eligible']);
        });

        Schema::create('audience_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audience_segment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // meta_replace | csv_export | meta_create
            $table->string('status', 12)->default('queued'); // queued | running | success | failed
            $table->unsignedInteger('members')->default(0);
            $table->unsignedInteger('eligible')->default(0);
            $table->unsignedInteger('uploaded')->default(0);
            $table->json('skipped')->nullable(); // {no_identifier, consent, suppressed, excluded}
            $table->string('meta_session_id', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'audience_segment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_sync_jobs');
        Schema::dropIfExists('audience_segment_memberships');
        Schema::dropIfExists('audience_segments');
    }
};
