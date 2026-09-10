<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One month's fund plan header for one enrolment.
     *
     * PLANNING, NOT ACCOUNTING. There is no ledger here, no invoice, no
     * payment and no reconciliation with a bank: a fund plan records what the
     * business intends to take in and pay out, and what actually happened
     * against that intention.
     *
     * UNIQUE (enrollment_id, year, month) is what preserves history: each
     * month is its own row set and is never overwritten by the next one.
     */
    public function up(): void
    {
        Schema::create('fund_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            $table->unsignedSmallInteger('year');
            // 1-12. Range validated in FundPlanService.
            $table->unsignedTinyInteger('month');

            // draft | approved.
            $table->string('status', 16)->default('draft');
            // The SOW's "month budget".
            $table->decimal('budget_total', 14, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            // Approval is internal and is its own permission: Staff holds
            // fund_plans.manage but not fund_plans.approve.
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // Two plans for one month would make "the plan for March"
            // ambiguous, and would let a second draft quietly replace an
            // approved month.
            $table->unique(['enrollment_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_plans');
    }
};
