<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A participant's work against ONE RELEASED ASSIGNMENT.
     *
     * TWO MANDATORY PARENTS, and neither alone identifies a submission:
     *
     *   assignment_instance_id - which released work is being answered
     *   enrollment_id          - whose work it is
     *
     * Both foreign keys are NOT NULL by design. The two must also resolve to
     * the same batch, which is a multi-hop rule
     * (instance -> session_instance -> batch versus enrollment -> batch) that
     * no foreign key can express; AssignmentSubmissionService asserts it.
     *
     * RESUBMISSION ADVANCES attempt_number. It does not create a second row -
     * that is what keeps the assignment status report truthful.
     */
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assignment_instance_id')->constrained('assignment_instances')->restrictOnDelete();
            // Semantic owner.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            // Redundant, defence in depth.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // not_started | in_progress | submitted | returned | accepted.
            $table->string('status', 16)->default('not_started');
            // Free-text answer; attachments go to documents.
            $table->text('body')->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->timestamp('submitted_at')->nullable();

            // Actor triple: submitted externally through a submit_assignment
            // grant.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // One submission record per participant per released assignment.
            // Named explicitly: the generated name is 66 characters and
            // MySQL's identifier limit is 64.
            $table->unique(['assignment_instance_id', 'enrollment_id'], 'assignment_submissions_instance_enrollment_unique');
            // The participant workspace, and trigger 4 (overdue).
            $table->index(['enrollment_id', 'status']);
            // The global scope.
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
