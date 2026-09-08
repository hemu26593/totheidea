<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One task list per participant, auto-fed.
     *
     * THE LIST IS THE ENROLMENT, so there is no header table.
     *
     * NOT THE DAY PLAN. A day plan item is scheduled work on a specific date
     * with a time slot and carry-forward; an action item is an outstanding
     * commitment with a due date and a provenance. They are related and
     * distinct, and they never share storage.
     *
     * PROVENANCE, NOT OWNERSHIP: the polymorphic source pair explains WHY a
     * task exists - a released assignment, a weak skill area. Ownership is the
     * NOT NULL enrollment_id. A dangling source degrades an explanation; it
     * never orphans a row, which is why the pair carries no foreign key.
     */
    public function up(): void
    {
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();

            // Ownership.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            $table->string('title', 300);
            $table->text('description')->nullable();

            // Provenance. Deliberately polymorphic, deliberately no FK.
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->date('due_date')->nullable();
            // open | in_progress | done | dropped.
            $table->string('status', 16)->default('open');
            // An ORDERED LABEL, not a scoring system. There is no arithmetic
            // on this column anywhere.
            $table->string('priority', 10)->default('normal');

            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            // Actor triple: an item may be completed through an external
            // grant.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // Open actions for a participant, and the console's "who needs a
            // call this week" ranking.
            $table->index(['enrollment_id', 'status', 'due_date']);
            // "Has an action already been created for this source?" - what
            // stops the auto-feed duplicating. A lookup, NOT a unique key: a
            // legitimate manual duplicate may exist.
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
