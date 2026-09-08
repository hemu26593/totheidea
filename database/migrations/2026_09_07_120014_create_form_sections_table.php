<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Display grouping of questions within a version.
     *
     * CASCADE from the version: a section has no meaning apart from it.
     */
    public function up(): void
    {
        Schema::create('form_sections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_version_id')->constrained('form_versions')->cascadeOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // Deterministic rendering order: two sections at the same
            // position render non-deterministically across engines.
            $table->unique(['form_version_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_sections');
    }
};
