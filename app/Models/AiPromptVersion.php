<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiPromptStatus;
use Database\Factories\AiPromptVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A versioned prompt.
 *
 * IMMUTABLE ONCE PUBLISHED. Generations point here to explain themselves;
 * rewriting a published prompt would silently rewrite the provenance of every
 * one of them. Archiving is the one change a published prompt permits.
 *
 * Enforced on the model rather than only in a policy, because a policy cannot
 * make an invariant absolute: `update` is not among
 * authorization.guarded_abilities, so Gate::before grants it to a Super Admin
 * and the policy is never consulted.
 *
 * NOT CUSTOMER DATA, AND NEVER CONTAINS ANY. The template carries placeholders
 * only; customer context is assembled at call time and lives in
 * ai_generations.input_context. assertCarriesNoCustomerData() states that in
 * code, and a test exercises it.
 *
 * Mass-assignment note: nothing about publication is fillable. Publishing goes
 * through PromptVersionService, which holds the one-published-version rule.
 */
#[Fillable([])]
class AiPromptVersion extends Model
{
    /** @use HasFactory<AiPromptVersionFactory> */
    use HasFactory;

    /**
     * Attributes that may still change after publication.
     *
     * Archiving a published prompt is permitted; rewriting it is not.
     *
     * @var array<int, string>
     */
    private const MUTABLE_AFTER_PUBLICATION = ['status', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'status' => AiPromptStatus::class,
            'output_schema' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AiPromptVersion $prompt): void {
            // getRawOriginal, not getOriginal: the attribute is cast to an
            // enum, so getOriginal returns an AiPromptStatus and the string
            // comparison would silently never match.
            if ($prompt->getRawOriginal('status') === AiPromptStatus::Draft->value) {
                return;
            }

            $rewritten = array_diff(array_keys($prompt->getDirty()), self::MUTABLE_AFTER_PUBLICATION);

            if ($rewritten !== []) {
                throw new RuntimeException(sprintf(
                    'Prompt version %d is %s and cannot be rewritten (%s). Every generation naming it '
                    .'would silently change meaning. Create the next version instead.',
                    $prompt->getKey(),
                    (string) $prompt->getRawOriginal('status'),
                    implode(', ', $rewritten),
                ));
            }
        });

        static::deleting(function (AiPromptVersion $prompt): never {
            throw new RuntimeException(
                'A prompt version is never deleted: generations bind to it as the record of what was asked.'
            );
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function isDraft(): bool
    {
        return $this->status === AiPromptStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === AiPromptStatus::Published;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', AiPromptStatus::Published->value);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    /**
     * Whether the template still reads as a template.
     *
     * A prompt with a business's name, figures or text baked into it has
     * stopped being system vocabulary and become customer data in a table
     * with no customer_id - which is exactly the shape that leaks. The check
     * is a guard against an obvious authoring mistake, not a content filter:
     * it looks for the marker of the mistake, an interpolated value where a
     * placeholder belongs.
     */
    public function assertCarriesNoCustomerData(): void
    {
        if (preg_match('/\{\{\s*[a-z0-9_.]+\s*\}\}/i', (string) $this->template) !== 1
            && str_contains((string) $this->template, '{{')) {
            throw new RuntimeException(
                'A prompt template contains a malformed placeholder. Customer context is substituted '
                .'at call time; a template must carry {{ placeholders }} and never a business\'s own text.'
            );
        }
    }
}
