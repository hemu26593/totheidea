<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The A1 audit extension: distinguishing internal staff activity from
 * external access-grant activity.
 *
 * Without the source discriminator an external submission appears actor-less
 * and is indistinguishable from an automated system write. "Did staff enter
 * this, or did the owner?" is exactly the question the locked rules require to
 * be answerable.
 */
class AuditSourceTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->audit = app(AuditLogger::class);
    }

    #[Test]
    public function an_entry_with_a_user_is_internal(): void
    {
        $entry = $this->audit->log(AuditAction::CustomerCreated, null, null, null, $this->admin());

        $this->assertSame(ActorSource::InternalUser, $entry->source);
        $this->assertNotNull($entry->actor_id);
        $this->assertNull($entry->access_grant_id);
    }

    #[Test]
    public function an_entry_with_a_grant_is_external_and_has_no_user(): void
    {
        $grant = AccessGrant::factory()->create();

        $entry = $this->audit->log(
            AuditAction::AccessGrantUsed,
            null, null, null,
            actor: null,
            source: null,
            accessGrant: $grant,
        );

        $this->assertSame(ActorSource::ExternalGrant, $entry->source);
        $this->assertSame($grant->getKey(), $entry->access_grant_id);
        $this->assertNull($entry->actor_id);
    }

    #[Test]
    public function an_entry_with_neither_is_a_system_write(): void
    {
        $entry = $this->audit->log(AuditAction::ReportGenerated);

        $this->assertSame(ActorSource::System, $entry->source);
        $this->assertNull($entry->actor_id);
        $this->assertNull($entry->access_grant_id);
    }

    #[Test]
    public function internal_and_external_activity_are_distinguishable_in_the_trail(): void
    {
        $grant = AccessGrant::factory()->create();

        $this->audit->log(AuditAction::SubmissionSubmitted, null, null, null, $this->admin());
        $this->audit->log(
            AuditAction::SubmissionSubmitted, null, null, null,
            actor: null, source: null, accessGrant: $grant,
        );

        $this->assertSame(1, AuditLog::query()->where('source', ActorSource::InternalUser->value)->count());
        $this->assertSame(1, AuditLog::query()->where('source', ActorSource::ExternalGrant->value)->count());
    }

    #[Test]
    public function the_existing_audit_behaviour_is_unchanged(): void
    {
        // One audit system, not two: entries remain immutable and
        // undeletable, and secrets are still redacted.
        $entry = $this->audit->log(
            AuditAction::UserCreated,
            null,
            null,
            ['password' => 'hunter2', 'name' => 'A'],
            $this->admin(),
        );

        $this->assertSame('[redacted]', $entry->new_values['password']);
        $this->assertSame('A', $entry->new_values['name']);

        $this->expectException(\RuntimeException::class);
        $entry->update(['action' => AuditAction::Login]);
    }

    #[Test]
    public function the_source_column_defaults_to_internal_user(): void
    {
        // A pre-existing row written before the extension reads as internal,
        // which is what it was.
        DB::table('audit_logs')->insert([
            'action' => AuditAction::Login->value,
            'created_at' => now(),
        ]);

        $entry = AuditLog::query()->firstOrFail();

        $this->assertSame(ActorSource::InternalUser, $entry->source);
    }
}
