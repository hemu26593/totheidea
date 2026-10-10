<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One customer's participation in one batch - THE OWNERSHIP SPINE.
     *
     * Without it a repeat participant's two runs merge, and every attendance
     * percentage and completion score becomes wrong. Every enrolment-owned
     * table resolves isolation through this row.
     *
     * payment_due_date was client decision S3: reminder 7 fired on a payment due
     * date while the same SOW excluded payment features, and one nullable date
     * was the narrowest structure that satisfied it.
     *
     * THAT COLUMN NO LONGER EXISTS. The client has since confirmed that this
     * application tracks no payment information at all, and
     * 2026_10_10_090000_drop_payment_due_date_from_enrollments_table removes it.
     * The definition below is left as it was so the migration history replays
     * truthfully; nothing reads the column between the two migrations.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();

            // Both RESTRICT: an enrolment anchors a whole programme run's data.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();

            $table->string('status', 16)->default('enrolled');

            $table->timestamp('enrolled_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 255)->nullable();

            $table->date('payment_due_date')->nullable();

            $table->timestamps();

            // No double enrolment in the same run. Deliberately excludes
            // status, so a withdrawn enrolment still blocks a duplicate;
            // re-participation is a LATER batch, which this key permits.
            $table->unique(['customer_id', 'batch_id']);
            // The roster, and the Monday batch roll-up.
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
