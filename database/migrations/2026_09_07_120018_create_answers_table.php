<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One typed value for one question in one submission.
     *
     * Typed columns rather than JSON: scoring aggregates answers, the heat map
     * groups them, and reports filter on them. JSON would push all three into
     * application memory.
     *
     * "Exactly one value_* populated, matching the question's type" depends on
     * questions.type, so it is a cross-table rule and is asserted in
     * SubmissionService rather than by a CHECK.
     */
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();

            // CASCADE: an answer is part of its submission.
            $table->foreignId('form_submission_id')->constrained('form_submissions')->cascadeOnDelete();
            // RESTRICT: a question that has been answered cannot be removed.
            $table->foreignId('question_id')->constrained('questions')->restrictOnDelete();

            $table->text('value_text')->nullable();
            // Wider scale than money - this holds ratings and quantities too.
            $table->decimal('value_number', 14, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();

            $table->timestamps();

            // One answer per question per submission. Both columns are NOT
            // NULL, so this unique key is portable.
            $table->unique(['form_submission_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
