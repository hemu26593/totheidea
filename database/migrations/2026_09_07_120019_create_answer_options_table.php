<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One selected choice for a single- or multi-select answer.
     *
     * Its own table rather than a nullable question_option_id on answers:
     * that shape would make "one answer per question" express as a unique
     * index containing NULLs, and NULL-in-unique-index semantics differ
     * between SQLite and MySQL. That is exactly the portability trap
     * ADR-005/006 warns about.
     */
    public function up(): void
    {
        Schema::create('answer_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('answer_id')->constrained('answers')->cascadeOnDelete();
            // RESTRICT: an option that has been selected cannot be removed,
            // so a historical answer never loses the meaning of its choice.
            $table->foreignId('question_option_id')->constrained('question_options')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['answer_id', 'question_option_id']);
            // "How many chose this option" - the 18-question Yes-count and
            // option-frequency reporting.
            $table->index('question_option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_options');
    }
};
