<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FormTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named form.
 *
 * customer_id null means a global template; non-null means it belongs to one
 * business. That is the only customer relationship - the template is not
 * itself customer data when global.
 */
#[Fillable(['customer_id', 'key', 'name', 'description', 'is_scored'])]
class FormTemplate extends Model
{
    /** @use HasFactory<FormTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_scored' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    public function isGlobal(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * The single published version, if there is one. Resolving "the current
     * version" is only ever for NEW submissions - an existing submission
     * always reads through its own stored version.
     */
    public function publishedVersion(): ?FormVersion
    {
        return $this->versions()->where('status', 'published')->first();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
