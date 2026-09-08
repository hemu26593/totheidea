<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Domain\Scoring\ScoringService;
use App\Enums\QuestionType;
use App\Enums\ScoringCategory;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\SkillArea;
use App\Models\SubmissionScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Deterministic scoring.
 *
 * Laravel is authoritative for every number that reaches a report. These
 * tests pin the two rules the SOW actually defines, and - just as importantly
 * - pin the absence of the ones it does not.
 */
class ScoringTest extends TestCase
{
    use RefreshDatabase;

    private FormBuilderService $builder;

    private FormPublishingService $publishing;

    private SubmissionService $submissions;

    private ScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->builder = app(FormBuilderService::class);
        $this->publishing = app(FormPublishingService::class);
        $this->submissions = app(SubmissionService::class);
        $this->scoring = app(ScoringService::class);
    }

    /**
     * A scored instrument of $count questions, each worth one point, with a
     * Yes option worth 1 and a No option worth 0.
     */
    private function scoredForm(int $count, QuestionType $type = QuestionType::SelectOne): array
    {
        $template = FormTemplate::factory()->scored()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);

        $questions = [];

        for ($i = 0; $i < $count; $i++) {
            $question = $this->builder->addQuestion(
                $version, $section, $type, "Question {$i}", $i, ['max_score' => 1.0],
            );

            if ($type === QuestionType::SelectOne) {
                $this->builder->addOption($question, 'yes', 'Yes', 0, scoreValue: 1.0);
                $this->builder->addOption($question, 'no', 'No', 1, scoreValue: 0.0);
            }

            $questions[] = $question->fresh();
        }

        $this->publishing->publish($version->fresh(), $this->admin());

        return ['version' => $version->fresh(), 'questions' => $questions];
    }

    // --- Rule 1: the 35-question assessment ---------------------------------

    #[Test]
    public function the_thirty_five_question_assessment_scores_out_of_thirty_five(): void
    {
        $form = $this->scoredForm(35);
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $form['version'], $this->admin(),
        );

        // Twenty correct out of thirty-five.
        foreach ($form['questions'] as $index => $question) {
            $option = $question->options()->where('value', $index < 20 ? 'yes' : 'no')->firstOrFail();
            $this->submissions->answer($submission, $question, [], [$option]);
        }

        $score = $this->scoring->scoreOverall($submission->fresh());

        $this->assertSame('20.00', $score->raw_score);
        $this->assertSame('35.00', $score->max_score);
        $this->assertSame('57.14', $score->percentage);
        $this->assertSame('overall', $score->score_type);
    }

    #[Test]
    public function an_unscored_instrument_produces_no_invented_score(): void
    {
        // Preserve the data; invent no number.
        $template = FormTemplate::factory()->scored()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $this->builder->addQuestion($version, $section, QuestionType::Text, 'Describe your goal', 0);
        $this->publishing->publish($version->fresh(), $this->admin());

        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $version->fresh(), $this->admin(),
        );

        $this->assertNull($this->scoring->scoreOverall($submission));
        $this->assertSame(0, $submission->fresh()->scores()->count());
    }

    #[Test]
    public function a_template_that_is_not_a_scored_instrument_is_refused(): void
    {
        $template = FormTemplate::factory()->create(); // is_scored false
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $this->builder->addQuestion($version, $section, QuestionType::Text, 'Q', 0);
        $this->publishing->publish($version->fresh(), $this->admin());

        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $version->fresh(), $this->admin(),
        );

        $this->expectException(RuntimeException::class);

        $this->scoring->score($submission);
    }

    // --- Rule 2: the 18-question check ---------------------------------------

    #[Test]
    public function the_eighteen_question_check_counts_yes_answers(): void
    {
        $form = $this->scoredForm(18, QuestionType::Boolean);
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $form['version'], $this->admin(),
        );

        foreach ($form['questions'] as $index => $question) {
            $this->submissions->answer($submission, $question, ['value_boolean' => $index < 12]);
        }

        $score = $this->scoring->scoreYesCount($submission->fresh());

        $this->assertSame('yes_count', $score->score_type);
        $this->assertSame('12.00', $score->raw_score);
        $this->assertSame('18.00', $score->max_score);
    }

    #[Test]
    public function the_ten_or_more_rule_is_applied_as_a_threshold(): void
    {
        $form = $this->scoredForm(18, QuestionType::Boolean);
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $form['version'], $this->admin(),
        );

        foreach ($form['questions'] as $index => $question) {
            $this->submissions->answer($submission, $question, ['value_boolean' => $index < 10]);
        }

        $this->scoring->scoreYesCount($submission->fresh());

        $this->assertSame(10, ScoringService::YES_COUNT_THRESHOLD);
        $this->assertTrue($this->scoring->meetsYesCountThreshold($submission->fresh()));
    }

    #[Test]
    public function nine_yes_answers_do_not_meet_the_threshold(): void
    {
        $form = $this->scoredForm(18, QuestionType::Boolean);
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $form['version'], $this->admin(),
        );

        foreach ($form['questions'] as $index => $question) {
            $this->submissions->answer($submission, $question, ['value_boolean' => $index < 9]);
        }

        $this->scoring->scoreYesCount($submission->fresh());

        $this->assertFalse($this->scoring->meetsYesCountThreshold($submission->fresh()));
    }

    #[Test]
    public function the_consequence_of_crossing_the_threshold_is_not_invented(): void
    {
        // The SOW states the threshold but not what follows from it, so
        // nothing is persisted that would imply a consequence.
        $columns = Schema::getColumnListing('submission_scores');

        foreach (['passed', 'outcome', 'band', 'rating', 'verdict', 'threshold_met'] as $invented) {
            $this->assertNotContains($invented, $columns);
        }
    }

    // --- Rule 3: per-skill-area scores ---------------------------------------

    #[Test]
    public function per_skill_area_scores_are_computed(): void
    {
        $areaA = SkillArea::factory()->create(['key' => 'finance']);
        $areaB = SkillArea::factory()->create(['key' => 'marketing']);

        $template = FormTemplate::factory()->scored()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);

        $questions = [];
        foreach ([$areaA, $areaA, $areaB] as $index => $area) {
            $q = $this->builder->addQuestion(
                $version, $section, QuestionType::Number, "Q{$index}", $index,
                ['max_score' => 5.0, 'skill_area_id' => $area->getKey()],
            );
            $questions[] = $q;
        }

        $this->publishing->publish($version->fresh(), $this->admin());
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $version->fresh(), $this->admin(),
        );

        $this->submissions->answer($submission, $questions[0], ['value_number' => 4]);
        $this->submissions->answer($submission, $questions[1], ['value_number' => 3]);
        $this->submissions->answer($submission, $questions[2], ['value_number' => 5]);

        $scores = $this->scoring->scoreSkillAreas($submission->fresh())->keyBy('skill_area_id');

        $this->assertSame('7.00', $scores[$areaA->getKey()]->raw_score);
        $this->assertSame('10.00', $scores[$areaA->getKey()]->max_score);
        $this->assertSame('5.00', $scores[$areaB->getKey()]->raw_score);
    }

    #[Test]
    public function heat_map_bands_are_not_invented(): void
    {
        // The SOW promises four scoring engines and defines two. Band
        // thresholds are deferred item L2, so the method refuses rather than
        // putting a fabricated judgement in front of the client.
        $score = SubmissionScore::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->scoring->bandFor($score);
    }

    // --- Scoring categories carry no number ----------------------------------

    #[Test]
    public function a_categorical_answer_contributes_no_score(): void
    {
        $template = FormTemplate::factory()->scored()->create();
        $version = $this->builder->createDraftVersion($template);
        $section = $this->builder->addSection($version, 'S', 0);
        $question = $this->builder->addQuestion(
            $version, $section, QuestionType::SelectOne, 'Evaluation', 0, ['max_score' => 1.0],
        );
        $options = $this->builder->addScoringCategoryOptions($question->fresh());

        $this->publishing->publish($version->fresh(), $this->admin());
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $version->fresh(), $this->admin(),
        );

        // "Best" is selected - and contributes nothing, because it is an
        // answer rather than a score.
        $best = collect($options)->firstWhere('label', ScoringCategory::Best->value);
        $this->submissions->answer($submission, $question->fresh(), [], [$best]);

        $score = $this->scoring->scoreOverall($submission->fresh());

        $this->assertSame('0.00', $score->raw_score);
    }

    #[Test]
    public function submission_scores_never_hold_a_category_label(): void
    {
        // It is a numeric table. Copying a category here would create a
        // second source of truth and invite the forbidden mapping.
        $columns = Schema::getColumnListing('submission_scores');

        $this->assertNotContains('category', $columns);
        $this->assertNotContains('scoring_category', $columns);
        $this->assertNotContains('label', $columns);
    }

    #[Test]
    public function the_scoring_service_exposes_no_numeric_mapping_for_categories(): void
    {
        foreach (['categoryScore', 'categoryValue', 'categoryToNumber', 'rankOf'] as $forbidden) {
            $this->assertFalse(method_exists(ScoringService::class, $forbidden));
        }

        foreach (['score', 'weight', 'points', 'rank', 'ordinal'] as $forbidden) {
            $this->assertFalse(method_exists(ScoringCategory::class, $forbidden));
        }
    }

    // --- Only writer, append-only --------------------------------------------

    #[Test]
    public function scores_cannot_be_written_outside_the_scoring_service(): void
    {
        $submission = FormSubmission::factory()->create();

        $this->expectException(RuntimeException::class);

        // A component, controller, observer or AI service reaching for the
        // model directly gets this.
        (new SubmissionScore)->forceFill([
            'form_submission_id' => $submission->getKey(),
            'score_type' => 'overall',
            'raw_score' => 99,
            'max_score' => 35,
            'scheme_version' => 'hand-written',
            'computed_at' => now(),
        ])->save();
    }

    #[Test]
    public function the_policy_refuses_to_let_staff_or_admin_author_a_score(): void
    {
        $score = SubmissionScore::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('create', SubmissionScore::class));
            $this->assertFalse($this->{$role}()->can('update', $score));
        }
    }

    #[Test]
    public function nobody_can_delete_a_score_not_even_a_super_admin(): void
    {
        // 'delete' IS a guarded ability, so Gate::before falls through to the
        // policy even for a Super Admin.
        $score = SubmissionScore::factory()->create();

        foreach (['superAdmin', 'admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('delete', $score));
        }
    }

    #[Test]
    public function the_write_guard_does_not_depend_on_a_policy(): void
    {
        // 'create' and 'update' are NOT guarded abilities, so Gate::before
        // grants them to a Super Admin regardless of what the policy returns.
        // That is why the real guarantee is the model's write gate: it holds
        // for every caller, including one the gate has already waved through.
        $submission = FormSubmission::factory()->create();

        $this->assertTrue($this->superAdmin()->can('create', SubmissionScore::class));

        $this->expectException(RuntimeException::class);

        (new SubmissionScore)->forceFill([
            'form_submission_id' => $submission->getKey(),
            'score_type' => 'overall',
            'raw_score' => 99,
            'max_score' => 35,
            'scheme_version' => 'super-admin-hand-written',
            'computed_at' => now(),
        ])->save();
    }

    #[Test]
    public function a_score_is_immutable_once_written(): void
    {
        $score = SubmissionScore::factory()->create();

        $this->expectException(RuntimeException::class);

        $score->update(['raw_score' => 99]);
    }

    #[Test]
    public function a_score_cannot_be_deleted(): void
    {
        $score = SubmissionScore::factory()->create();

        $this->expectException(RuntimeException::class);

        $score->delete();
    }

    #[Test]
    public function recomputation_appends_and_leaves_the_delivered_score_intact(): void
    {
        // The diagnostic report is handed over before Session 1; a later rule
        // correction must not rewrite what was given.
        $form = $this->scoredForm(3);
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $form['version'], $this->admin(),
        );

        foreach ($form['questions'] as $question) {
            $option = $question->options()->where('value', 'yes')->firstOrFail();
            $this->submissions->answer($submission, $question, [], [$option]);
        }

        $first = $this->scoring->scoreOverall($submission->fresh());

        // The participant corrects an answer, and the score is recomputed.
        $changed = $form['questions'][0];
        $this->submissions->answer(
            $submission->fresh(), $changed, [], [$changed->options()->where('value', 'no')->firstOrFail()],
        );
        $second = $this->scoring->scoreOverall($submission->fresh());

        $this->assertSame(2, $submission->fresh()->scores()->where('score_type', 'overall')->count());
        // The originally delivered figure is untouched.
        $this->assertSame('3.00', $first->fresh()->raw_score);
        $this->assertSame('2.00', $second->raw_score);
        // "Current" is the newest row.
        $this->assertTrue($this->scoring->currentScore($submission->fresh(), 'overall')->is($second));
    }

    #[Test]
    public function every_score_records_which_rules_produced_it(): void
    {
        $form = $this->scoredForm(2);
        $submission = $this->submissions->startDraft(
            Enrollment::factory()->create(), $form['version'], $this->admin(),
        );

        $score = $this->scoring->scoreOverall($submission->fresh());

        $this->assertSame(ScoringService::SCHEME_VERSION, $score->scheme_version);
        $this->assertNotNull($score->computed_at);
    }

    #[Test]
    public function the_scoring_service_is_the_only_class_that_opens_the_write_gate(): void
    {
        // writeThrough is the single door. Anything else calling it would show
        // up here.
        $callers = [];

        foreach ([
            app_path('Domain'), app_path('Services'), app_path('Models'), app_path('Policies'),
        ] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && str_contains((string) file_get_contents($file->getPathname()), 'writeThrough(')) {
                    $callers[] = basename($file->getPathname());
                }
            }
        }

        sort($callers);

        $this->assertSame(['ScoringService.php', 'SubmissionScore.php'], $callers);
    }

    #[Test]
    public function the_reflection_of_the_score_writer_confirms_it_is_private(): void
    {
        $append = (new ReflectionClass(ScoringService::class))->getMethod('append');

        $this->assertTrue($append->isPrivate(), 'The row writer must not be callable from outside.');
    }
}
