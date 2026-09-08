<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\Contracts\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiApproval;
use App\Models\AiGeneration;
use App\Models\AiPromptVersion;
use App\Policies\AiApprovalPolicy;
use App\Policies\AiGenerationPolicy;
use App\Policies\AiPromptVersionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What AI is NOT allowed to be in this application.
 *
 * Several of these read the source tree rather than exercise behaviour, which
 * is deliberate: the rules are about capabilities that must never be built,
 * and the only way to test the absence of a capability is to look for it.
 */
class AiSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function the_provider_contract_offers_no_way_to_act_only_to_answer(): void
    {
        // One method, taking a request and returning text. There is no
        // continue(), no callTool(), no execute() - so no future adapter can
        // widen what AI is permitted to do without changing this contract in
        // plain sight.
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AiProvider::class))->getMethods(),
        );

        sort($methods);

        $this->assertSame(['generate', 'name'], $methods);
    }

    #[Test]
    public function generated_output_never_reaches_an_execution_sink(): void
    {
        // The AI domain contains no call that could turn text into
        // behaviour. Grepping for the sinks is the only way to assert an
        // absence, and it is worth doing because adding one would look like
        // ordinary code in review.
        $sinks = ['eval(', 'exec(', 'shell_exec(', 'passthru(', 'system(', 'proc_open(',
            'popen(', 'unserialize(', 'assert(', 'create_function(', 'call_user_func',
            'DB::statement', 'DB::raw', 'whereRaw', 'selectRaw', 'Blade::render', 'file_put_contents'];

        foreach ($this->sourcesIn(app_path('Domain/Ai')) as $file) {
            $source = file_get_contents($file);

            foreach ($sinks as $sink) {
                $this->assertStringNotContainsString(
                    $sink,
                    $source,
                    sprintf('%s contains [%s]. AI output is data and never becomes behaviour.',
                        basename($file), $sink),
                );
            }
        }
    }

    #[Test]
    public function the_ai_domain_never_writes_an_authoritative_figure(): void
    {
        // Scores, MMD entries, targets and attendance are computed by
        // application code. Nothing under app/Domain/Ai may name those
        // models at all, which is stronger than "does not currently write
        // one".
        $forbidden = ['SubmissionScore', 'MmdEntry', 'MmdTarget', 'SessionAttendance', 'ScoringService'];

        foreach ($this->sourcesIn(app_path('Domain/Ai')) as $file) {
            $source = file_get_contents($file);

            foreach ($forbidden as $model) {
                if ($file === app_path('Domain/Ai/CustomerContextAssembler.php') && $model === 'SubmissionScore') {
                    // The one permitted mention: reading already-computed
                    // scores as material for a narrative. It is a select.
                    $this->assertStringNotContainsString('SubmissionScore::query()->create', $source);

                    continue;
                }

                $this->assertStringNotContainsString(
                    $model,
                    $source,
                    sprintf('%s names [%s]. AI is never authoritative for a figure.', basename($file), $model),
                );
            }
        }
    }

    #[Test]
    public function the_ai_domain_never_touches_users_roles_permissions_or_access_grants(): void
    {
        // "Never allow AI to issue access grants / assign permissions /
        // modify users or roles." Structural, not procedural: those classes
        // are simply not reachable from here.
        $forbidden = ['AccessGrant', 'assignRole', 'givePermissionTo', 'syncRoles',
            'Permission::', 'Role::', 'UserService'];

        foreach ($this->sourcesIn(app_path('Domain/Ai')) as $file) {
            $source = file_get_contents($file);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    sprintf('%s names [%s]. AI cannot grant access or change who anyone is.',
                        basename($file), $needle),
                );
            }
        }
    }

    #[Test]
    public function a_proposed_question_type_outside_the_vocabulary_is_refused(): void
    {
        // A model may propose content; it does not extend the application's
        // vocabulary.
        $actor = $this->admin();
        $scenario = new AiScenario($actor);

        $generation = AiGeneration::factory()
            ->forCustomer($scenario->customer)
            ->succeeded([
                'title' => 'Odd',
                'sections' => [[
                    'title' => 'S',
                    'questions' => [['label' => 'Q', 'type' => 'App\\Models\\User']],
                ]],
            ])
            ->create(['generated_by' => $actor->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a question type/');

        app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);
    }

    #[Test]
    public function the_form_draft_schema_permits_no_field_that_carries_a_figure_or_an_expression(): void
    {
        $questionProperties = array_keys(
            AiFormDraftService::outputSchema()['properties']['sections']['items']['properties']['questions']['items']['properties']
        );

        sort($questionProperties);

        $this->assertSame(['help_text', 'is_required', 'label', 'options', 'type'], $questionProperties);

        $optionProperties = array_keys(
            AiFormDraftService::outputSchema()['properties']['sections']['items']['properties']['questions']['items']['properties']['options']['items']['properties']
        );

        sort($optionProperties);

        // No score_value. Average / Good / Better / Best have no numeric
        // mapping and a model does not get to propose one.
        $this->assertSame(['label', 'value'], $optionProperties);
    }

    #[Test]
    public function there_is_no_free_form_prompt_surface(): void
    {
        // Every invocation is one of a closed set of named purposes with a
        // prompt version behind it. There is no "ask anything" case.
        $this->assertSame(['form_draft', 'narrative', 'summary'], AiPurpose::values());
    }

    #[Test]
    public function no_route_exposes_an_arbitrary_prompt_box(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->uri());

        foreach (['chat', 'assistant', 'prompt', 'ask'] as $shape) {
            $this->assertFalse(
                $routes->contains(fn (string $uri): bool => str_contains($uri, $shape)),
                "A route matching [{$shape}] would be a general-purpose assistant surface."
            );
        }
    }

    #[Test]
    public function staff_can_read_ai_output_but_cannot_commission_or_approve_it(): void
    {
        $staff = $this->staff();
        $generation = AiGeneration::factory()->create();

        $this->assertTrue($staff->can('view', $generation));
        $this->assertTrue($staff->can('viewAny', AiGeneration::class));

        $this->assertFalse($staff->can('ai.forms.generate'));
        $this->assertFalse($staff->can('ai.analysis.generate'));
        $this->assertFalse($staff->can('ai.forms.approve'));
        $this->assertFalse($staff->can('approve', $generation));
        $this->assertFalse($staff->can('create', [AiApproval::class, $generation]));
    }

    #[Test]
    public function the_permission_needed_depends_on_what_is_being_generated(): void
    {
        $admin = $this->admin();
        $policy = new AiGenerationPolicy;

        $this->assertTrue($policy->generate($admin, AiPurpose::FormDraft));
        $this->assertTrue($policy->generate($admin, AiPurpose::Narrative));
        $this->assertTrue($policy->generate($admin, AiPurpose::Summary));

        $staff = $this->staff();
        $this->assertFalse($policy->generate($staff, AiPurpose::FormDraft));
        $this->assertFalse($policy->generate($staff, AiPurpose::Narrative));
    }

    #[Test]
    public function nobody_may_rewrite_or_delete_a_generation_or_an_approval(): void
    {
        $superAdmin = $this->superAdmin();
        $generation = AiGeneration::factory()->create();
        $approval = AiApproval::factory()->create();
        $prompt = AiPromptVersion::factory()->published()->create();

        // The policies say no...
        $this->assertFalse((new AiGenerationPolicy)->update($superAdmin, $generation));
        $this->assertFalse((new AiGenerationPolicy)->delete($superAdmin, $generation));
        $this->assertFalse((new AiApprovalPolicy)->update($superAdmin, $approval));
        $this->assertFalse((new AiApprovalPolicy)->delete($superAdmin, $approval));
        $this->assertFalse((new AiPromptVersionPolicy)->delete($superAdmin, $prompt));

        // `delete` IS a guarded ability, so Gate::before falls through and
        // the policy's refusal stands.
        $this->assertFalse($superAdmin->can('delete', $generation));

        // `update` is NOT, so Gate::before grants it to a Super Admin before
        // the policy is consulted, and the policy above is a statement of
        // intent rather than a guarantee. That gap is precisely why the
        // models carry the guards - the tests in AiProvenanceTest and
        // AiSelfApprovalTest exercise them.
        $this->assertTrue($superAdmin->can('update', $generation));
    }

    #[Test]
    public function no_customer_authentication_concept_appears_anywhere_in_the_ai_domain(): void
    {
        // Customer != User, and AI does not change that. A generation names a
        // customer and is initiated by a user; nothing here treats a customer
        // as something that signs in.
        foreach ($this->sourcesIn(app_path('Domain/Ai')) as $file) {
            $source = file_get_contents($file);

            foreach (['customer_users', 'customerLogin', 'Auth::guard(\'customer', 'customer_password'] as $needle) {
                $this->assertStringNotContainsString($needle, $source);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function sourcesIn(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
