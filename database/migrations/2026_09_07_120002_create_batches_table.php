<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One run of a program - a cohort with dates.
     *
     * No constraint prevents overlapping batch dates: concurrent batches are a
     * confirmed product requirement.
     */
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();

            // RESTRICT: a program with runs cannot be deleted.
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();

            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // Null means uncapped. Enforced at enrolment time, not by the
            // database, because it is a count across rows.
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 16)->default('planned');

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // "Batches for this program, most recent first" - the batch picker
            // and the Monday roll-up. No index on status: low cardinality on a
            // small table is pure write cost.
            $table->index(['program_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
