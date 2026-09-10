<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One planned/actual amount within a month's plan.
     *
     * THREE DIMENSIONS, NOT ONE FLAT CATEGORY:
     *
     *   section             where the line sits
     *                       fund_in | fund_out | marketing_budget |
     *                       sales_closing | production
     *   planning_type       how it was planned
     *                       BFP | FBP | EBP | SM
     *   due_classification  how aged a receivable is
     *                       CD | LD | LLD - exactly three
     *
     * WHY A CLASSIFICATION VALUE RATHER THAN A COLUMN PER CLASS: with
     * cd_amount / ld_amount / lld_amount, "all Long Due across months" needs a
     * UNION and any change to the vocabulary is a migration. As a value on the
     * line, the aging dimension is queryable and indexable.
     *
     * week_number handles weekly money in and out WITHOUT a third table:
     * NULL is a month-level line, 1-5 a weekly one.
     *
     * No unique constraint: a section legitimately holds several lines with
     * the same shape and different labels - BIP under sales_closing, for
     * instance.
     */
    public function up(): void
    {
        Schema::create('fund_plan_lines', function (Blueprint $table) {
            $table->id();

            // CASCADE: a line is part of its plan, not a record in its own
            // right.
            $table->foreignId('fund_plan_id')->constrained('fund_plans')->cascadeOnDelete();

            $table->string('section', 24);
            $table->string('planning_type', 4)->nullable();
            // Set ONLY on a fund_in line. Conditional cross-column validity is
            // not portably expressible as a CHECK, so FundPlanService enforces
            // it and a test proves it.
            $table->string('due_classification', 4)->nullable();

            // Free text for the line - e.g. BIP under sales_closing.
            $table->string('label', 200)->nullable();
            // NULL = a month-level line; 1-5 = a weekly one.
            $table->unsignedTinyInteger('week_number')->nullable();

            $table->decimal('planned_amount', 14, 2)->nullable();
            $table->decimal('actual_amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // Section-by-section rendering of a month.
            $table->index(['fund_plan_id', 'section']);
            // "All Long Due across months" - the query the classification
            // dimension exists to make possible.
            $table->index('due_classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_plan_lines');
    }
};
