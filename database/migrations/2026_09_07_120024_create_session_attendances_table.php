<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance for one enrolment at one session instance.
     *
     * The 90% rule is COMPUTED FROM THESE ROWS AND NEVER STORED. A stored
     * percentage is a number that silently goes stale the moment a mark is
     * amended, and it is a contractual completion figure.
     *
     * How `late` and `excused` weigh in that calculation is
     * [CLIENT DECISION - ATTENDANCE WEIGHTING]. It affects the FORMULA, not
     * this schema: all four statuses are stored either way.
     *
     * Amendable with audit; never deleted.
     */
    public function up(): void
    {
        Schema::create('session_attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_instance_id')->constrained('session_instances')->restrictOnDelete();
            // Semantic owner - isolation resolves through the enrolment spine.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            // Redundant, defence in depth: a single missed join cannot leak
            // another customer's register.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // present | absent | late | excused. Validated in AttendanceService.
            $table->string('status', 16);

            $table->timestamp('marked_at');
            // Null when marked externally through a grant rather than by staff.
            $table->foreignId('marked_by')->nullable()->constrained('users')->restrictOnDelete();

            // [CLIENT DECISION - S4] consultant | participant_code | venue_qr.
            // Nullable with NO DEFAULT: assuming "consultant" would record a
            // marking method nobody confirmed. Dropped if the answer is
            // "consultant only".
            $table->string('marking_method', 20)->nullable();

            $table->timestamp('amended_at')->nullable();

            // Actor triple: attendance may be marked externally.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // DOUBLE-MARKING PREVENTION. Two marks for one participant at one
            // session would silently corrupt the 90% figure and mis-fire
            // trigger 8, so this is a database constraint, not a service rule.
            $table->unique(['session_instance_id', 'enrollment_id']);
            // The live 90% calculation and the reachability warning.
            $table->index(['enrollment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_attendances');
    }
};
