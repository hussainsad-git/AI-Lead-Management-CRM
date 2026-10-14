<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('totp_secret')->nullable()->after('password');            // encrypted; set while enrolling
            $t->timestamp('totp_confirmed_at')->nullable()->after('totp_secret'); // null = not enabled
            $t->bigInteger('totp_last_step')->nullable()->after('totp_confirmed_at'); // replay protection
            $t->text('recovery_codes')->nullable()->after('totp_last_step');    // encrypted JSON of hashed codes
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step', 'recovery_codes']));
    }
};
