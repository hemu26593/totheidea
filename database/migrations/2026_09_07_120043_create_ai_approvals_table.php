<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The approval decision on a generation (Step 3B, table 42).
     *
     * WHY THIS IS ITS OWN TABLE. ADR-014 requires that whoever generated an
     * AI artifact cannot approve it. That rule is only expressible if
     * generation and approval are DISTINCT RECORDS with DISTINCT ACTORS.
     * Folding decided_by onto ai_generations would make the comparison a
     * self-comparison on one row, and far easier to bypass by accident.
     *
     * The comparison itself (decided_by != generated_by, invariant I9) is
     * cross-table and therefore cannot be a database constraint. It lives in
     * AiApprovalService AND on this model - not only in a policy, because
     * `approve` has to be in authorization.guarded_abilities or Gate::before
     * would hand a Super Admin the very bypass this table exists to prevent.
     *
     * IMMUTABLE. A reversal is a new generation, not a second approval.
     */
    public function up(): void
    {
        Schema::create('ai_approvals', function (Blueprint $table) {
            $table->id();

            // CASCADE: an approval is part of its generation and has no
            // meaning without it.
            $table->foreignId('ai_generation_id')->constrained('ai_generations')->cascadeOnDelete();

            // approved | rejected. Validated in application code for
            // portability.
            $table->string('decision', 16);

            // RESTRICT: the deciding user is the evidence of who decided.
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('decided_at');

            $table->text('remark')->nullable();

            $table->timestamps();

            // ONE DECISION PER GENERATION. This is what keeps "was this
            // approved?" answerable with a single row rather than a history
            // that has to be interpreted.
            $table->unique('ai_generation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_approvals');
    }
};
