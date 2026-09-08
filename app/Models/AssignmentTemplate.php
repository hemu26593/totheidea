<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssignmentTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable assignment definition on a session template.
 *
 * default_due_days is an offset from the session, not a date: only a released
 * assignment has a real due date, and that lives on the instance.
 */
#[Fillable([
    'session_template_id', 'title', 'instructions',
    'default_due_days', 'requires_attachment', 'position',
])]
class AssignmentTemplate extends Model
{
    /** @use HasFactory<AssignmentTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_due_days' => 'integer',
            'requires_attachment' => 'boolean',
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function sessionTemplate(): BelongsTo
    {
        return $this->belongsTo(SessionTemplate::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(AssignmentInstance::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
