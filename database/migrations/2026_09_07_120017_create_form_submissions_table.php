<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One completed - or in-progress - form for one enrolment.
     *
     * BINDS TO THE VERSION, NEVER THE TEMPLATE. That single choice is what
     * makes historical submissions stable: publishing version 2 cannot change
     * what a version 1 submission was asked.
     *
     * No UNIQUE (enrollment_id, form_version_id): SOW section 9.2 q12 contemplates
     * repeating the 35-question assessment, and session worksheets may be
     * filled more than once. Duplicate prevention, where wanted, is a
     * service-level rule.
     */
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();

            // RESTRICT: a version that has been answered can never be removed.
            $table->foreignId('form_version_id')->constrained('form_versions')->restrictOnDelete();
            // Semantic owner.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            // Redundant, defence in depth: a single missed join cannot leak
            // another customer's data.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            $table->string('status', 16)->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            // Distinct from updated_at: updated_at records that a row changed,
            // never what or why.
            $table->timestamp('amended_at')->nullable();

            // Actor triple - this is the primary externally-written table.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // Trigger 2 ("intake forms not filled") and the "asked only once"
            // resolution.
            $table->index(['enrollment_id', 'form_version_id']);
            $table->index(['customer_id', 'status']);
            // The draft-resumption list.
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
