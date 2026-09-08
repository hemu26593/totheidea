<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commentary on any subject, including the consultant's private notes
     * that participants must never see (SOW module 14).
     *
     * is_internal defaults to TRUE and that direction is deliberate: SOW
     * section 6 requires participant isolation be "built in, not a setting", so
     * forgetting the flag must over-restrict rather than leak.
     *
     * There is NO actor triple here. Notes are internal-only by construction -
     * author_id is always a user, and an external grant can never create one.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();

            $table->string('notable_type', 100);
            $table->unsignedBigInteger('notable_id');

            $table->text('body');

            // Fails closed.
            $table->boolean('is_internal')->default(true);

            // Notes are authored by staff only. RESTRICT so the trail stays
            // attributable.
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();

            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            // Mandatory polymorphic lookup.
            $table->index(['notable_type', 'notable_id']);
            // Visibility filtering on every external-facing read.
            $table->index('is_internal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
