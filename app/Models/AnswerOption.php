<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnswerOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selected choice.
 *
 * The selection points at the exact question_option row, which lives inside
 * an immutable version - so the historical meaning of a choice survives any
 * later change to the form.
 */
#[Fillable([])]
class AnswerOption extends Model
{
    /** @use HasFactory<AnswerOptionFactory> */
    use HasFactory;

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    public function questionOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class);
    }
}
