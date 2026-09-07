<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Account activation state. A deactivated user cannot authenticate,
            // and is signed out of any live session (ADR-009).
            $table->boolean('is_active')->default(true)->after('password');

            // Dormant-account detection and incident forensics.
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });

        // Deliberately no index on is_active: this table holds internal staff
        // only (tens of rows). An index there would cost writes for a scan that
        // will never be slow.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_at', 'last_login_ip']);
        });
    }
};
