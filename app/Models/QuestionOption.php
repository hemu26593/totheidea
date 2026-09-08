<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToImmutableFormVersion;
use Database\Factories\QuestionOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A selectable choice.
 *
 * score_value is NULL for every categorical option. The Average / Good /
 * Better / Best set is categorical and the client defined no numeric mapping;
 * populating score_value for them would invent Average = 1 ... Best = 4.
 * FormBuilderService refuses it and a test proves it.
 *
 * Because options live inside an immutable version, the choice a participant
 * selected is preserved automatically - editing "the current options" is
 * impossible without publishing a new version.
 */
#[Fillable(['question_id', 'value', 'label', 'label_secondary', 'score_value', 'position'])]
class QuestionOption extends Model
{
    use BelongsToImmutableFormVersion;

    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_value' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function owningFormVersion(): ?FormVersion
    {
        return $this->question()->first()?->owningFormVersion();
    }
}
