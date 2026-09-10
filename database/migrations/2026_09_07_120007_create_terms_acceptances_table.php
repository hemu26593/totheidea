<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SOW section 4.2 module 1 requires terms accepted on screen "with name, date
     * and time recorded". The login went away; the legal record did not.
     *
     * IMMUTABLE. There is no update path - a correction is a new row against a
     * new terms version. The model enforces this.
     */
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            $table->string('terms_version', 30);
            // Typed by the acceptor, as the SOW requires.
            $table->string('accepted_name', 150);
            $table->timestamp('accepted_at');
            $table->string('accepted_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Actor triple. Almost always external_grant - this is the clearest
            // case of an externally-sourced write.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // One acceptance per enrolment per version of the terms. A
            // re-issued version legitimately produces a second row.
            $table->unique(['enrollment_id', 'terms_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
    }
};
