<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Enums\AuditAction;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishing a form version, and superseding it with the next one.
 *
 * "At most one published version per template" would be a partial unique
 * index, which is not portable between SQLite and MySQL, so it is enforced
 * here - transactionally, so two concurrent publishes cannot both succeed.
 *
 * Publishing is the moment a version becomes answerable, which is exactly the
 * moment worth auditing.
 */
class FormPublishingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FormBuilderService $builder,
    ) {}

    /**
     * Publish a draft, superseding whichever version was published before.
     *
     * The superseded version is ARCHIVED, never edited and never removed:
     * submissions bind to it as the record of what was asked.
     */
    public function publish(FormVersion $version, User $actor, ?string $schemeVersion = null): FormVersion
    {
        return DB::transaction(function () use ($version, $actor, $schemeVersion): FormVersion {
            if (! $version->isDraft()) {
                throw new RuntimeException(
                    "Form version {$version->getKey()} is already {$version->status}."
                );
            }

            $this->assertHasContent($version);

            $this->archiveCurrentlyPublished($version);

            $version->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $actor->getKey(),
                'scoring_scheme_version' => $schemeVersion,
            ])->save();

            $this->audit->log(
                AuditAction::FormVersionPublished,
                $version,
                ['status' => 'draft'],
                [
                    'status' => 'published',
                    'version_number' => $version->version_number,
                    'form_template_id' => $version->form_template_id,
                ],
                $actor,
            );

            return $version->fresh();
        });
    }

    /**
     * Begin the next version of a template.
     *
     * This is the ONLY way a published form changes. The published version is
     * left exactly as it is, so every submission already bound to it keeps
     * its meaning.
     */
    public function startNextVersion(FormTemplate $template): FormVersion
    {
        return $this->builder->createDraftVersion($template);
    }

    /**
     * The version a NEW submission should bind to.
     *
     * Existing submissions never call this: they read through their own
     * stored form_version_id.
     */
    public function versionForNewSubmission(FormTemplate $template): FormVersion
    {
        $published = $template->publishedVersion();

        if ($published === null) {
            throw new RuntimeException(
                "Form template {$template->getKey()} has no published version to submit against."
            );
        }

        return $published;
    }

    private function assertHasContent(FormVersion $version): void
    {
        if ($version->questions()->count() === 0) {
            throw new RuntimeException(
                "Form version {$version->getKey()} has no questions and cannot be published."
            );
        }
    }

    private function archiveCurrentlyPublished(FormVersion $incoming): void
    {
        FormVersion::query()
            ->where('form_template_id', $incoming->form_template_id)
            ->where('status', 'published')
            ->whereKeyNot($incoming->getKey())
            ->get()
            ->each(function (FormVersion $superseded): void {
                // Archiving is the one change a published version permits.
                $superseded->forceFill([
                    'status' => 'archived',
                    'archived_at' => now(),
                ])->save();
            });
    }
}
