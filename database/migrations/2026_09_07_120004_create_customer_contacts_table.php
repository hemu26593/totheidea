<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People at the business.
     *
     * Under the no-login decision these are the ONLY delivery address for
     * eight of the nine SOW reminders, which is why consent is per channel.
     *
     * No UNIQUE (customer_id, email): email is nullable, and NULL-in-unique-index
     * semantics differ between SQLite and MySQL. Duplicate prevention is a
     * service-level check.
     */
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();

            // CASCADE: a contact has no meaning apart from its business. Safe
            // because customers are never deleted - it can only fire in a
            // genuine teardown.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('role_title', 100)->nullable();
            $table->string('email', 255)->nullable();
            // E.164 so WhatsApp dispatch needs no normalisation.
            $table->string('phone_e164', 20)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('email_opt_in')->default(true);
            // Fails closed: WhatsApp is billed to the client and is
            // consent-sensitive.
            $table->boolean('whatsapp_opt_in')->default(false);

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // "The primary contact for this business" - default recipient
            // resolution on every trigger.
            $table->index(['customer_id', 'is_primary']);
            // Inbound lookup when a reply or bounce arrives.
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
