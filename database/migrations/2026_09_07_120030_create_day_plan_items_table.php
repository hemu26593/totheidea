<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One task on one day, with time slot, status and carry-forward.
     *
     * DAILY EXECUTION. This is NOT the Time Grid: that is Q1-Q4 strategic
     * allocation, lives in its own table, and shares no storage and no
     * semantics with this one.
     *
     * No parent header table. A plan is identified by participant and date,
     * and a row holding only that pair would carry no state of its own.
     *
     * CARRY-FORWARD WRITES A NEW ROW. The original stays on its own date
     * marked carried_forward and the new row points back through
     * carried_from_id. Mutating plan_date in place would be simpler and wrong:
     * yesterday would retroactively appear to have contained no unfinished
     * work, and the chain showing how many times a task slipped - the actual
     * coaching signal - would vanish.
     */
    public function up(): void
    {
        Schema::create('day_plan_items', function (Blueprint $table) {
            $table->id();

            // Semantic owner.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            // Redundant, defence in depth.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            $table->date('plan_date');
            $table->string('task', 500);

            // The time slot. Deliberately no unique key: a participant may
            // legitimately plan several tasks in the same slot on one day.
            $table->time('planned_start')->nullable();
            $table->time('planned_end')->nullable();
            $table->unsignedSmallInteger('actual_minutes')->nullable();

            // planned | done | carried_forward. Validated in DayPlanService.
            $table->string('status', 20)->default('planned');

            // Self-referencing: the slip chain. SET NULL because a broken
            // chain degrades an explanation and must never orphan a row.
            $table->foreignId('carried_from_id')->nullable()
                ->constrained('day_plan_items')->nullOnDelete();

            // [CLIENT DECISION - S1] The three columns the SOW places after
            // Tasks on the Day Plan sheet. The COUNT is settled at three, so
            // the table structure is settled; only the individual business
            // labels are outstanding.
            //
            // Carried EXACTLY as supplied - not expanded, not renamed, not
            // guessed. The type is provisional: if a label turns out to denote
            // a flag rather than a value, VARCHAR(60) becomes BOOLEAN. That is
            // a type change on three columns, not a redesign.
            $table->string('g', 60)->nullable();
            $table->string('c', 60)->nullable();
            $table->string('m', 60)->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            // Actor triple: a day plan may be entered through an
            // enter_day_plan grant.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // The day plan itself, and the nightly carry-forward sweep.
            $table->index(['enrollment_id', 'plan_date']);
            // The global scope.
            $table->index(['customer_id', 'plan_date']);
            // Walking the slip chain.
            $table->index('carried_from_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_plan_items');
    }
};
