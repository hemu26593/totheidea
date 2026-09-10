<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\AccessGrantRedeemer;
use App\Domain\Access\AccessGrantService;
use App\Domain\Access\IssuedGrant;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Enums\QuestionType;
use App\Exceptions\AccessGrantDeniedException;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Redemption of a scoped external access grant.
 *
 * This is the ONE unauthenticated write path in the system, so this file is
 * the security boundary in test form. Two properties are load-bearing and are
 * asserted from several directions each:
 *
 *   1. Nothing a caller supplies is trusted. Customer, enrolment and subject
 *      are all resolved from the grant the token hashes to.
 *   2. Every refusal is indistinguishable. A caller cannot learn whether a
 *      token exists, has expired, was revoked, or belongs to someone else.
 */
class AccessGrantRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private AccessGrantService $grants;

    private AccessGrantRedeemer $redeemer;

    private FormBuilderService $builder;

    private FormPublishingService $publishing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->grants = app(AccessGrantService::class);
        $this->redeemer = app(AccessGrantRedeemer::class);
        $this->builder = app(FormBuilderService::class);
        $this->publishing = app(FormPublishingService::class);
        RateLimiter::clear('access-grant-redemption:198.51.100.10');
    }

    /**
     * A complete, valid graph: customer -> enrolment -> published form version,
     * with a grant scoped to exactly that version.
     */
    private function issueFormGrant(?Customer $customer = null, ?User $actor = null): IssuedGrant
    {
        $customer ??= Customer::factory()->create();
        $actor ??= $this->admin();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $version = $this->publishedVersion();

        return $this->grants->issue(
            $customer,
            $enrollment,
            $version,
            GrantAbility::CompleteForm,
            now()->addDays(7),
            $actor,
        );
    }

    /**
     * A genuinely published version, built the way the application builds one.
     *
     * There is no published() factory state by design: the one-published-
     * version invariant lives in FormPublishingService, and a factory that
     * fabricated the status around it would let tests pass against a state the
     * application cannot produce.
     */
    private function publishedVersion(): FormVersion
    {
        $template = FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'Section', 0);
        $this->builder->addQuestion($version, $section, QuestionType::Text, 'A question', 0);
        $this->publishing->publish($version->fresh(), $this->admin());

        return $version->fresh();
    }

    // --- The happy path ----------------------------------------------------

    #[Test]
    public function a_valid_token_redeems_to_its_own_scope(): void
    {
        $issued = $this->issueFormGrant();

        $scope = $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $this->assertSame($issued->grant->getKey(), $scope->grant->getKey());
        $this->assertSame((int) $issued->grant->customer_id, $scope->customerId());
        $this->assertSame((int) $issued->grant->enrollment_id, $scope->enrollmentId());
        $this->assertSame((int) $issued->grant->subject_id, (int) $scope->subject->getKey());
    }

    #[Test]
    public function redeeming_consumes_a_use_and_records_when_and_from_where(): void
    {
        $issued = $this->issueFormGrant();

        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $grant = $issued->grant->fresh();
        $this->assertSame(1, $grant->use_count);
        $this->assertNotNull($grant->last_used_at);
        $this->assertSame('198.51.100.10', $grant->last_used_ip);
    }

    #[Test]
    public function authorizing_does_not_consume_a_use(): void
    {
        // Opening a form must not burn a single-use grant before the
        // participant has filled anything in.
        $issued = $this->issueFormGrant();

        $this->redeemer->authorize($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
        $this->redeemer->authorize($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $this->assertSame(0, $issued->grant->fresh()->use_count);
    }

    #[Test]
    public function using_a_grant_is_audited_as_an_external_act(): void
    {
        $issued = $this->issueFormGrant();

        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $entry = AuditLog::query()->where('action', AuditAction::AccessGrantUsed)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(ActorSource::ExternalGrant, $entry->source);
        $this->assertSame($issued->grant->getKey(), $entry->access_grant_id);
        // A capability was used. No user acted.
        $this->assertNull($entry->actor_id);
    }

    // --- Every refusal is the same refusal ---------------------------------

    /**
     * @return array<string, callable>
     */
    private function refusalCases(): array
    {
        return [
            'unknown token' => fn (): string => 'this-token-was-never-issued',
            'malformed token' => fn (): string => "\0\x01 not a token at all ../../etc/passwd",
            'empty token' => fn (): string => '',
        ];
    }

    #[Test]
    public function an_unknown_expired_revoked_or_spent_token_all_fail_identically(): void
    {
        $messages = [];

        // Unknown.
        $messages[] = $this->denialMessageFor('never-issued-token');

        // Expired.
        $expired = $this->issueFormGrant();
        $expired->grant->forceFill(['expires_at' => now()->subDay()])->save();
        $messages[] = $this->denialMessageFor($expired->plaintextToken);

        // Revoked.
        $revoked = $this->issueFormGrant();
        $this->grants->revoke($revoked->grant, $this->admin(), 'Sent to the wrong address');
        $messages[] = $this->denialMessageFor($revoked->plaintextToken);

        // Spent.
        $spent = $this->issueFormGrant();
        $this->redeemer->redeem($spent->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
        $messages[] = $this->denialMessageFor($spent->plaintextToken);

        // Belonging to another customer's action.
        $wrongAbility = $this->issueFormGrant();
        $messages[] = $this->denialMessageFor($wrongAbility->plaintextToken, GrantAbility::MarkAttendance);

        $this->assertCount(1, array_unique($messages),
            'Every refusal must be indistinguishable, or the endpoint becomes an oracle: '
            .'try a token, read the difference, learn whether it exists.');

        $this->assertSame(config('access.redemption.denial_message'), $messages[0]);
    }

    #[Test]
    public function a_tampered_token_is_refused(): void
    {
        $issued = $this->issueFormGrant();

        // One character changed. The lookup is by SHA-256 of the whole token,
        // so this is as unrelated as a random string.
        $tampered = substr($issued->plaintextToken, 0, -1).(str_ends_with($issued->plaintextToken, 'a') ? 'b' : 'a');

        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->authorize($tampered, GrantAbility::CompleteForm, ip: '198.51.100.10');
    }

    #[Test]
    public function a_malformed_token_is_refused_without_erroring(): void
    {
        foreach ($this->refusalCases() as $label => $token) {
            try {
                $this->redeemer->authorize($token(), GrantAbility::CompleteForm, ip: '198.51.100.10');
                $this->fail("[{$label}] should have been refused.");
            } catch (AccessGrantDeniedException $e) {
                $this->assertSame(config('access.redemption.denial_message'), $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_internal_reason_is_recorded_even_though_the_caller_is_told_nothing(): void
    {
        $expired = $this->issueFormGrant();
        $expired->grant->forceFill(['expires_at' => now()->subDay()])->save();

        $this->denialMessageFor($expired->plaintextToken);

        $entry = AuditLog::query()->where('action', AuditAction::AccessGrantDenied)->latest('id')->first();

        // Staff can see why; the caller cannot.
        $this->assertNotNull($entry);
        $this->assertSame(AccessGrantDeniedException::REASON_EXPIRED, $entry->new_values['reason']);
    }

    #[Test]
    public function a_refused_redemption_is_a_security_event(): void
    {
        $this->assertTrue(AuditAction::AccessGrantDenied->isSecurityEvent());
    }

    // --- Scope: action, resource, customer, enrolment ----------------------

    #[Test]
    public function a_grant_cannot_be_used_for_a_different_action(): void
    {
        $issued = $this->issueFormGrant();

        $this->expectException(AccessGrantDeniedException::class);

        // A complete_form link cannot mark attendance.
        $this->redeemer->authorize($issued->plaintextToken, GrantAbility::MarkAttendance, ip: '198.51.100.10');
    }

    #[Test]
    public function a_grant_cannot_be_pointed_at_a_different_resource(): void
    {
        $issued = $this->issueFormGrant();
        $someoneElsesForm = $this->publishedVersion();

        $this->expectException(AccessGrantDeniedException::class);

        $this->redeemer->authorize(
            $issued->plaintextToken,
            GrantAbility::CompleteForm,
            $someoneElsesForm,
            '198.51.100.10',
        );
    }

    #[Test]
    public function customer_a_cannot_reach_customer_b_by_substituting_a_subject(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        $issuedToA = $this->issueFormGrant($a);

        $bEnrollment = Enrollment::factory()->create(['customer_id' => $b->getKey()]);
        $bVersion = $this->publishedVersion();
        $issuedToB = $this->grants->issue(
            $b, $bEnrollment, $bVersion, GrantAbility::CompleteForm, now()->addDays(7), $this->admin(),
        );

        // A's token, pointed at B's resource. The single most important
        // isolation case in the system.
        $this->expectException(AccessGrantDeniedException::class);

        $this->redeemer->authorize(
            $issuedToA->plaintextToken,
            GrantAbility::CompleteForm,
            $issuedToB->grant->subject()->first(),
            '198.51.100.10',
        );
    }

    #[Test]
    public function a_grant_whose_subject_drifted_to_another_customer_fails_closed(): void
    {
        $issued = $this->issueFormGrant();

        // Simulate drift after issue - a bug this boundary must survive
        // rather than trust upstream to prevent. customer_id on the grant is
        // immutable by design, so the drift is applied to the enrolment, which
        // is the pair the redeemer re-proves.
        DB::table('enrollments')
            ->where('id', $issued->grant->enrollment_id)
            ->update(['customer_id' => Customer::factory()->create()->getKey()]);

        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->authorize($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
    }

    #[Test]
    public function a_grant_whose_subject_no_longer_exists_fails_closed(): void
    {
        $issued = $this->issueFormGrant();

        $issued->grant->forceFill(['subject_id' => 999999])->save();

        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->authorize($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
    }

    // --- Replay ------------------------------------------------------------

    #[Test]
    public function a_spent_single_use_link_cannot_be_replayed(): void
    {
        $issued = $this->issueFormGrant();

        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
    }

    #[Test]
    public function the_use_count_is_the_only_thing_that_decides_a_replay(): void
    {
        // A conditional UPDATE, not a read-modify-write: two simultaneous
        // submissions both pass an isExhausted() check, and only one can win
        // `WHERE use_count < max_uses`.
        $issued = $this->issueFormGrant();

        AccessGrant::query()->whereKey($issued->grant->getKey())->update(['use_count' => 1]);

        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
    }

    #[Test]
    public function a_multi_use_grant_permits_exactly_its_stated_number_of_uses(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $version = $this->publishedVersion();

        $issued = $this->grants->issue(
            $customer, $enrollment, $version, GrantAbility::CompleteForm,
            now()->addDays(7), $this->admin(), maxUses: 2,
        );

        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');
    }

    // --- Rate limiting -----------------------------------------------------

    #[Test]
    public function redemption_is_rate_limited_per_caller(): void
    {
        config()->set('access.redemption.max_attempts', 3);
        RateLimiter::clear('access-grant-redemption:203.0.113.99');

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->redeemer->authorize('nope', GrantAbility::CompleteForm, ip: '203.0.113.99');
            } catch (AccessGrantDeniedException) {
                // Expected - the point is that the attempts are counted.
            }
        }

        $issued = $this->issueFormGrant();

        // Even a VALID token is now refused from that caller.
        $this->expectException(AccessGrantDeniedException::class);
        $this->redeemer->authorize($issued->plaintextToken, GrantAbility::CompleteForm, ip: '203.0.113.99');
    }

    // --- What redemption must never create ---------------------------------

    #[Test]
    public function redeeming_creates_no_session_and_logs_nobody_in(): void
    {
        $issued = $this->issueFormGrant();

        $this->assertFalse(Auth::check());

        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        // A grant is a capability. It never becomes an identity.
        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }

    #[Test]
    public function redeeming_creates_no_user_account(): void
    {
        $issued = $this->issueFormGrant();
        $before = User::query()->count();

        $this->redeemer->redeem($issued->plaintextToken, GrantAbility::CompleteForm, ip: '198.51.100.10');

        $this->assertSame($before, User::query()->count(),
            'Customer != User. External access must never manufacture an account.');
    }

    #[Test]
    public function no_customer_authentication_structure_exists(): void
    {
        foreach (['customer_users', 'customer_accounts', 'customer_roles', 'customer_passwords', 'customer_sessions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "[{$table}] must never exist.");
        }

        foreach (['password', 'remember_token', 'is_active'] as $column) {
            $this->assertFalse(Schema::hasColumn('customers', $column));
            $this->assertFalse(Schema::hasColumn('customer_contacts', $column));
        }
    }

    // --- Token storage -----------------------------------------------------

    #[Test]
    public function the_raw_token_is_never_persisted_anywhere(): void
    {
        $issued = $this->issueFormGrant();
        $token = $issued->plaintextToken;

        // Every text column of every table, scanned for the plaintext.
        foreach (Schema::getTableListing() as $qualified) {
            $table = str_contains($qualified, '.') ? explode('.', $qualified)[1] : $qualified;

            foreach (Schema::getColumns($table) as $column) {
                $rows = DB::table($table)
                    ->where($column['name'], 'like', '%'.$token.'%')
                    ->count();

                $this->assertSame(0, $rows,
                    "The plaintext token appeared in {$table}.{$column['name']}.");
            }
        }
    }

    #[Test]
    public function only_the_sha256_hash_is_stored(): void
    {
        $issued = $this->issueFormGrant();

        $this->assertSame(hash('sha256', $issued->plaintextToken), $issued->grant->token_hash);
        $this->assertSame(64, strlen($issued->grant->token_hash));
        $this->assertNotSame($issued->plaintextToken, $issued->grant->token_hash);
    }

    #[Test]
    public function the_hash_is_hidden_from_serialisation(): void
    {
        $issued = $this->issueFormGrant();

        $this->assertArrayNotHasKey('token_hash', $issued->grant->fresh()->toArray());
    }

    #[Test]
    public function the_token_is_long_and_url_safe(): void
    {
        $issued = $this->issueFormGrant();

        // 32 random bytes, base64url. Anything shorter would make the rate
        // limit load-bearing rather than a backstop.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $issued->plaintextToken);
    }

    private function denialMessageFor(string $token, ?GrantAbility $ability = null): string
    {
        try {
            $this->redeemer->redeem($token, $ability ?? GrantAbility::CompleteForm, ip: '198.51.100.10');
        } catch (AccessGrantDeniedException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected the token to be refused.');
    }
}
