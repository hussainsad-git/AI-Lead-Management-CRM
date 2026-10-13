<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Meta leadgen id: idempotency for webhook retries + backfill, and an audit of what
        // happened to each submission. Holds no answers (PII lives only on the contact/lead).
        Schema::create('lead_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_lead_id', 64);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 20)->default('pending'); // pending | created | existing_lead | failed | no_contact
            $table->string('received_via', 12); // webhook | backfill
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'external_lead_id']);
            $table->index(['organization_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_submissions');
    }
};
