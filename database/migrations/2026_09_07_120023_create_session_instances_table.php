<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A session AS DELIVERED TO ONE BATCH - planned date and actual date.
     *
     * Both dates exist because the SOW's calendar reports the plan against
     * what happened; a single date column would quietly overwrite the plan the
     * moment a session moved.
     *
     * Not directly customer-owned: isolation runs batch -> enrolments.
     *
     * CANCELLED, NEVER DELETED. session_attendances and assignment_instances
     * RESTRICT, so a held session cannot be erased out from under its record.
     */
    public function up(): void
    {
        Schema::create('session_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();
            $table->foreignId('session_template_id')->constrained('session_templates')->restrictOnDelete();

            $table->date('planned_date');
            // Null until the session is actually held.
            $table->date('actual_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->string('venue', 200)->nullable();

            // scheduled | in_progress | completed | cancelled. Validated by
            // SessionSchedulingService rather than by a CHECK, which is not
            // portably alterable across SQLite and MySQL.
            $table->string('status', 16)->default('scheduled');

            // "Will anyone other than you take sessions?" - the SOW allows it.
            $table->foreignId('conducted_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // [CLIENT DECISION - S5] UNIQUE (batch_id, session_template_id).
            //
            // If a session may be held only once per batch, this key belongs
            // here and protects "one session 3 per cohort". If repeat sittings
            // are allowed, it must not exist at all.
            //
            // IT IS DELIBERATELY NOT APPLIED. Adding a unique key later is
            // trivial; removing one after production data has been shaped by
            // it is not. No speculative constraint is imposed on an open
            // question.

            // The session calendar, and trigger 1 (3 days before, and that
            // morning).
            $table->index(['batch_id', 'planned_date']);
            // The upcoming-sessions sweep across every batch.
            $table->index(['status', 'planned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_instances');
    }
};
