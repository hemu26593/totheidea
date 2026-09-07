<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Requirements 20-23: administrative and security actions are audited. */
class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->users = app(UserService::class);
    }

    /** Requirement 20 */
    #[Test]
    public function user_creation_is_logged(): void
    {
        $actor = $this->superAdmin();

        $created = $this->users->create([
            'name' => 'Created Person',
            'email' => 'created@example.test',
            'password' => 'a-strong-password-9',
        ], UserRole::Staff, $actor);

        $entry = AuditLog::where('action', AuditAction::UserCreated)->firstOrFail();

        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame($created->id, $entry->auditable_id);
        $this->assertSame('created@example.test', $entry->new_values['email']);
        $this->assertSame(UserRole::Staff->value, $entry->new_values['role']);
    }

    #[Test]
    public function a_created_users_password_is_never_written_to_the_audit_log(): void
    {
        $actor = $this->superAdmin();

        $this->users->create([
            'name' => 'Created Person',
            'email' => 'created@example.test',
            'password' => 'a-very-secret-password-9',
        ], UserRole::Staff, $actor);

        $this->assertStringNotContainsString(
            'a-very-secret-password-9',
            AuditLog::all()->toJson(),
        );
    }

    /** Requirement 21 */
    #[Test]
    public function role_assignment_is_logged_with_before_and_after(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff();

        $this->users->assignRole($target, UserRole::Admin, $actor);

        $entry = AuditLog::where('action', AuditAction::RoleAssigned)->firstOrFail();

        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame($target->id, $entry->auditable_id);
        $this->assertSame(UserRole::Staff->value, $entry->old_values['role']);
        $this->assertSame(UserRole::Admin->value, $entry->new_values['role']);
    }

    /** Requirement 22 */
    #[Test]
    public function deactivation_and_activation_are_logged(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff();

        $this->users->deactivate($target, $actor);
        $this->users->activate($target->fresh(), $actor);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::UserDeactivated->value,
            'auditable_id' => $target->id,
            'actor_id' => $actor->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::UserActivated->value,
            'auditable_id' => $target->id,
            'actor_id' => $actor->id,
        ]);
    }

    #[Test]
    public function user_updates_record_only_the_changed_attributes(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff(['name' => 'Original Name']);

        $this->users->update($target, [
            'name' => 'Changed Name',
            'email' => $target->email, // unchanged
        ], $actor);

        $entry = AuditLog::where('action', AuditAction::UserUpdated)->firstOrFail();

        $this->assertSame(['name' => 'Original Name'], $entry->old_values);
        $this->assertSame(['name' => 'Changed Name'], $entry->new_values);
        $this->assertArrayNotHasKey('email', $entry->new_values);
    }

    #[Test]
    public function an_update_that_changes_nothing_writes_no_entry(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff(['name' => 'Same Name']);

        $this->users->update($target, ['name' => 'Same Name'], $actor);

        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::UserUpdated->value]);
    }

    #[Test]
    public function deletion_is_logged_before_the_record_disappears(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff();
        $email = $target->email;

        $this->users->delete($target, $actor);

        $entry = AuditLog::where('action', AuditAction::UserDeleted)->firstOrFail();

        $this->assertSame($email, $entry->old_values['email']);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    /** Requirement 23 */
    #[Test]
    public function security_relevant_actions_are_all_captured(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff();

        $this->users->assignRole($target, UserRole::Admin, $actor);
        $this->users->deactivate($target->fresh(), $actor);

        $recorded = AuditLog::pluck('action')->map(fn ($a) => $a->value)->all();

        $this->assertContains(AuditAction::RoleAssigned->value, $recorded);
        $this->assertContains(AuditAction::UserDeactivated->value, $recorded);
    }

    #[Test]
    public function entries_capture_request_context(): void
    {
        $actor = $this->superAdmin();

        $this->users->create([
            'name' => 'Contextual',
            'email' => 'contextual@example.test',
            'password' => 'a-strong-password-9',
        ], UserRole::Staff, $actor);

        $entry = AuditLog::where('action', AuditAction::UserCreated)->firstOrFail();

        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
        $this->assertSame($actor->email, $entry->actor_label);
    }

    #[Test]
    public function audit_entries_cannot_be_modified(): void
    {
        // An audit trail that can be edited is not evidence.
        $this->users->create([
            'name' => 'Subject',
            'email' => 'subject@example.test',
            'password' => 'a-strong-password-9',
        ], UserRole::Staff, $this->superAdmin());

        $entry = AuditLog::firstOrFail();

        $this->expectException(RuntimeException::class);
        $entry->update(['action' => AuditAction::Login]);
    }

    #[Test]
    public function audit_entries_cannot_be_deleted(): void
    {
        $this->users->create([
            'name' => 'Subject',
            'email' => 'subject@example.test',
            'password' => 'a-strong-password-9',
        ], UserRole::Staff, $this->superAdmin());

        $entry = AuditLog::firstOrFail();

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    #[Test]
    public function the_trail_survives_deletion_of_the_actor(): void
    {
        $actor = $this->superAdmin();
        $this->superAdmin();
        $target = $this->staff();

        $this->users->assignRole($target, UserRole::Admin, $actor);

        $actorEmail = $actor->email;
        $this->users->delete($actor, $this->superAdmin());

        $entry = AuditLog::where('action', AuditAction::RoleAssigned)->firstOrFail();

        // actor_id is nulled by the foreign key, but the denormalised label
        // keeps the trail readable.
        $this->assertNull($entry->fresh()->actor_id);
        $this->assertSame($actorEmail, $entry->actor_label);
    }
}
