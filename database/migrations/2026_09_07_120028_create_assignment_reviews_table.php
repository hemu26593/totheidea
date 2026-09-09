<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One accept/return decision with its remark - THE HISTORY.
     *
     * Without this table, "returned, resubmitted, returned again, accepted"
     * collapses to "accepted" and every remark is lost.
     *
     * APPEND-ONLY AND IMMUTABLE. A changed mind is a new review on a new
     * attempt, never an edit.
     *
     * No actor triple: review is an internal-only act by construction, so
     * reviewed_by is NOT NULL and an external grant can never write here.
     */
    public function up(): void
    {
        Schema::create('assignment_reviews', function (Blueprint $table) {
            $table->id();

            // CASCADE: a review is part of its submission.
            $table->foreignId('assignment_submission_id')->constrained('assignment_submissions')->cascadeOnDelete();

            // accepted | returned.
            $table->string('decision', 16);
            $table->text('remark')->nullable();
            // Which attempt this decision judged.
            $table->unsignedSmallInteger('attempt_number');

            // ALWAYS an internal user.
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');

            $table->timestamps();

            // One decision per attempt. Two would make the current status
            // ambiguous.
            // Named explicitly: the generated name is 65 characters and
            // MySQL's identifier limit is 64.
            $table->unique(['assignment_submission_id', 'attempt_number'], 'assignment_reviews_submission_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_reviews');
    }
};
