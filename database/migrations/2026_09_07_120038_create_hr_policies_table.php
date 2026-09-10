<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR policy documents and their status tracker.
     *
     * OWNED BY THE CUSTOMER. These are the participant's own policies, not
     * the programme's.
     *
     * SUPERSEDED, NEVER EDITED IN PLACE. Once a policy is published, its text
     * is frozen and a revision is a NEW row that the old one points at through
     * superseded_by_id. Editing published text would silently change what
     * people had already signed off on, and every acknowledgement bound to it
     * would then attest to words nobody read.
     *
     * Text policies live in body; files go to the existing documents table.
     * There is deliberately no second document or versioning system here.
     */
    public function up(): void
    {
        Schema::create('hr_policies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('body')->nullable();

            // draft | published | superseded. Validated in HrPolicyService: a
            // native ENUM is not portable between SQLite and MySQL.
            $table->string('status', 16)->default('draft');
            $table->string('version_label', 30)->nullable();
            $table->timestamp('published_at')->nullable();

            // The supersession chain. SET NULL so a broken link degrades an
            // explanation rather than orphaning a policy.
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('hr_policies')->nullOnDelete();

            $table->timestamp('archived_at')->nullable();

            // Actor triple.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // The Session 6 policy status tracker.
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_policies');
    }
};
