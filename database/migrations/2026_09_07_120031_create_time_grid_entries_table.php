<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quarterly planned-versus-actual allocation, Q1-Q4.
     *
     * STRATEGIC ALLOCATION, NOT A TASK LIST. This table is distinct from
     * day_plan_items by design and by decision: quarterly strategic allocation
     * versus daily execution. It has no task, no date, no status and no
     * carry-forward, and it never acquires them.
     *
     * Amendable with audit - actuals arrive after planning, which is the whole
     * point of holding planned and actual side by side.
     */
    public function up(): void
    {
        Schema::create('time_grid_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            $table->unsignedSmallInteger('year');
            // Q1-Q4. Range validated in TimeGridService: a CHECK on a small
            // integer is not portably alterable between SQLite and MySQL.
            $table->unsignedTinyInteger('quarter');
            $table->string('activity', 200);

            $table->decimal('planned_hours', 7, 2)->nullable();
            $table->decimal('actual_hours', 7, 2)->nullable();

            // Actor triple.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // Protects against duplicate grid cells: two rows for the same
            // activity in the same quarter would double-count planned hours.
            $table->unique(['enrollment_id', 'year', 'quarter', 'activity']);
            // Grid retrieval. A prefix of the unique key, so MySQL serves it
            // from that key; declared for clarity of intent.
            $table->index(['enrollment_id', 'year', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_grid_entries');
    }
};
