<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An IMMUTABLE revision of a template.
     *
     * This is what preserves the meaning of an answer given in January
     * against a question reworded in March. A submission binds to a version,
     * never to a template, so rewording later cannot rewrite what was asked.
     *
     * "At most one published version per template" is NOT a partial unique
     * index - those are not portable - so it is enforced in
     * FormPublishingService and covered by a test.
     */
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();

            // RESTRICT: a template with revisions cannot be deleted.
            $table->foreignId('form_template_id')->constrained('form_templates')->restrictOnDelete();

            $table->unsignedSmallInteger('version_number');
            $table->string('status', 16)->default('draft');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();

            // Which scoring rules applied at publication.
            $table->string('scoring_scheme_version', 30)->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Two rows claiming to be "version 2" would make a submission's
            // provenance unresolvable.
            $table->unique(['form_template_id', 'version_number']);
            // "The current published version of this template" - the hottest
            // read in the form engine.
            $table->index(['form_template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};
