<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\AuditAction;
use App\Enums\QuestionType;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The critical historical rule: a published form version never changes.
 *
 * A submission binds to a version, so the version's structure IS the record
 * of what was asked. If a published version could still be edited, every
 * historical submission would silently change meaning with it - a January
 * answer read against a March question.
 */
class FormVersioningTest extends TestCase
{
    use RefreshDatabase;

    private FormBuilderService $builder;

    private FormPublishingService $publishing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->builder = app(FormBuilderService::class);
        $this->publishing = app(FormPublishingService::class);
    }

    private function draftWithOneQuestion(?FormTemplate $template = null): FormVersion
    {
        $template ??= FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'Section A', 0);
        $this->builder->addQuestion($version, $section, QuestionType::Text, 'Your goal?', 0);

        return $version->fresh();
    }

    // --- Draft and publish -------------------------------------------------

    #[Test]
    public function a_new_version_starts_as_a_draft(): void
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());

        $this->assertTrue($version->isDraft());
        $this->assertSame(1, $version->version_number);
        $this->assertNull($version->published_at);
    }

    #[Test]
    public function version_numbers_continue_the_templates_sequence(): void
    {
        $template = FormTemplate::factory()->create();

        $this->assertSame(1, $this->builder->createDraftVersion($template)->version_number);
        $this->assertSame(2, $this->builder->createDraftVersion($template)->version_number);
        $this->assertSame(3, $this->builder->createDraftVersion($template)->version_number);
    }

    #[Test]
    public function publishing_records_who_and_when_and_is_audited(): void
    {
        $actor = $this->admin();
        $version = $this->draftWithOneQuestion();

        $published = $this->publishing->publish($version, $actor);

        $this->assertTrue($published->isPublished());
        $this->assertNotNull($published->published_at);
        $this->assertSame($actor->getKey(), $published->published_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::FormVersionPublished->value,
            'auditable_id' => $version->getKey(),
        ]);
    }

    #[Test]
    public function an_empty_version_cannot_be_published(): void
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());

        $this->expectException(RuntimeException::class);

        $this->publishing->publish($version, $this->admin());
    }

    #[Test]
    public function a_template_has_at_most_one_published_version(): void
    {
        // Not a partial unique index - those are not portable - so this is the
        // service's invariant and it needs proving.
        $template = FormTemplate::factory()->create();
        $actor = $this->admin();

        $first = $this->draftWithOneQuestion($template);
        $this->publishing->publish($first, $actor);

        $second = $this->draftWithOneQuestion($template);
        $this->publishing->publish($second, $actor);

        $this->assertSame(
            1,
            FormVersion::query()->where('form_template_id', $template->getKey())->where('status', 'published')->count(),
        );
        $this->assertSame('archived', $first->fresh()->status);
        $this->assertTrue($second->fresh()->isPublished());
    }

    // --- Immutability ------------------------------------------------------

    #[Test]
    public function a_published_version_cannot_be_rewritten(): void
    {
        $version = $this->draftWithOneQuestion();
        $this->publishing->publish($version, $this->admin());

        $this->expectException(RuntimeException::class);

        $version->fresh()->forceFill(['version_number' => 99])->save();
    }

    #[Test]
    public function a_published_version_cannot_gain_a_question(): void
    {
        $version = $this->draftWithOneQuestion();
        $section = $version->sections()->firstOrFail();
        $this->publishing->publish($version, $this->admin());

        $this->expectException(RuntimeException::class);

        $this->builder->addQuestion($version->fresh(), $section, QuestionType::Text, 'Sneaky addition', 1);
    }

    #[Test]
    public function a_question_in_a_published_version_cannot_be_edited(): void
    {
        $version = $this->draftWithOneQuestion();
        $question = $version->questions()->firstOrFail();
        $this->publishing->publish($version, $this->admin());

        $this->expectException(RuntimeException::class);

        $question->fresh()->update(['label' => 'Reworded in March']);
    }

    #[Test]
    public function an_option_in_a_published_version_cannot_be_edited(): void
    {
        $template = FormTemplate::factory()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Pick one', 0);
        $option = $this->builder->addOption($question, 'a', 'Option A', 0);

        $this->publishing->publish($version->fresh(), $this->admin());

        $this->expectException(RuntimeException::class);

        $option->fresh()->update(['label' => 'Changed after the fact']);
    }

    #[Test]
    public function a_section_in_a_published_version_cannot_be_reordered(): void
    {
        $version = $this->draftWithOneQuestion();
        $section = $version->sections()->firstOrFail();
        $this->publishing->publish($version, $this->admin());

        $this->expectException(RuntimeException::class);

        $section->fresh()->update(['position' => 5]);
    }

    #[Test]
    public function a_form_version_is_never_deleted(): void
    {
        $version = $this->draftWithOneQuestion();

        $this->expectException(RuntimeException::class);

        $version->delete();
    }

    #[Test]
    public function archiving_is_the_one_change_a_published_version_permits(): void
    {
        $version = $this->draftWithOneQuestion();
        $this->publishing->publish($version, $this->admin());

        $version->fresh()->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->assertSame('archived', $version->fresh()->status);
    }

    #[Test]
    public function immutability_does_not_depend_on_a_policy(): void
    {
        // 'update' is not a guarded ability, so Gate::before grants it to a
        // Super Admin. The guarantee therefore lives on the model, where
        // nothing bypasses it.
        $version = $this->draftWithOneQuestion();
        $question = $version->questions()->firstOrFail();
        $this->publishing->publish($version, $this->admin());

        $this->assertTrue($this->superAdmin()->can('forms.edit'));

        $this->expectException(RuntimeException::class);

        $question->fresh()->update(['label' => 'Super Admin edit']);
    }

    // --- Changing a form ---------------------------------------------------

    #[Test]
    public function changing_a_form_means_creating_a_new_version(): void
    {
        $template = FormTemplate::factory()->create();
        $actor = $this->admin();

        $v1 = $this->draftWithOneQuestion($template);
        $this->publishing->publish($v1, $actor);

        $v2 = $this->publishing->startNextVersion($template);
        $section = $this->builder->addSection($v2, 'Revised section', 0);
        $this->builder->addQuestion($v2, $section, QuestionType::Text, 'A better question', 0);

        $this->assertSame(2, $v2->fresh()->version_number);
        $this->assertTrue($v2->fresh()->isDraft());
        // Version 1 is untouched.
        $this->assertSame('Your goal?', $v1->fresh()->questions()->firstOrFail()->label);
    }

    #[Test]
    public function a_new_submission_resolves_only_the_published_version(): void
    {
        $template = FormTemplate::factory()->create();
        $version = $this->draftWithOneQuestion($template);
        $this->publishing->publish($version, $this->admin());

        $this->assertTrue($this->publishing->versionForNewSubmission($template)->is($version));
    }

    #[Test]
    public function a_template_with_no_published_version_cannot_be_submitted_against(): void
    {
        $template = FormTemplate::factory()->create();
        $this->builder->createDraftVersion($template);

        $this->expectException(RuntimeException::class);

        $this->publishing->versionForNewSubmission($template);
    }
}
