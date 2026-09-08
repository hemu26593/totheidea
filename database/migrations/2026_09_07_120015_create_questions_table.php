<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One question, its type, its rules and its locale text.
     *
     * TWO MANDATORY PARENTS: the version it belongs to, and the section it is
     * displayed in. Both are NOT NULL. Optional behaviour was not invented
     * for convenience - every question is displayed, and every displayed
     * question sits in a section.
     *
     * The cross-row invariant that a question's section belongs to the SAME
     * version cannot be a database constraint, so FormBuilderService asserts
     * it and a test proves it.
     *
     * label_secondary and help_text_secondary are client decision S6
     * (Gujarati). Two nullable columns are what the SOW actually asks for; a
     * translations table is introduced only if the answer is system-wide
     * multi-locale.
     */
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->foreignId('form_section_id')->constrained('form_sections')->cascadeOnDelete();

            // Identifies the same logical question across templates - the SOW
            // rule that repeated questions are asked only once. Deliberately
            // NOT unique: recurrence is the whole point.
            $table->string('bank_key', 60)->nullable();

            // RESTRICT: reference data must not vanish under a question.
            $table->foreignId('skill_area_id')->nullable()->constrained('skill_areas')->restrictOnDelete();

            $table->string('type', 24);
            $table->text('label');
            $table->text('label_secondary')->nullable();
            $table->text('help_text')->nullable();
            $table->text('help_text_secondary')->nullable();
            $table->boolean('is_required')->default(false);
            // Null when the question is not numerically scored.
            $table->decimal('max_score', 6, 2)->nullable();
            // Conditional rule, read WITH the question, never queried across
            // rows - so TEXT, and no JSON functions in queries.
            $table->text('visible_when')->nullable();
            // Evaluated in PHP. Never by the database, and never by AI.
            $table->text('compute_expression')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['form_version_id', 'position']);
            $table->index(['form_section_id', 'position']);
            // "Has this business already answered this question?"
            $table->index('bank_key');
            $table->index('skill_area_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
