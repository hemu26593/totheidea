<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A computed NUMERIC score for a submission - overall, or per skill area.
     *
     * Persisted because the diagnostic report is generated before Session 1
     * and handed over: a later correction to a scoring rule must not
     * retroactively change what was delivered. scheme_version records which
     * rules produced the number.
     *
     * APPEND-ONLY. Recomputation appends; it never overwrites. There is
     * deliberately no unique key - one would forbid the very history this
     * table exists to keep - so "current score" is the newest computed_at,
     * resolved in application code.
     *
     * This table NEVER holds the Average / Good / Better / Best category. It
     * is numeric; the category is an answer, not a score.
     */
    public function up(): void
    {
        Schema::create('submission_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_submission_id')->constrained('form_submissions')->cascadeOnDelete();
            // NULL means the overall score rather than a per-area one.
            $table->foreignId('skill_area_id')->nullable()->constrained('skill_areas')->restrictOnDelete();

            $table->string('score_type', 30);
            $table->decimal('raw_score', 10, 2);
            $table->decimal('max_score', 10, 2);
            // Stored because it is reported; derived from the two above.
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('scheme_version', 30);
            $table->timestamp('computed_at');

            $table->timestamps();

            // Heat-map assembly.
            $table->index(['form_submission_id', 'skill_area_id']);
            // "The current score", i.e. the latest row.
            $table->index(['form_submission_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_scores');
    }
};
