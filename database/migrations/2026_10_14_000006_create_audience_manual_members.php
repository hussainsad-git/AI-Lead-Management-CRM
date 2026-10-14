<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leads added to a segment by hand (bulk action). They join the segment's query results;
        // eligibility (opt-out, consent, exclusions, identifier) is still applied on top.
        Schema::create('audience_manual_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('audience_segment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['audience_segment_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_manual_members');
    }
};
