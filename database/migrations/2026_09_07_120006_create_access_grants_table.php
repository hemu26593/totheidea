<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scoped, expiring CAPABILITY issued to a customer contact.
     *
     * No account, no password, no session. This is what replaces SOW section 4.2
     * module 1 under the no-login decision, and it is why eight of nine
     * reminders can address someone who can actually act on them.
     *
     * The grant carries a capability, not an identity: ability + subject mean
     * "complete this form for this enrolment", never "access customer X".
     *
     * Only the SHA-256 hash of the token is stored. The plaintext exists solely
     * in the emailed link - a leaked table grants nothing, the same reasoning
     * as password hashes.
     *
     * Created in Phase 1 even though redemption ships in Phase 5, because
     * twelve later tables carry access_grant_id and SQLite cannot add a foreign
     * key to an existing table. The audit_logs extension that follows this
     * migration depends on it for the same reason.
     */
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();

            // SHA-256 hex. Unique both to prevent collision and because it is
            // the redemption lookup - by hash, in constant time, never by
            // plaintext and never with a LIKE.
            $table->char('token_hash', 64)->unique();

            $table->string('ability', 32);

            // The specific form version / assignment instance / date. No
            // database FK: the subject is polymorphic. Application-enforced.
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');

            // Both non-null: together they are the isolation boundary.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            // SET NULL: the grant outlives the contact as evidence of what was
            // permitted.
            $table->foreignId('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();

            // Fails closed.
            $table->boolean('single_use')->default(true);
            $table->unsignedSmallInteger('max_uses')->default(1);
            $table->unsignedSmallInteger('use_count')->default(0);

            // Always an internal user: a grant is issued, never self-created.
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            // Always set. There are no open-ended grants.
            $table->timestamp('expires_at');

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revoke_reason', 255)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->timestamps();

            // "Active grants for this business", and the expiry sweep.
            $table->index(['customer_id', 'expires_at']);
            // "Who currently holds a grant on this subject".
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
