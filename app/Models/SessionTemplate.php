<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SessionTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One session of the curriculum - a row, not a class.
 *
 * Six of these per program describe what sessions 1-6 are. A batch gets six
 * session_instances pointing at them.
 */
#[Fillable(['program_id', 'sequence', 'title', 'theme', 'objectives'])]
class SessionTemplate extends Model
{
    /** @use HasFactory<SessionTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function templateForms(): HasMany
    {
        return $this->hasMany(SessionTemplateForm::class);
    }

    public function assignmentTemplates(): HasMany
    {
        return $this->hasMany(AssignmentTemplate::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(SessionInstance::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
