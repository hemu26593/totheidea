<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 16 skill areas behind the heat map.
     *
     * Reference data the client can rename, which is why it is a table and
     * not an enum: canonical vocabulary the code branches on belongs in an
     * enum, reference data the client edits belongs in a table.
     *
     * Created before the form engine because questions.skill_area_id
     * references it.
     */
    public function up(): void
    {
        Schema::create('skill_areas', function (Blueprint $table) {
            $table->id();

            // Stable machine key, so the client can rename `name` without
            // breaking scoring or historical reports.
            $table->string('key', 60)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_areas');
    }
};
