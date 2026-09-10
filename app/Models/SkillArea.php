<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SkillAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the 16 skill areas behind the heat map.
 *
 * Reference data the client can rename. `key` is the stable identity, so a
 * renamed `name` does not break scoring or historical reports.
 */
#[Fillable(['key', 'name', 'description', 'position'])]
class SkillArea extends Model
{
    /** @use HasFactory<SkillAreaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
