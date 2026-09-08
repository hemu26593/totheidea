<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staff member's sign-off on a policy.
     *
     * WHO ACKNOWLEDGED IS A POSITION, NOT A USER. The person signing works for
     * the participant's business and has no platform account; the position
     * identifies them and acknowledged_name records what they typed. That is
     * the whole reason position_id exists here rather than a user_id.
     *
     * IMMUTABLE. A sign-off is evidence, and evidence that can be edited
     * afterwards is not evidence. A re-acknowledgement belongs to a NEW policy
     * version, which is what supersession is for.
     *
     * Isolation is inherited through the policy. The position and the policy
     * must resolve to the same customer - a multi-hop rule that no foreign key
     * can express, so HrPolicyAcknowledgementService asserts it (I19).
     */
    public function up(): void
    {
        Schema::create('hr_policy_acknowledgements', function (Blueprint $table) {
            $table->id();

            // CASCADE: an acknowledgement is part of its policy. Safe because
            // policies are archived and superseded, never deleted - so this
            // can only fire in a genuine teardown.
            $table->foreignId('hr_policy_id')->constrained('hr_policies')->cascadeOnDelete();
            // RESTRICT: the acknowledging position must remain resolvable, or
            // the compliance record stops saying who signed.
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();

            // Typed at sign-off. A name, not a credential.
            $table->string('acknowledged_name', 150);
            $table->timestamp('acknowledged_at');

            // Actor triple: a sign-off may arrive through a scoped link.
            $table->string('source', 16)->default('internal_user');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();

            $table->timestamps();

            // One acknowledgement per position per policy. Duplicates would
            // inflate the compliance tracker, which is the one number this
            // table exists to produce.
            $table->unique(['hr_policy_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_policy_acknowledgements');
    }
};
