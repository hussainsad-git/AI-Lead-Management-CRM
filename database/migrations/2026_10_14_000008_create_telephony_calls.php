<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Calls placed through a connected telephony provider (click-to-call). Status, timestamps and duration come
        // from the provider's callbacks only; nothing here is inferred. The rep still logs the outcome on the lead.
        Schema::create('telephony_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider', 20);                       // twilio
            $t->string('provider_sid', 64)->nullable()->unique(); // parent (agent-leg) call SID
            $t->string('direction', 10)->default('outbound');
            $t->string('status', 20)->default('queued');     // queued | ringing | in-progress | completed | busy | no-answer | failed | canceled
            $t->string('lead_leg_status', 20)->nullable();   // DialCallStatus of the customer leg
            $t->string('from_number', 32);
            $t->string('to_number', 32);                      // customer, E.164
            $t->string('agent_number', 32);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();      // agent leg, from provider
            $t->unsignedInteger('talk_seconds')->nullable();          // customer leg (DialCallDuration), from provider
            $t->string('recording_sid', 64)->nullable();
            $t->string('recording_url', 500)->nullable();
            $t->decimal('price', 10, 5)->nullable();
            $t->string('price_unit', 3)->nullable();
            $t->string('error', 300)->nullable();
            $t->boolean('outcome_logged')->default(false);
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
            $t->index(['lead_id', 'created_at']);
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_calls');
    }
};
