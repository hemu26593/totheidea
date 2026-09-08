<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files attached to any subject - assignment attachments, HR policy
     * files, uploaded workbooks.
     *
     * The subject is polymorphic, so isolation is resolved in application
     * code: a polymorphic column cannot carry a foreign key. That is exactly
     * why is_internal defaults to true - the failure mode of forgetting the
     * flag is an over-restricted document rather than a leaked one.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Attachment, not ownership. No database foreign key by design;
            // the subject's customer is resolved and asserted in the service.
            $table->string('documentable_type', 100);
            $table->unsignedBigInteger('documentable_id');

            $table->string('disk', 30)->default('local');
            // Never a public URL.
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            // Integrity of what was delivered.
            $table->char('checksum_sha256', 64)->nullable();

            // Fails closed.
            $table->boolean('is_internal')->default(true);

            $table->timestamp('archived_at')->nullable();

            // Actor triple.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // Mandatory: the only way to retrieve a subject's attachments.
            $table->index(['documentable_type', 'documentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
