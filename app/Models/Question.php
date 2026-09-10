<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToImmutableFormVersion;
use App\Enums\QuestionType;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question. TWO MANDATORY PARENTS: the version it belongs to, and the
 * section it is displayed in.
 *
 * The cross-row rule that the section must belong to the SAME version cannot
 * be a database constraint, so FormBuilderService asserts it on write and a
 * test proves a cross-version attachment is refused.
 *
 * compute_expression is evaluated in PHP by application code. It is never
 * executed as generated code and never evaluated by AI.
 */
#[Fillable([
    'form_version_id', 'form_section_id', 'bank_key', 'skill_area_id',
    'type', 'label', 'label_secondary', 'help_text', 'help_text_secondary',
    'is_required', 'max_score', 'visible_when', 'compute_expression', 'position',
])]
class Question extends Model
{
    use BelongsToImmutableFormVersion;

    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'is_required' => 'boolean',
            'max_score' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function formSection(): BelongsTo
    {
        return $this->belongsTo(FormSection::class);
    }

    public function skillArea(): BelongsTo
    {
        return $this->belongsTo(SkillArea::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function owningFormVersion(): ?FormVersion
    {
        return $this->formVersion()->first();
    }

    public function usesOptions(): bool
    {
        return $this->type instanceof QuestionType && $this->type->usesOptions();
    }
}
