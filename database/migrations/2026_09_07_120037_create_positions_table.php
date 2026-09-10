<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The PARTICIPANT'S OWN org hierarchy - position list, role, KRA.
     *
     * OWNED BY THE CUSTOMER, not by an enrolment. An org chart is continuous
     * business data: it outlives any single programme run, in the same way MMD
     * figures do.
     *
     * holder_name IS FREE TEXT AND MUST STAY THAT WAY. A staff member of the
     * participant's business is NOT a platform user and gets no account. A
     * foreign key to users here would manufacture exactly the customer login
     * the architecture forbids, so the column is a string and a test asserts
     * no such key exists.
     *
     * Archive-only.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // Self-referencing hierarchy. SET NULL because removing a manager
            // must not delete the people who reported to them - the reports
            // become unparented, not deleted.
            $table->foreignId('parent_position_id')->nullable()
                ->constrained('positions')->nullOnDelete();

            $table->string('title', 150);
            // FREE TEXT. Never a foreign key to users.
            $table->string('holder_name', 150)->nullable();
            $table->text('role_description')->nullable();
            // Key result areas.
            $table->text('kra')->nullable();

            $table->timestamp('archived_at')->nullable();

            // Actor triple.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // No unique key on title: two positions may legitimately share
            // one - two Area Managers, for instance.

            // Walking the org tree, which is always scoped to one business.
            $table->index(['customer_id', 'parent_position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
