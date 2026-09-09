<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which forms and worksheets belong to which session.
     *
     * The junction is what lets the session workspace render "this session's
     * instruments" without a hard-coded map, and it is what trigger 2
     * ("intake forms not filled") reads to know which forms are required.
     */
    public function up(): void
    {
        Schema::create('session_template_forms', function (Blueprint $table) {
            $table->id();

            // CASCADE: a junction row is part of its session, not a record in
            // its own right.
            $table->foreignId('session_template_id')->constrained('session_templates')->cascadeOnDelete();
            // RESTRICT: a form in use by a curriculum cannot be removed.
            $table->foreignId('form_template_id')->constrained('form_templates')->restrictOnDelete();

            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // Protects "a form is attached to a session once". A duplicate
            // would double-count in completion and fire trigger 2 twice.
            // Named explicitly. The generated name would be
            // session_template_forms_session_template_id_form_template_id_unique
            // at 66 characters, and MySQL rejects any identifier over 64
            // (ERROR 1059), so the migration would fail on the production
            // database while succeeding on SQLite (ADR-006).
            $table->unique(['session_template_id', 'form_template_id'], 'session_template_forms_unique');
            // Reverse lookup: "which sessions use this form".
            $table->index('form_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_template_forms');
    }
};
