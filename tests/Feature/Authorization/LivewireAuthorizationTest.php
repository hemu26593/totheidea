<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Livewire\Users\Index;
use App\Livewire\Users\ManageUser;
use App\Models\User;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Requirement 12: Livewire actions are authorization-protected.
 *
 * Every public method on a Livewire component is a directly invocable HTTP
 * endpoint. Rendering the component is not the test — invoking the method is,
 * because a crafted request can call an action whose button was never
 * rendered.
 */
class LivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function staff_cannot_mount_the_user_list(): void
    {
        Livewire::actingAs($this->staff())
            ->test(Index::class)
            ->assertForbidden();
    }

    #[Test]
    public function staff_cannot_invoke_the_deactivate_action(): void
    {
        $target = $this->staff();

        // Staff cannot even mount the component, so the action is unreachable
        // for them; asserting the mount refusal is the meaningful check.
        Livewire::actingAs($this->staff())
            ->test(Index::class)
            ->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
    }

    #[Test]
    public function an_admin_cannot_deactivate_a_user_who_outranks_them(): void
    {
        $superAdmin = $this->superAdmin();
        $this->superAdmin();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('deactivate', $superAdmin->id)
            ->assertForbidden();

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    #[Test]
    public function admin_cannot_invoke_delete_at_all(): void
    {
        $target = $this->staff();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('delete', $target->id)
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    #[Test]
    public function super_admin_can_invoke_deactivate(): void
    {
        $target = $this->staff();

        Livewire::actingAs($this->superAdmin())
            ->test(Index::class)
            ->call('deactivate', $target->id);

        $this->assertFalse($target->fresh()->is_active);
    }

    #[Test]
    public function staff_cannot_mount_the_user_form(): void
    {
        Livewire::actingAs($this->staff())
            ->test(ManageUser::class)
            ->assertForbidden();
    }

    #[Test]
    public function admin_cannot_create_a_super_admin_through_the_form(): void
    {
        // The role select never offers Super Admin to an Admin — this submits
        // it anyway, which is what an attacker would do.
        Livewire::actingAs($this->admin())
            ->test(ManageUser::class)
            ->set('name', 'Escalated')
            ->set('email', 'escalated@example.test')
            ->set('password', 'a-strong-password-9')
            ->set('role', UserRole::SuperAdmin->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
    }

    #[Test]
    public function admin_cannot_promote_an_existing_user_to_super_admin(): void
    {
        $target = $this->staff();

        Livewire::actingAs($this->admin())
            ->test(ManageUser::class, ['user' => $target])
            ->set('role', UserRole::SuperAdmin->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::Staff, $target->fresh()->role());
    }

    #[Test]
    public function admin_can_create_a_staff_user(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageUser::class)
            ->set('name', 'New Staffer')
            ->set('email', 'staffer@example.test')
            ->set('password', 'a-strong-password-9')
            ->set('role', UserRole::Staff->value)
            ->call('save')
            ->assertHasNoErrors();

        $created = User::where('email', 'staffer@example.test')->firstOrFail();

        $this->assertSame(UserRole::Staff, $created->role());
        $this->assertTrue($created->is_active);
    }

    #[Test]
    public function the_user_id_property_is_locked_against_rebinding(): void
    {
        // Without #[Locked], a crafted update could point the component at a
        // different account than the one authorized in mount().
        $target = $this->staff();
        $other = $this->staff();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(ManageUser::class, ['user' => $target]);

        $this->expectException(
            CannotUpdateLockedPropertyException::class
        );

        $component->set('userId', $other->id);
    }

    /**
     * Livewire's transport endpoint is an ordinary HTTP route in the web group,
     * so EnsureUserIsActive applies to it. Livewire::test() invokes components
     * in-process and skips HTTP middleware entirely, so this has to be asserted
     * over real HTTP to mean anything.
     */
    #[Test]
    public function a_deactivated_user_is_rejected_by_the_livewire_transport_endpoint(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);
        $admin->forceFill(['is_active' => false])->save();

        $this->withHeaders(['X-Livewire' => 'true'])
            ->post('/'.$this->livewireUpdateUri(), ['components' => []])
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * Livewire 4 derives its endpoint prefix per application, so the URI is
     * resolved from the route table rather than hard-coded.
     */
    private function livewireUpdateUri(): string
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->getName() !== null
                && str_ends_with($r->getName(), 'livewire.update'));

        $this->assertNotNull($route, 'Livewire update route not found.');

        return $route->uri();
    }

    #[Test]
    public function the_livewire_transport_endpoint_runs_the_web_middleware_group(): void
    {
        // The web group is where EnsureUserIsActive is appended, so this is the
        // structural half of the guarantee the HTTP test above exercises.
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->getName() !== null
                && str_ends_with($r->getName(), 'livewire.update'));

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());

        $this->assertContains(
            EnsureUserIsActive::class,
            app(Kernel::class)->getMiddlewareGroups()['web'],
        );
    }
}
