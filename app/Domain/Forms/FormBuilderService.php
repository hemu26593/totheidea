<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Enums\QuestionType;
use App\Enums\ScoringCategory;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SkillArea;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authoring a draft form version: sections, questions and options.
 *
 * Two invariants live here because neither can be a database constraint:
 *
 *   1. A question's section must belong to the SAME form version. Cross-row,
 *      so no foreign key can express it. Without it a question from version 1
 *      could be displayed inside version 2's section, and both versions'
 *      meaning would quietly become undefined.
 *
 *   2. Categorical options carry score_value NULL. The Average / Good /
 *      Better / Best set has no numeric mapping, and inventing one here would
 *      turn a categorical answer into a score.
 *
 * Structural immutability after publication is enforced on the models, not
 * here, so it holds for every caller.
 */
class FormBuilderService
{
    public function __construct(private readonly QuestionBankResolver $bank) {}

    public function createTemplate(array $attributes): FormTemplate
    {
        $this->assertKeyIsUniqueInScope($attributes['key'], $attributes['customer_id'] ?? null);

        return FormTemplate::create($attributes);
    }

    /**
     * Start a new draft version of a template.
     *
     * The version number continues the template's sequence, so history reads
     * in order and UNIQUE (form_template_id, version_number) never collides.
     */
    public function createDraftVersion(FormTemplate $template): FormVersion
    {
        return DB::transaction(function () use ($template): FormVersion {
            $next = (int) $template->versions()->max('version_number') + 1;

            $version = new FormVersion;
            $version->forceFill([
                'form_template_id' => $template->getKey(),
                'version_number' => $next,
                'status' => 'draft',
            ])->save();

            return $version->fresh();
        });
    }

    public function addSection(FormVersion $version, string $title, int $position, ?string $description = null): FormSection
    {
        $this->assertDraft($version);

        return FormSection::create([
            'form_version_id' => $version->getKey(),
            'title' => $title,
            'description' => $description,
            'position' => $position,
        ]);
    }

    /**
     * Add a question to a draft version, inside one of its own sections.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addQuestion(
        FormVersion $version,
        FormSection $section,
        QuestionType $type,
        string $label,
        int $position,
        array $attributes = [],
    ): Question {
        $this->assertDraft($version);
        $this->assertSectionBelongsToVersion($section, $version);

        if (isset($attributes['skill_area_id'])) {
            $this->assertSkillAreaExists((int) $attributes['skill_area_id']);
        }

        return Question::create(array_merge($attributes, [
            'form_version_id' => $version->getKey(),
            'form_section_id' => $section->getKey(),
            'type' => $type,
            'label' => $label,
            'position' => $position,
        ]));
    }

    /**
     * Add a selectable option to a question.
     *
     * $scoreValue is refused for any label in the categorical scoring set.
     */
    public function addOption(
        Question $question,
        string $value,
        string $label,
        int $position,
        ?float $scoreValue = null,
        ?string $labelSecondary = null,
    ): QuestionOption {
        $this->assertScoreValueIsPermitted($label, $scoreValue);

        return QuestionOption::create([
            'question_id' => $question->getKey(),
            'value' => $value,
            'label' => $label,
            'label_secondary' => $labelSecondary,
            'score_value' => $scoreValue,
            'position' => $position,
        ]);
    }

    /**
     * The four categorical values, added with score_value deliberately unset.
     *
     * @return array<int, QuestionOption>
     */
    public function addScoringCategoryOptions(Question $question): array
    {
        $options = [];

        foreach (ScoringCategory::cases() as $index => $case) {
            $options[] = $this->addOption($question, strtolower($case->value), $case->value, $index);
        }

        return $options;
    }

    public function questionBank(): QuestionBankResolver
    {
        return $this->bank;
    }

    private function assertDraft(FormVersion $version): void
    {
        if (! $version->isDraft()) {
            throw new RuntimeException(
                "Form version {$version->getKey()} is {$version->status} and cannot be edited. Create a new version."
            );
        }
    }

    /**
     * The invariant a foreign key cannot express.
     */
    private function assertSectionBelongsToVersion(FormSection $section, FormVersion $version): void
    {
        if ((int) $section->form_version_id !== (int) $version->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Section %d belongs to form version %d, not %d. A question cannot be grouped under another version\'s section.',
                $section->getKey(),
                $section->form_version_id,
                $version->getKey(),
            ));
        }
    }

    private function assertSkillAreaExists(int $skillAreaId): void
    {
        if (! SkillArea::query()->whereKey($skillAreaId)->exists()) {
            throw new InvalidArgumentException("Skill area {$skillAreaId} does not exist.");
        }
    }

    /**
     * Categorical values have no numeric mapping and never gain one here.
     */
    private function assertScoreValueIsPermitted(string $label, ?float $scoreValue): void
    {
        if ($scoreValue === null) {
            return;
        }

        if (ScoringCategory::tryFrom($label) !== null) {
            throw new InvalidArgumentException(sprintf(
                'Option [%s] is a scoring category and must not carry a numeric score. '
                .'Average / Good / Better / Best are categorical; the client defined no numeric mapping.',
                $label,
            ));
        }
    }

    private function assertKeyIsUniqueInScope(string $key, ?int $customerId): void
    {
        $exists = FormTemplate::query()
            ->where('key', $key)
            ->when($customerId === null,
                fn ($q) => $q->whereNull('customer_id'),
                fn ($q) => $q->where('customer_id', $customerId),
            )
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException(
                "A form template with key [{$key}] already exists in this scope."
            );
        }
    }
}
