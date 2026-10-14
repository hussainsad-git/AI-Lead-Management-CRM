<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public capture endpoints for website / landing-page forms. The key is the only credential a public
        // site holds: it can create leads for one business and nothing else, and is rotatable per form.
        Schema::create('web_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('key', 40)->unique();
            $t->foreignId('lead_source_id')->constrained()->restrictOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('allowed_origins')->nullable();   // ["https://example.com"]; null = any origin (not recommended)
            $t->string('redirect_url', 500)->nullable(); // for plain HTML posts; must be on an allowed origin
            $t->json('tags')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('submissions_count')->default(0);
            $t->timestamp('last_submission_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'is_active']);
        });

        Schema::create('web_form_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('web_form_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20); // created | linked | duplicate | rejected | invalid
            $t->string('ip_hash', 64)->nullable();
            $t->json('payload')->nullable(); // submitted fields minus honeypot; pruned after 90 days
            $t->string('error', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['web_form_id', 'created_at']);
            $t->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_form_submissions');
        Schema::dropIfExists('web_forms');
    }
};
