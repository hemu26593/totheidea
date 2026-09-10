<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Forms\FormBuilderService;
use App\Enums\AiGenerationStatus;
use App\Enums\QuestionType;
use App\Exceptions\CustomerIsolationException;
use App\Models\AiGeneration;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * DRAFT. The third stage: validated output becomes a draft form version.
 *
 * IT USES THE ORDINARY FORM ENGINE. Every section and question is created
 * through FormBuilderService, which means an AI-authored form is subject to
 * exactly the same invariants as a hand-authored one - a question's section
 * must belong to its own version, categorical options carry no numeric score,
 * and a published version can never be rewritten. There is no parallel AI
 * form system, so an AI-drafted form is answered, scored and reported on by
 * the same code as any other.
 *
 * IT PRODUCES A DRAFT AND STOPS. Publishing is FormPublishingService's job
 * and happens only after a second person approves the generation. This class
 * has no path to a published version.
 *
 * IT NEVER TOUCHES SCORING. score_value is not read from model output at all:
 * the four categorical values have no numeric mapping, that mapping is a
 * client decision nobody has made, and a model proposing one would be
 * inventing a business rule. Options are created through
 * addScoringCategoryOptions() or with score_value null.
 *
 * THE OUTPUT IS DATA THROUGHOUT. Question types are matched against the
 * QuestionType enum by value; an unrecognised type is refused. No string from
 * a model becomes a class name, a callable, a column name or a query
 * fragment.
 */
class AiFormDraftService
{
    /**
     * The schema every form_draft prompt version must declare.
     *
     * Kept here, beside the code that consumes it, so the shape the model is
     * asked for and the shape this class reads cannot drift apart. The
     * permitted question types are read from the QuestionType enum rather
     * than written out again, so a type this application does not have can
     * never be asked for.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'sections'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                'sections' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'questions'],
                        'properties' => [
                            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                            'description' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                            'questions' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => 50,
                                'items' => self::questionSchema(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * One proposed question.
     *
     * Note what is ABSENT and cannot be proposed: skill_area_id, max_score,
     * compute_expression, visible_when. A model does not assign a question to
     * a skill area, does not set what it is worth, and above all does not
     * supply an expression - compute_expression is the one field on this
     * table that reads like code, and nothing generated ever reaches it.
     *
     * @return array<string, mixed>
     */
    private static function questionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['label', 'type'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'help_text' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                'type' => ['type' => 'string', 'enum' => QuestionType::values()],
                'is_required' => ['type' => 'boolean'],
                'options' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['label'],
                        'properties' => [
                            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                            'value' => ['type' => 'string', 'maxLength' => 60, 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function __construct(private readonly FormBuilderService $builder) {}

    /**
     * Turn one succeeded generation into a draft version of a template.
     *
     * The generation moves to awaiting_approval: there is now something for a
     * person to look at, and the person who looks at it will not be the
     * person who asked for it.
     */
    public function draftFrom(AiGeneration $generation, FormTemplate $template): FormVersion
    {
        $this->assertUsable($generation);
        $this->assertTemplateIsInScope($generation, $template);

        /** @var array<string, mixed> $output */
        $output = $generation->validated_output;

        return DB::transaction(function () use ($generation, $template, $output): FormVersion {
            $version = $this->builder->createDraftVersion($template);

            $this->buildSections($version, (array) ($output['sections'] ?? []));

            $generation->forceFill([
                'resulting_form_version_id' => $version->getKey(),
                'status' => AiGenerationStatus::AwaitingApproval,
            ])->save();

            return $version->fresh();
        });
    }

    /**
     * @param  array<int, mixed>  $sections
     */
    private function buildSections(FormVersion $version, array $sections): void
    {
        $questionPosition = 0;

        foreach (array_values($sections) as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw new InvalidArgumentException('Each proposed section must be an object.');
            }

            $created = $this->builder->addSection(
                $version,
                (string) $section['title'],
                $sectionIndex,
                isset($section['description']) ? (string) $section['description'] : null,
            );

            foreach (array_values((array) ($section['questions'] ?? [])) as $question) {
                if (! is_array($question)) {
                    throw new InvalidArgumentException('Each proposed question must be an object.');
                }

                // Positions are assigned by this application, not proposed by
                // the model: questions.position is UNIQUE per version and a
                // duplicate would be a constraint violation rather than a
                // rendering quirk.
                $this->addQuestion($version, $created, $question, $questionPosition++);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    private function addQuestion(FormVersion $version, FormSection $section, array $proposed, int $position): void
    {
        $type = QuestionType::tryFrom((string) $proposed['type']);

        if ($type === null) {
            throw new InvalidArgumentException(sprintf(
                'Proposed question type [%s] is not a question type this application has. '
                .'A model may propose content; it does not extend the vocabulary.',
                (string) $proposed['type'],
            ));
        }

        $question = $this->builder->addQuestion(
            $version,
            $section,
            $type,
            (string) $proposed['label'],
            $position,
            [
                'help_text' => isset($proposed['help_text']) ? (string) $proposed['help_text'] : null,
                'is_required' => (bool) ($proposed['is_required'] ?? false),
            ],
        );

        foreach (array_values((array) ($proposed['options'] ?? [])) as $optionPosition => $option) {
            if (! is_array($option)) {
                continue;
            }

            $label = (string) $option['label'];

            $this->builder->addOption(
                $question,
                isset($option['value']) && $option['value'] !== null
                    ? (string) $option['value']
                    : $this->slug($label),
                $label,
                $optionPosition,
                // Never a score. See the class docblock.
                null,
            );
        }
    }

    /**
     * A machine value derived deterministically from the label, so two runs
     * of the same proposal produce the same option values.
     */
    private function slug(string $label): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '_', mb_strtolower($label)), '_');

        return $slug === '' ? 'option' : mb_substr($slug, 0, 60);
    }

    private function assertUsable(AiGeneration $generation): void
    {
        if ($generation->status !== AiGenerationStatus::Succeeded) {
            throw new RuntimeException(sprintf(
                'Generation %d is [%s] and cannot become a draft. Only output that passed schema '
                .'validation reaches the form engine.',
                (int) $generation->getKey(),
                $generation->status->value,
            ));
        }

        if (! is_array($generation->validated_output)) {
            throw new RuntimeException(sprintf(
                'Generation %d has no validated output. raw_output is a proposal, not a form.',
                (int) $generation->getKey(),
            ));
        }
    }

    /**
     * A customer-specific template may only be drafted from that customer's
     * own generation. A shared template (customer_id null) is programme
     * curriculum and is drafted by staff acting for the programme.
     */
    private function assertTemplateIsInScope(AiGeneration $generation, FormTemplate $template): void
    {
        if ($template->customer_id === null) {
            return;
        }

        if ((int) $template->customer_id !== (int) $generation->customer_id) {
            throw CustomerIsolationException::mismatch(
                "form template {$template->getKey()}",
                "customer {$generation->customer_id}",
                "customer {$template->customer_id}",
            );
        }
    }
}
