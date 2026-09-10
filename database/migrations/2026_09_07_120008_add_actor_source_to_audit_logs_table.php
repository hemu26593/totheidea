<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration A1 - the audit_logs extension (Step 3B section 6.2).
     *
     * This is NOT one of the 42 BMP tables. It extends the existing Step 2
     * audit table rather than replacing it: there is one audit system.
     *
     * audit_logs.actor_id assumes an authenticated user. Under the hybrid entry
     * model a mutation may arrive from an external grant with no user at all,
     * so without a source discriminator an external submission appears
     * actor-less and is indistinguishable from an automated system write.
     * "Did staff enter this, or did the owner?" is exactly the question the
     * locked rules require to be answerable.
     *
     * Ordering is fixed on both sides: it cannot precede access_grants,
     * because access_grant_id references it; and it must precede the first
     * grant ever issued, so that grant's audit entry carries the discriminator.
     * Hence its slot immediately after table 7.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('source', 16)->default('internal_user')->after('actor_label');
            $table->foreignId('access_grant_id')->nullable()->after('source')
                ->constrained('access_grants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_grant_id');
            $table->dropColumn('source');
        });
    }
};
