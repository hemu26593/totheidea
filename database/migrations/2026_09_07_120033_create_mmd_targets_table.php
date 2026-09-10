<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A target for one metric over one period.
     *
     * ROWS, NOT COLUMNS, because targets are sparse - one may be set for sales
     * and not for production - and their periods vary.
     *
     * OWNED BY THE ENROLMENT. Targets are programme-run-specific; the actuals
     * they are compared against are not, which is why mmd_entries is owned by
     * the customer and this is not.
     *
     * Target-versus-actual is DERIVED. Nothing computed is stored here.
     */
    public function up(): void
    {
        Schema::create('mmd_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            // Mirrors the mmd_entries measure set exactly. Validated in
            // MmdTargetService against that same list, so the two cannot
            // drift.
            $table->string('metric', 30);
            // daily | weekly | monthly.
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 14, 2);

            // No actor triple: setting a target is an internal administrative
            // act, and set_by records it. An external grant can never write
            // here by construction.
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('set_at');

            $table->timestamps();

            // One target per metric per period. Two would make every
            // target-versus-actual comparison ambiguous.
            $table->unique(['enrollment_id', 'metric', 'period_type', 'period_start']);
            // Serves the target-versus-actual join directly.
            $table->index(['enrollment_id', 'metric', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mmd_targets');
    }
};
