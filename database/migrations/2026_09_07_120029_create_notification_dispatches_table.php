<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One record per ATTEMPTED send, across the nine SOW section 7 triggers.
     *
     * THE TRIGGERS THEMSELVES ARE CODE CONSTANTS, NOT ROWS. None is invented
     * and none is user-configurable in v1, so there is no triggers table.
     *
     * Table 38 in the frozen order, built here rather than after tables 28-37
     * because the notification architecture is Phase 5 work and nothing in it
     * depends on the trackers.
     *
     * Owned by the system, with customer/enrolment attribution.
     * customer_id and enrollment_id are NULLABLE because trigger 9 (weekly
     * batch summary) addresses an internal user about a batch, not a customer.
     *
     * Append-only. Every attempt is a row, including failed and suppressed - a
     * send withheld for lack of consent is RECORDED, never silently skipped.
     */
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();

            $table->string('trigger_key', 60);
            // email | whatsapp. Validated in application code: a native ENUM
            // is not portable between SQLite and MySQL.
            $table->string('channel', 16);

            // contact | user. Polymorphic with NO foreign key: recipients are
            // customer_contacts or internal users, and participants have no
            // account, so there is no third kind.
            $table->string('recipient_type', 16);
            $table->unsignedBigInteger('recipient_id');
            // SNAPSHOT. A contact's address may change after the send; the
            // record must say where the message actually went.
            $table->string('address_used', 255);

            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();

            // What the message is about. Polymorphic; no FK.
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->timestamp('scheduled_for');
            $table->timestamp('sent_at')->nullable();
            // pending | sent | failed | suppressed.
            $table->string('status', 16)->default('pending');

            $table->string('provider_message_id', 150)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();

            // LOAD-BEARING: trigger + subject + period + recipient.
            //
            // Triggers 2, 4, 5, 6, 7 and 8 describe conditions that STAY TRUE
            // - attendance below 90% is continuously true once crossed.
            // Without an idempotency key every scheduler tick re-sends and the
            // system becomes the thing participants mute. The unique index
            // makes double-sending impossible rather than unlikely.
            //
            // VARCHAR(190): MySQL's utf8mb4 index prefix limit is 191
            // characters, so a longer column could not be uniquely indexed on
            // the production engine.
            $table->string('dedupe_key', 190)->unique();

            $table->timestamps();

            // The send queue, polled continuously.
            $table->index(['status', 'scheduled_for']);
            // "What have we sent this business?" - consultant console and
            // complaint handling.
            $table->index(['customer_id', 'trigger_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
