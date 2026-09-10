<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Enrollment;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Records terms acceptance.
 *
 * SOW section 4.2 module 1 requires acceptance on screen with name, date and time.
 * The login went away; the legal record did not.
 *
 * Acceptances are immutable - the model refuses updates and deletes. A
 * correction is a new row against a new terms version.
 */
class TermsAcceptanceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record an acceptance made by internal staff on the customer's behalf.
     */
    public function recordByStaff(
        Enrollment $enrollment,
        string $termsVersion,
        string $acceptedName,
        User $actor,
        ?string $ip = null,
        ?string $userAgent = null,
    ): TermsAcceptance {
        return $this->record(
            $enrollment,
            $termsVersion,
            $acceptedName,
            ActorSource::InternalUser,
            $actor,
            null,
            $ip,
            $userAgent,
        );
    }

    /**
     * Record an acceptance made through a scoped external grant.
     *
     * The grant must belong to the same enrolment; a caller cannot pair one
     * customer's grant with another's enrolment.
     */
    public function recordByGrant(
        Enrollment $enrollment,
        string $termsVersion,
        string $acceptedName,
        AccessGrant $grant,
        ?string $ip = null,
        ?string $userAgent = null,
    ): TermsAcceptance {
        if ((int) $grant->enrollment_id !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'grant '.$grant->getKey(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$grant->enrollment_id,
            );
        }

        return $this->record(
            $enrollment,
            $termsVersion,
            $acceptedName,
            ActorSource::ExternalGrant,
            null,
            $grant,
            $ip,
            $userAgent,
        );
    }

    private function record(
        Enrollment $enrollment,
        string $termsVersion,
        string $acceptedName,
        ActorSource $source,
        ?User $actor,
        ?AccessGrant $grant,
        ?string $ip,
        ?string $userAgent,
    ): TermsAcceptance {
        return DB::transaction(function () use (
            $enrollment, $termsVersion, $acceptedName, $source, $actor, $grant, $ip, $userAgent
        ): TermsAcceptance {
            $acceptance = new TermsAcceptance;
            $acceptance->forceFill([
                'enrollment_id' => $enrollment->getKey(),
                'terms_version' => $termsVersion,
                'accepted_name' => $acceptedName,
                'accepted_at' => now(),
                'accepted_ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'source' => $source,
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::TermsAccepted,
                $acceptance,
                null,
                ['enrollment_id' => $enrollment->getKey(), 'terms_version' => $termsVersion],
                $actor,
                $source,
                $grant,
            );

            return $acceptance->fresh();
        });
    }
}
