<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One day's business figures - the daily heartbeat.
     *
     * OWNED BY THE CUSTOMER, attributed to an enrolment. Continuous business
     * data outlives any single programme run, which is why enrollment_id is
     * nullable and SET NULL rather than the owner.
     *
     * FIXED COLUMNS, NOT ROWS: the metric set is known and every one of them
     * is aggregated. T / Th / S ADD NO COLUMNS - Tuesday, Thursday and
     * Saturday are a DERIVED weekly presentation over these daily rows, not
     * measures.
     *
     * There is no mmd_dashboard table. Target-versus-actual, the T/Th/S
     * weekly view and the Average / Good / Better / Best evaluation row are
     * all queries over this table, mmd_targets, and the relevant form answer.
     *
     * Amended with audit, never deleted. amended_at is distinct from
     * updated_at: updated_at records that a row changed, never what or why.
     */
    public function up(): void
    {
        Schema::create('mmd_entries', function (Blueprint $table) {
            $table->id();

            // Owner.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // Attribution only. SET NULL: the figures survive the run they
            // were attributed to.
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();

            $table->date('entry_date');

            $table->decimal('fund_in', 14, 2)->nullable();
            $table->decimal('fund_out', 14, 2)->nullable();
            $table->unsignedInteger('enquiries_new')->nullable();
            $table->unsignedInteger('enquiries_repeat')->nullable();
            $table->unsignedInteger('enquiries_referral')->nullable();
            $table->unsignedInteger('sales_closed_count')->nullable();
            $table->decimal('sales_closed_value', 14, 2)->nullable();
            $table->decimal('production', 14, 2)->nullable();

            $table->timestamp('recorded_at');
            $table->timestamp('amended_at')->nullable();

            // OPTIMISTIC LOCKING. This is the one table where two actors may
            // plausibly write the same row at once: an enter_mmd grant used by
            // the owner while staff key the same day from a paper sheet.
            //
            // lockForUpdate() is a NO-OP on SQLite, so a pessimistic lock
            // would pass every test here and fail in production. A version
            // column checked in UPDATE ... WHERE id = ? AND lock_version = ?
            // behaves identically on both engines. It is deliberately on no
            // other table - none has a concrete contention scenario.
            $table->unsignedInteger('lock_version')->default(0);

            // Actor triple.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // ================================================================
            // [B1 CLIENT DECISION] - THE UNIQUE CONSTRAINT IS NOT APPLIED
            // ================================================================
            //
            // Answer A - one business/customer entry per day:
            //     $table->unique(['customer_id', 'entry_date']);
            //
            // Answer B - contributor / function rows per day:
            //     $table->unique(['customer_id', 'entry_date', '<dimension>']);
            //
            // NEITHER IS CHOSEN, and no contributor column is invented above.
            //
            // This is a GRAIN AND PRIMARY KEY question, not an additive
            // column. Specifying the wrong grain means rebuilding this table
            // and every query and reminder that reads it - notification
            // triggers 5 and 6 both depend on "was there an entry for this
            // day". Adding the key later is one migration; removing it after
            // production data has been shaped by it is not.
            //
            // Everything downstream is written to be grain-agnostic:
            // target-versus-actual and the weekly view AGGREGATE over a date
            // range, which is correct under both answers.
            // ================================================================

            // Cross-customer trend, and the nightly "who missed yesterday?"
            // sweep.
            $table->index('entry_date');
            // Retained explicitly: under answer B this becomes a prefix rather
            // than the whole key, and it is needed either way.
            $table->index(['customer_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mmd_entries');
    }
};
