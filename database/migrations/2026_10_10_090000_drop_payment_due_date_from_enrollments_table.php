<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the last piece of payment tracking.
     *
     * The client has confirmed that this application tracks no payment
     * information at all. The column was added for SOW reminder 7 under open
     * decision S3, and PaymentDueTrigger was the only thing that ever read it;
     * both go in the same change, so nothing is left reading a column that no
     * longer exists and nothing is left writing one nobody reads.
     *
     * Dropping it loses whatever dates were recorded. That is the instruction
     * rather than an accident: the business does not want the information
     * held. Nothing else is touched - no enrolment is deleted, no status
     * changed, and every programme record keeps the enrolment it hangs off.
     *
     * down() restores the column but not its contents, which is the honest
     * shape of this change: a reversal returns the structure, and the data it
     * held is gone on purpose.
     */
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('payment_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->date('payment_due_date')->nullable()->after('withdrawal_reason');
        });
    }
};
