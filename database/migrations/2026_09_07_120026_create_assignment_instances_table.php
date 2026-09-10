<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An assignment RELEASED TO A BATCH, with a due date and a wording
     * snapshot.
     *
     * title and instructions are copied from the template AT RELEASE and never
     * re-read afterwards. A running batch keeps the assignment text it was
     * actually given, without a full versioning system on top of the template.
     *
     * Without this level there is no per-batch due date, so reminders 3 and 4
     * have nothing to fire on.
     *
     * NO UNIQUE (session_instance_id, assignment_template_id): the template is
     * nullable for ad-hoc assignments, and a re-run may legitimately release
     * the same template twice.
     *
     * Closed, never deleted.
     */
    public function up(): void
    {
        Schema::create('assignment_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_instance_id')->constrained('session_instances')->restrictOnDelete();
            // Nullable: an ad-hoc assignment with no template is legitimate.
            $table->foreignId('assignment_template_id')->nullable()->constrained('assignment_templates')->restrictOnDelete();

            // SNAPSHOT AT RELEASE - deliberately not a join to the template.
            $table->string('title', 200);
            $table->text('instructions')->nullable();

            // Null while staged.
            $table->timestamp('released_at')->nullable();
            $table->dateTime('due_at');
            $table->boolean('requires_attachment')->default(false);

            // draft | released | closed. Validated in AssignmentReleaseService.
            $table->string('status', 16)->default('draft');

            $table->timestamps();

            // Triggers 3 and 4 sweep by date across every batch - the most
            // frequent scheduled query in the system.
            $table->index('due_at');
            // The session workspace listing.
            $table->index(['session_instance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_instances');
    }
};
