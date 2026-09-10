<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\AccessGrantDeniedException;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Redeems a scoped external access grant.
 *
 * This is the one unauthenticated write path in the system, so every check is
 * here and every one is server-side. Nothing supplied by the caller is
 * trusted: not the customer id, not the enrolment id, not the subject id.
 * Everything is resolved from the grant that the token hashes to.
 *
 * Two entry points, and the difference matters:
 *
 *   authorize() - validates and returns the scope. Consumes NOTHING. This is
 *                 what opening a form uses, so that reading a form and
 *                 answering it question by question does not burn a
 *                 single-use grant before the participant has finished.
 *
 *   redeem()    - validates AND consumes one use, atomically. This is what the
 *                 committing act uses.
 *
 * WHAT THIS NEVER DOES:
 *   - create a user, a password, a role or a session
 *   - log anyone in, or leave anything behind that outlives the call
 *   - widen access beyond the grant's own subject and ability
 *   - tell the caller why a token was refused
 *
 * Every refusal is AccessGrantDeniedException with the same message.
 */
class AccessGrantRedeemer
{
    public function __construct(
        private readonly AccessGrantService $grants,
        private readonly GrantScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate a token and resolve its scope WITHOUT consuming a use.
     *
     * @param  Model|null  $expectedSubject  the resource the caller claims to be acting on
     */
    public function authorize(
        string $plaintext,
        GrantAbility $ability,
        ?Model $expectedSubject = null,
        ?string $ip = null,
    ): RedeemedGrant {
        $this->assertNotRateLimited($ip);

        $grant = $this->resolve($plaintext, $ability, $expectedSubject, $ip);

        return new RedeemedGrant($grant, $this->resolveSubject($grant, $ip));
    }

    /**
     * Validate a token and consume one use, atomically.
     *
     * The increment is a conditional UPDATE rather than a read-modify-write.
     * Two simultaneous submissions of a single-use grant would both pass an
     * isExhausted() check and both write; only one can win a
     * `WHERE use_count < max_uses` update, and the loser is refused like any
     * other invalid token. That is also what makes replaying a spent link
     * fail.
     */
    public function redeem(
        string $plaintext,
        GrantAbility $ability,
        ?Model $expectedSubject = null,
        ?string $ip = null,
    ): RedeemedGrant {
        $this->assertNotRateLimited($ip);

        $grant = $this->resolve($plaintext, $ability, $expectedSubject, $ip);
        $subject = $this->resolveSubject($grant, $ip);

        return DB::transaction(function () use ($grant, $subject, $ip): RedeemedGrant {
            $claimed = AccessGrant::query()
                ->whereKey($grant->getKey())
                ->whereColumn('use_count', '<', 'max_uses')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'use_count' => DB::raw('use_count + 1'),
                    'last_used_at' => now(),
                    'last_used_ip' => $ip,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                $this->deny($grant, AccessGrantDeniedException::REASON_RACE_LOST, $ip);
            }

            $fresh = $grant->fresh();

            // Recorded with source = external_grant and the grant id, so the
            // trail says a capability was used, not that a user acted.
            $this->audit->log(
                AuditAction::AccessGrantUsed,
                $fresh,
                null,
                [
                    'ability' => $fresh->ability->value,
                    'customer_id' => $fresh->customer_id,
                    'enrollment_id' => $fresh->enrollment_id,
                    'subject_type' => $fresh->subject_type,
                    'subject_id' => $fresh->subject_id,
                    'use_count' => $fresh->use_count,
                ],
                null,
                ActorSource::ExternalGrant,
                $fresh,
            );

            return new RedeemedGrant($fresh, $subject);
        });
    }

    /**
     * The validity chain. Order is deliberate: cheapest and least
     * informative first, so nothing about a token is established before it is
     * known to exist.
     */
    private function resolve(
        string $plaintext,
        GrantAbility $ability,
        ?Model $expectedSubject,
        ?string $ip,
    ): AccessGrant {
        // Lookup is by SHA-256 hash and by equality - never by plaintext,
        // never with a LIKE, and never by loading candidates and comparing in
        // PHP.
        $grant = $this->grants->findByToken($plaintext);

        if ($grant === null) {
            $this->deny(null, AccessGrantDeniedException::REASON_UNKNOWN_TOKEN, $ip);
        }

        if ($grant->isRevoked()) {
            $this->deny($grant, AccessGrantDeniedException::REASON_REVOKED, $ip);
        }

        if ($grant->isExpired()) {
            $this->deny($grant, AccessGrantDeniedException::REASON_EXPIRED, $ip);
        }

        if ($grant->isExhausted()) {
            $this->deny($grant, AccessGrantDeniedException::REASON_EXHAUSTED, $ip);
        }

        // The action must be the one the grant was issued for. A
        // complete_form grant cannot mark attendance.
        if ($grant->ability !== $ability) {
            $this->deny($grant, AccessGrantDeniedException::REASON_WRONG_ABILITY, $ip);
        }

        // The resource must be the one the grant was issued for. This is what
        // stops a grant for customer A's form being pointed at customer B's.
        if ($expectedSubject !== null && ! $this->subjectMatches($grant, $expectedSubject)) {
            $this->deny($grant, AccessGrantDeniedException::REASON_WRONG_SUBJECT, $ip);
        }

        return $grant;
    }

