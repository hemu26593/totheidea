<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Enums\AiPromptStatus;
use App\Enums\AuditAction;
use App\Models\AiPromptVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Authoring, publishing and resolving prompt versions.
 *
 * Deliberately the same shape as FormPublishingService, because it is the
 * same problem: a numbered history where exactly one version is current, and
 * where published rows are immutable because other records point at them.
 *
 * "AT MOST ONE PUBLISHED VERSION PER KEY" IS ENFORCED HERE. It would be a
 * partial unique index, which is not portable between SQLite and MySQL, so it
 * lives in a transaction instead - which also stops two concurrent publishes
 * from both succeeding.
 */
class PromptVersionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Begin the next draft of a prompt.
     *
     * @param  array<string, mixed>|null  $outputSchema
     */
    public function createDraft(
        string $key,
        string $template,
        User $author,
        ?array $outputSchema = null,
        ?string $modelIdentifier = null,
    ): AiPromptVersion {
        return DB::transaction(function () use ($key, $template, $author, $outputSchema, $modelIdentifier): AiPromptVersion {
            $next = (int) AiPromptVersion::query()->where('key', $key)->max('version_number') + 1;

            $prompt = new AiPromptVersion;
            $prompt->forceFill([
                'key' => $key,
                'version_number' => $next,
                'template' => $template,
                'model_identifier' => $modelIdentifier,
                'output_schema' => $outputSchema,
                'status' => AiPromptStatus::Draft,
                'created_by' => $author->getKey(),
            ])->save();

            $prompt->refresh()->assertCarriesNoCustomerData();

            return $prompt;
        });
    }

    /**
     * Publish a draft, archiving whichever version was current.
     *
     * The superseded version is ARCHIVED, never edited and never removed:
     * generations bind to it as the record of what was asked.
     */
    public function publish(AiPromptVersion $prompt, User $actor): AiPromptVersion
    {
        return DB::transaction(function () use ($prompt, $actor): AiPromptVersion {
            if (! $prompt->isDraft()) {
                throw new RuntimeException(
                    "Prompt version {$prompt->getKey()} is already {$prompt->status->value}."
                );
            }

            $prompt->assertCarriesNoCustomerData();

            $this->archiveCurrentlyPublished($prompt);

            $prompt->forceFill([
                'status' => AiPromptStatus::Published,
                'published_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::AiPromptPublished,
                $prompt,
                ['status' => AiPromptStatus::Draft->value],
                [
                    'status' => AiPromptStatus::Published->value,
                    'key' => $prompt->key,
                    'version_number' => $prompt->version_number,
                ],
                $actor,
            );

            return $prompt->fresh();
        });
    }

    /**
     * The version a NEW generation must use.
     *
     * Existing generations never call this: they read through their own
     * stored ai_prompt_version_id, which is what keeps an old output
     * explicable after the prompt has moved on.
     */
    public function publishedFor(string $key): AiPromptVersion
    {
        $prompt = AiPromptVersion::query()
            ->forKey($key)
            ->published()
            ->orderByDesc('version_number')
            ->first();

        if ($prompt === null) {
            throw new RuntimeException(
                "No published prompt version exists for [{$key}]. Generation is refused rather than "
                .'run against an unreviewed draft.'
            );
        }

        return $prompt;
    }

    private function archiveCurrentlyPublished(AiPromptVersion $incoming): void
    {
        AiPromptVersion::query()
            ->where('key', $incoming->key)
            ->where('status', AiPromptStatus::Published->value)
            ->whereKeyNot($incoming->getKey())
            ->get()
            ->each(function (AiPromptVersion $superseded): void {
                // Archiving is the one change a published prompt permits.
                $superseded->forceFill(['status' => AiPromptStatus::Archived])->save();
            });
    }
}
