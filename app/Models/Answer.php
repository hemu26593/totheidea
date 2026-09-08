<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One typed value for one question in one submission.
 *
 * Typed columns rather than JSON, because scoring aggregates these, the heat
 * map groups them and reports filter on them.
 *
 * The answer's question must belong to the submission's form version. That is
 * a cross-row rule, so SubmissionService asserts it - an answer pointing at a
 * question from another version would silently corrupt both the submission's
 * meaning and its score.
 */
#[Fillable(['value_text', 'value_number', 'value_date', 'value_boolean'])]
class Answer extends Model
{
    /** @use HasFactory<AnswerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_date' => 'date',
            'value_boolean' => 'boolean',
        ];
    }

    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function answerOptions(): HasMany
    {
        return $this->hasMany(AnswerOption::class);
    }

    /**
     * The options actually selected.
     *
     * Read through answer_options rather than through the question's current
     * options, so a historical selection is never reinterpreted.
     */
    public function selectedOptions(): HasMany
    {
        return $this->answerOptions()->with('questionOption');
    }
}
