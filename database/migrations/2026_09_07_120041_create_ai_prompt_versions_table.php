<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned prompts (Step 3B, table 40).
     *
     * WHY VERSION A PROMPT AT ALL: an AI output that cannot be traced to the
     * exact instruction that produced it cannot be explained months later,
     * and "why did it suggest that?" is the first question anyone asks. A
     * generation names one prompt version, which is why that identity has to
     * be unambiguous.
     *
     * NOT CUSTOMER DATA. A prompt is system vocabulary, like a form template.
     * It carries PLACEHOLDERS ONLY - never a business's name, figures or
     * text. Context is assembled at call time from source-of-truth entities
     * scoped by customer_id (table 41), so there is no path by which one
     * business's content becomes another's prompt.
     *
     * IMMUTABLE ONCE PUBLISHED, enforced on the model. Editing a published
     * prompt would silently rewrite the provenance of every generation
     * already pointing at it.
     */
    public function up(): void
    {
        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->id();

            // form_draft | diagnostic_narrative | ... The stable name of the
            // prompt; version_number carries the history.
            $table->string('key', 60);

            $table->unsignedSmallInteger('version_number');

            // The prompt text. Placeholders only - see the class docblock.
            $table->text('template');

            // Provenance: which model this prompt was written and tested
            // against. Nullable because the configured default may be used.
            $table->string('model_identifier', 100)->nullable();

            // The JSON schema the output must validate against before it is
            // persisted as validated_output. Nullable for prompts whose
            // output is prose rather than structure.
            $table->text('output_schema')->nullable();

            // draft | published | archived. Validated in application code: a
            // native ENUM is not portable between SQLite and MySQL, and a
            // CHECK constraint would have to be dropped and recreated to add
            // a value.
            $table->string('status', 16)->default('draft');

            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // Unambiguous provenance: two rows claiming to be version 3 of
            // the same key would make every generation naming it unreadable.
            $table->unique(['key', 'version_number']);

            // "Which prompt do I use right now?" - the resolution every
            // generation starts with.
            $table->index(['key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_versions');
    }
};
