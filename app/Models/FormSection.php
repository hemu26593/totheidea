<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToImmutableFormVersion;
use Database\Factories\FormSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Display grouping of questions within a version. Immutable once the version
 * is published.
 */
#[Fillable(['form_version_id', 'title', 'description', 'position'])]
class FormSection extends Model
{
    use BelongsToImmutableFormVersion;

    /** @use HasFactory<FormSectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function owningFormVersion(): ?FormVersion
    {
        return $this->formVersion()->first();
    }
}
