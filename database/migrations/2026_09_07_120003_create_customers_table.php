<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The participant's BUSINESS. Not a user, not an account (ADR-003/004).
     *
     * This table is the root of the customer-owned tree and the isolation
     * boundary every other customer-owned table resolves through.
     *
     * There is deliberately NO credential column of any kind here - no
     * password, no remember token, no Authenticatable. Customers do not
     * authenticate; external access is a scoped, expiring grant instead.
     * Adding a credential column is an architecture change requiring an ADR,
     * not a migration.
     *
     * The projected profile columns Step 3B option D describes are NOT added
     * here: which attributes get projected is client decision S2, and no
     * column is invented ahead of that answer.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 200);
            // Human-quotable reference. The business NAME is deliberately not
            // unique - two distinct businesses may share a trading name.
            $table->string('code', 30)->unique();
            $table->string('status', 16)->default('prospect');

            // Archive, never delete (ADR-011).
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
