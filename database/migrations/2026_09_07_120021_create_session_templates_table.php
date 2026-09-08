<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sessions 1-6 as ROWS, not as code.
     *
     * The curriculum is identical across batches, so it is described once here
     * and instantiated per batch in session_instances. Encoding "session 3 is
     * the finance session" in PHP would make a curriculum change a deployment.
     *
     * Not customer-owned: a template belongs to the program.
     */
    public function up(): void
    {
        Schema::create('session_templates', function (Blueprint $table) {
            $table->id();

            // RESTRICT: a program whose curriculum exists cannot be deleted.
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('title', 200);
            $table->string('theme', 200)->nullable();
            $table->text('objectives')->nullable();

            // Archive-only: session_instances RESTRICT, so a delivered
            // curriculum row survives its own retirement.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Protects "one session 3 per curriculum". Two would make the
            // calendar and every reminder ambiguous. Also the ordered read.
            $table->unique(['program_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_templates');
    }
};
