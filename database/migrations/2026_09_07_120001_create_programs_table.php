<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A curriculum. BMP Months 1-2 is the first; SOW section 8 defers
     * Sessions 7-18 to "a later phase", which is a second curriculum in all
     * but name.
     *
     * System-owned reference data. Archive-only: batches RESTRICT, so an
     * attempted delete raises rather than sweeping a programme's history.
     */
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            // Stable handle used by reports and seed data.
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('session_count');
            $table->string('status', 16)->default('active');

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