    /**
     * Load the subject and re-prove it is inside the grant's scope.
     *
     * The check ran when the grant was issued. It runs AGAIN here, through the
     * same GrantScope, because the two facts can drift: a grant is long-lived,
     * and a row it names can be re-parented by a later bug. This boundary must
     * not depend on the correctness of anything upstream of it.
     *
     * Fails closed - an unresolvable subject is refused, not assumed safe.
     */
    private function resolveSubject(AccessGrant $grant, ?string $ip): Model
    {
        $subject = $grant->subject()->first();

        if ($subject === null) {
            $this->deny($grant, AccessGrantDeniedException::REASON_SUBJECT_MISSING, $ip);
        }

        $customer = $grant->customer()->first();
        $enrollment = $grant->enrollment()->first();

        if ($customer === null || $enrollment === null) {
            $this->deny($grant, AccessGrantDeniedException::REASON_OUT_OF_SCOPE, $ip);
        }

        // The grant's own pair must still agree with each other, not just with
        // the subject. A grant whose enrolment has moved to another business
        // is not a grant on anything.
        if ((int) $enrollment->customer_id !== (int) $customer->getKey()) {
            $this->deny($grant, AccessGrantDeniedException::REASON_OUT_OF_SCOPE, $ip);
        }

        try {
            $this->scope->assertInScope($subject, $customer, $enrollment);
        } catch (CustomerIsolationException) {
            $this->deny($grant, AccessGrantDeniedException::REASON_OUT_OF_SCOPE, $ip);
        }

        return $subject;
    }

    private function subjectMatches(AccessGrant $grant, Model $subject): bool
    {
        return $subject->getMorphClass() === $grant->subject_type
            && (int) $subject->getKey() === (int) $grant->subject_id;
    }

    /**
     * Keyed by caller, not by token. Keying by token would let an attacker try
     * every candidate once and never hit a limit.
     *
     * THE BUDGET IS SPENT BY REFUSALS, NOT BY USE. The limiter exists to bound
     * token GUESSING, and a token that resolves is not a guess. Counting
     * successful calls too would make the limit fire on legitimate work: an
     * external participant re-presents their token on every saved answer,
     * because there is no session for the boundary to trust between calls, so
     * an eleven-question form would lock its own owner out of their link.
     * RateLimiter::hit() therefore lives in deny(). Volume, as opposed to
     * guessing, is bounded at the route by throttle middleware, which is where
     * a request-rate concern belongs.
     */
    private function assertNotRateLimited(?string $ip): void
    {
        $max = (int) config('access.redemption.max_attempts', 10);

        if (RateLimiter::tooManyAttempts($this->limiterKey($ip), $max)) {
            $this->deny(null, AccessGrantDeniedException::REASON_RATE_LIMITED, $ip);
        }
    }

    private function limiterKey(?string $ip): string
    {
        return 'access-grant-redemption:'.($ip ?? 'unknown');
    }

    /**
     * Record the internal reason, return the uniform refusal.
     *
     * The reason reaches the audit trail, which staff read. It never reaches
     * the caller: AccessGrantDeniedException carries one message for every
     * cause.
     */
    private function deny(?AccessGrant $grant, string $reason, ?string $ip): never
    {
        RateLimiter::hit(
            $this->limiterKey($ip),
            (int) config('access.redemption.decay_seconds', 60),
        );

        try {
            $this->audit->log(
                AuditAction::AccessGrantDenied,
                $grant,
                null,
                array_filter([
                    'reason' => $reason,
                    'ip' => $ip,
                    // Present only when the token resolved to a real grant.
                    // For an unknown token there is nothing to name, and the
                    // token itself is never written anywhere.
                    'customer_id' => $grant?->customer_id,
                    'ability' => $grant?->ability?->value,
                ], static fn (mixed $value): bool => $value !== null),
                null,
                ActorSource::System,
            );
        } catch (Throwable) {
            // A refusal must never depend on the audit write succeeding.
            // Failing open here would be the one bug worth having.
        }

        throw AccessGrantDeniedException::because($reason);
    }
}
