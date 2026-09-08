<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\QuestionBankResolver;
use App\Domain\Forms\SubmissionService;
use App\Enums\QuestionType;
use App\Enums\ScoringCategory;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\SkillArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A question's two mandatory parents, and the cross-row rule no foreign key
 * can express: its section must belong to the SAME version.
 */
class QuestionInvariantTest extends TestCase
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

    #[Test]
    public function a_question_belongs_to_both_a_version_and_a_section(): void
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);

        $question = $this->builder->addQuestion($version, $section, QuestionType::Text, 'Q', 0);

        $this->assertSame($version->getKey(), $question->form_version_id);
        $this->assertSame($section->getKey(), $question->form_section_id);
        $this->assertTrue($question->formVersion->is($version));
        $this->assertTrue($question->formSection->is($section));
    }

    #[Test]
    public function a_question_cannot_be_grouped_under_another_versions_section(): void
    {
        // The invariant a foreign key cannot express. Without it, version 1's
        // question could be displayed inside version 2's section and both
        // versions' meaning would become undefined.
        $mine = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $theirs = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $foreignSection = $this->builder->addSection($theirs, 'Their section', 0);

        $this->expectException(InvalidArgumentException::class);

        $this->builder->addQuestion($mine, $foreignSection, QuestionType::Text, 'Q', 0);
    }

    #[Test]
    public function a_section_from_a_later_version_of_the_same_template_is_also_refused(): void
    {
        // Same template, different version - still a cross-version pairing.
        $template = FormTemplate::factory()->create();
        $v1 = $this->builder->createDraftVersion($template);
        $v2 = $this->builder->createDraftVersion($template);
        $v2Section = $this->builder->addSection($v2, 'V2 section', 0);

        $this->expectException(InvalidArgumentException::class);

        $this->builder->addQuestion($v1, $v2Section, QuestionType::Text, 'Q', 0);
    }

    #[Test]
    public function the_factory_cannot_produce_a_cross_version_question(): void
    {
        // Factories must generate coherent graphs: the version is derived
        // from the section, so the invalid pairing is unreachable.
        $question = Question::factory()->create();

        $this->assertSame(
            (int) $question->formSection->form_version_id,
            (int) $question->form_version_id,
        );
    }

    #[Test]
    public function a_question_may_reference_a_skill_area(): void
    {
        $area = SkillArea::factory()->create();
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);

        $question = $this->builder->addQuestion(
            $version, $section, QuestionType::Scale, 'Rate this', 0,
            ['skill_area_id' => $area->getKey()],
        );

        $this->assertTrue($question->skillArea->is($area));
    }

    #[Test]
    public function a_nonexistent_skill_area_is_refused(): void
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);

        $this->expectException(InvalidArgumentException::class);

        $this->builder->addQuestion(
            $version, $section, QuestionType::Scale, 'Q', 0, ['skill_area_id' => 9999],
        );
    }

    // --- Scoring categories carry no number --------------------------------

    #[Test]
    public function a_scoring_category_option_cannot_be_given_a_numeric_value(): void
    {
        // Average = 1 ... Best = 4 is exactly the mapping the brief forbids.
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Rate', 0);

        $this->expectException(InvalidArgumentException::class);

        $this->builder->addOption($question, 'best', 'Best', 0, scoreValue: 4.0);
    }

    #[Test]
    public function every_scoring_category_option_is_created_with_a_null_score(): void
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Rate', 0);

        $options = $this->builder->addScoringCategoryOptions($question);

        $this->assertCount(4, $options);

        foreach ($options as $option) {
            $this->assertNull($option->score_value, "[{$option->label}] must carry no numeric score.");
            $this->assertNotNull(ScoringCategory::tryFrom($option->label));
        }
    }

    #[Test]
    public function an_ordinary_option_may_still_carry_a_score(): void
    {
        // The prohibition is specific to the categorical set, not to options
        // in general - numerically-scored questions need it.
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion($version, $section, QuestionType::SelectOne, 'Q', 0);

        $option = $this->builder->addOption($question, 'yes', 'Yes', 0, scoreValue: 1.0);

        $this->assertSame('1.00', $option->score_value);
    }

    // --- Question bank ------------------------------------------------------

    #[Test]
    public function a_repeated_question_is_resolved_from_a_prior_answer(): void
    {
        // SOW module 2: repeated questions across the client's two documents
        // are asked only once.
        $bank = app(QuestionBankResolver::class);
        $enrollment = Enrollment::factory()->create();

        $firstForm = $this->publishedFormWithBankKey('annual_turnover');
        $submissions = app(SubmissionService::class);
        $submission = $submissions->startDraft($enrollment, $firstForm['version'], $this->admin());
        $submissions->answer($submission, $firstForm['question'], ['value_text' => '25 lakh']);

        $this->assertTrue($bank->hasAnswered($enrollment, 'annual_turnover'));
        $this->assertSame('25 lakh', $bank->priorAnswer($enrollment, 'annual_turnover')?->value_text);

        // A second form asks the same logical question.
        $secondForm = $this->publishedFormWithBankKey('annual_turnover');
        $prefill = $bank->prefillFor($enrollment, $secondForm['version']->getKey());

        $this->assertArrayHasKey($secondForm['question']->getKey(), $prefill);
        $this->assertSame('25 lakh', $prefill[$secondForm['question']->getKey()]->value_text);
    }

    #[Test]
    public function an_unanswered_bank_key_resolves_to_nothing(): void
    {
        $bank = app(QuestionBankResolver::class);

        $this->assertFalse($bank->hasAnswered(Enrollment::factory()->create(), 'never_asked'));
    }

    /**
     * @return array{version: FormVersion, question: Question}
     */
    private function publishedFormWithBankKey(string $bankKey): array
    {
        $version = $this->builder->createDraftVersion(FormTemplate::factory()->create());
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion(
            $version, $section, QuestionType::Text, 'Annual turnover?', 0, ['bank_key' => $bankKey],
        );
        $this->publishing->publish($version->fresh(), $this->admin());

        return ['version' => $version->fresh(), 'question' => $question];
    }
}
