<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues and revokes scoped external access grants.
 *
 * Phase 1 implements the grant infrastructure only. REDEMPTION IS PHASE 5 -
 * there is deliberately no method here that consumes a token, and no route
 * accepts one yet.
 *
 * A grant carries a capability, not an identity. It never creates an account,
 * a password or a session, and its scope is always the quadruple:
 *
 *     customer + enrolment + subject (resource) + ability (action) + expiry
 *
 * The plaintext token leaves this service exactly once, inside IssuedGrant, on
 * its way into an emailed link. Only the SHA-256 hash is stored.
 */
class AccessGrantService
{
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Issue a grant.
     *
     * Every identifier is verified against the others before anything is
     * written: an enrolment must belong to the named customer, a contact must
     * belong to the named customer, and the subject must resolve to both. A
     * caller cannot widen a grant by substituting an id.
     */
    public function issue(
        Customer $customer,
        Enrollment $enrollment,
        Model $subject,
        GrantAbility $ability,
        DateTimeInterface $expiresAt,
        User $actor,
        ?CustomerContact $contact = null,
        int $maxUses = 1,
    ): IssuedGrant {
        if ($maxUses < 1) {
            throw new InvalidArgumentException('A grant must permit at least one use.');
        }

        if ($expiresAt <= now()) {
            throw new InvalidArgumentException('A grant must expire in the future.');
        }

        $this->assertEnrollmentBelongsToCustomer($enrollment, $customer);
        $this->assertSubjectIsInScope($subject, $customer, $enrollment);

        if ($contact !== null) {
            $this->assertContactBelongsToCustomer($contact, $customer);
        }

        return DB::transaction(function () use (
            $customer, $enrollment, $subject, $ability, $expiresAt, $actor, $contact, $maxUses
        ): IssuedGrant {
            $plaintext = $this->generateToken();

            $grant = new AccessGrant;
            $grant->forceFill([
                'token_hash' => $this->hash($plaintext),
                'ability' => $ability,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'customer_id' => $customer->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'customer_contact_id' => $contact?->getKey(),
                'single_use' => $maxUses === 1,
                'max_uses' => $maxUses,
                'use_count' => 0,
                'issued_by' => $actor->getKey(),
                'issued_at' => now(),
                'expires_at' => $expiresAt,
            ])->save();

            // The plaintext token is never audited - only the fact of issue,
            // and the scope it was issued for.
            $this->audit->log(
                AuditAction::AccessGrantIssued,
                $grant,
                null,
                [
                    'ability' => $ability->value,
                    'customer_id' => $customer->getKey(),
                    'enrollment_id' => $enrollment->getKey(),
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                    'max_uses' => $maxUses,
                ],
                $actor,
            );

            return new IssuedGrant($grant->fresh(), $plaintext);
        });
    }

    public function revoke(AccessGrant $grant, User $actor, ?string $reason = null): AccessGrant
    {
        return DB::transaction(function () use ($grant, $actor, $reason): AccessGrant {
            // Revocation is state, not deletion: the grant is retained as
            // evidence of what was permitted and when.
            $grant->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor->getKey(),
                'revoke_reason' => $reason,
            ])->save();

            $this->audit->log(
                AuditAction::AccessGrantRevoked,
                $grant,
                ['revoked_at' => null],
                ['revoked_at' => $grant->revoked_at, 'revoke_reason' => $reason],
                $actor,
            );

            return $grant->fresh();
        });
    }

    /**
     * Look a grant up by its plaintext token, in constant time with respect to
     * the stored value - by hash, never by a LIKE.
     *
     * Redemption itself is Phase 5. This exists so that issuing can be tested
     * against the guarantee that the plaintext is not recoverable from, and
     * not stored in, the database.
     */
    public function findByToken(string $plaintext): ?AccessGrant
    {
        return AccessGrant::query()->where('token_hash', $this->hash($plaintext))->first();
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    private function generateToken(): string
    {
        // 256 bits of entropy, URL-safe so it can be carried in a link.
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function assertEnrollmentBelongsToCustomer(Enrollment $enrollment, Customer $customer): void
    {
        if ((int) $enrollment->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'enrolment '.$enrollment->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$enrollment->customer_id,
            );
        }
    }

    private function assertContactBelongsToCustomer(CustomerContact $contact, Customer $customer): void
    {
        if ((int) $contact->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'contact '.$contact->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$contact->customer_id,
            );
        }
    }

    /**
     * The isolation boundary for external access: a grant's subject must
     * resolve to the same customer and enrolment as the grant itself.
     *
     * Fails CLOSED. A subject whose ownership cannot be established is
     * refused rather than assumed safe, so a later phase that adds a new
     * subject type must extend this method deliberately.
     */
    private function assertSubjectIsInScope(Model $subject, Customer $customer, Enrollment $enrollment): void
    {
        if ($subject instanceof Enrollment) {
            if ((int) $subject->getKey() !== (int) $enrollment->getKey()) {
                throw CustomerIsolationException::mismatch(
                    'subject enrolment '.$subject->getKey(),
                    'enrolment '.$enrollment->getKey(),
                    'enrolment '.$subject->getKey(),
                );
            }

            return;
        }

        $subjectEnrollmentId = $subject->getAttribute('enrollment_id');
        $subjectCustomerId = $subject->getAttribute('customer_id');

        if ($subjectEnrollmentId === null && $subjectCustomerId === null) {
            throw CustomerIsolationException::unverifiableSubject($subject->getMorphClass());
        }

        if ($subjectEnrollmentId !== null && (int) $subjectEnrollmentId !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'subject '.$subject->getMorphClass(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$subjectEnrollmentId,
            );
        }

        if ($subjectCustomerId !== null && (int) $subjectCustomerId !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'subject '.$subject->getMorphClass(),
                'customer '.$customer->getKey(),
                'customer '.$subjectCustomerId,
            );
        }
    }
}
