<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AI invocation, with full provenance (Step 3B, table 41):
     * what was asked, what the model saw, and what came back.
     *
     * customer_id IS THE ISOLATION BOUNDARY, and it is NOT NULL. There is no
     * unscoped generation. Making it a required foreign key is what turns
     * cross-customer leakage from "unlikely" into "unrepresentable" - a
     * generation cannot exist without naming whose business it is about.
     *
     * NO customer_ai_data TABLE. Context is assembled at call time by
     * CustomerContextAssembler, reading only source-of-truth entities already
     * scoped to that customer. A dumping table would be a second, stale
     * source of truth and would turn a leak into a silent data bug rather
     * than an impossible query.
     *
     * input_context IS WRITTEN ONCE AND NEVER READ BACK INTO BUSINESS LOGIC.
     * It exists so an output can be explained; a test asserts that nothing
     * reads a figure out of it.
     *
     * NO ACTOR TRIPLE. AI generation is always initiated by an internal user;
     * an external access grant can never invoke it, which is why generated_by
     * is NOT NULL and there is no source/access_grant_id pair here.
     *
     * NEVER REMOVED. A failed generation is retained - a failure is
     * provenance too, and deleting it would hide the one case worth
     * reviewing.
     */
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();

            // Scope. NOT NULL - there is no unscoped generation.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // RESTRICT: a prompt version referenced by a generation can never
            // be removed, or the generation stops being explicable.
            $table->foreignId('ai_prompt_version_id')->constrained('ai_prompt_versions')->restrictOnDelete();

            // form_draft | narrative | summary.
            $table->string('purpose', 60);

            // The snapshot of what the model actually saw. Required.
            $table->text('input_context');

            // Exactly as returned, before any interpretation.
            $table->text('raw_output')->nullable();

            // Set ONLY once schema validation passes. Its presence is the
            // record that the output was structurally acceptable.
            $table->text('validated_output')->nullable();

            // pending | succeeded | failed | awaiting_approval | approved |
            // rejected. Validated in application code for portability.
            $table->string('status', 24)->default('pending');

            $table->text('validation_error')->nullable();

            // The DRAFT form version this generation produced, if any. SET
            // NULL rather than RESTRICT: the generation record outlives any
            // particular draft, and provenance must not block cleanup of the
            // form engine.
            $table->foreignId('resulting_form_version_id')->nullable()
                ->constrained('form_versions')->nullOnDelete();

            // The internal user who initiated it. NOT NULL - see the class
            // docblock on the missing actor triple. This is one half of the
            // pair the self-approval invariant compares.
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('generated_at');

            $table->unsignedInteger('tokens_used')->nullable();

            $table->timestamps();

            // NO UNIQUE CONSTRAINT. Repeated generation is legitimate and
            // each attempt is its own provenance record.

            // The approval queue, always scoped to one business.
            $table->index(['customer_id', 'status']);

            // The pending / awaiting-approval sweep.
            $table->index(['status', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
