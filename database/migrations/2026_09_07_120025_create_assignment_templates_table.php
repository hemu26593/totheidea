<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reusable assignment definition attached to a session template.
     *
     * Without this level the same assignment is retyped for every batch and
     * drifts, which makes "what were they actually asked?" unanswerable a year
     * later.
     *
     * Not customer-owned. Archive-only; assignment_instances RESTRICT.
     */
    public function up(): void
    {
        Schema::create('assignment_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_template_id')->constrained('session_templates')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('instructions')->nullable();
            // An OFFSET, not a date. The real due date lives on the instance,
            // because only a released assignment has one.
            $table->unsignedSmallInteger('default_due_days')->nullable();
            $table->boolean('requires_attachment')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Deterministic ordering of a session's assignments.
            $table->unique(['session_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_templates');
    }
};
