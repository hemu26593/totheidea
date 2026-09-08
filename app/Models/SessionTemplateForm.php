<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SessionTemplateFormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The attachment of one form template to one session template.
 *
 * is_required is what trigger 2 ("intake forms not filled") reads; an optional
 * worksheet must not generate a daily reminder.
 */
#[Fillable(['session_template_id', 'form_template_id', 'is_required', 'position'])]
class SessionTemplateForm extends Model
{
    /** @use HasFactory<SessionTemplateFormFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function sessionTemplate(): BelongsTo
    {
        return $this->belongsTo(SessionTemplate::class);
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class);
    }
}
