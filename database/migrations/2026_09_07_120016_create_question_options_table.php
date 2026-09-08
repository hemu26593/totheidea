<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Selectable choices, including the Average / Good / Better / Best
     * scoring category.
     *
     * score_value stays NULL for every categorical option. Populating it
     * would invent the Average = 1 ... Best = 4 mapping the brief explicitly
     * forbids. The column exists for numerically-scored questions only.
     *
     * Because options belong to an immutable version, the value selected at
     * submission time is preserved automatically - no extra mechanism.
     */
    public function up(): void
    {
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();

            // Stable machine value.
            $table->string('value', 60);
            $table->string('label', 200);
            $table->string('label_secondary', 200)->nullable();
            $table->decimal('score_value', 6, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // One machine value per question: duplicates would make an answer
            // ambiguous.
            $table->unique(['question_id', 'value']);
            $table->index(['question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
