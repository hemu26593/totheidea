<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A named form: the four intake instruments, each session's worksheets,
     * and later the AI-drafted customer-specific forms.
     *
     * customer_id NULL means a global template. Non-null means it belongs to
     * one business.
     *
     * No UNIQUE (customer_id, key): customer_id is nullable and
     * NULL-in-unique-index semantics differ between SQLite and MySQL.
     * Uniqueness of key within scope is enforced in FormBuilderService.
     */
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();

            // Stable code, e.g. business_analysis, assessment_35.
            $table->string('key', 60);
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Drives whether a scoring engine runs at all.
            $table->boolean('is_scored')->default(false);
            $table->string('status', 16)->default('active');

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
