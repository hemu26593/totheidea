<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\AuditAction;
use App\Models\ReportArtifact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generating a report, and preserving what was delivered.
 *
 * THE ORDER MATTERS AND IS DELIBERATE:
 *
 *   1. authorize        - the actor may generate, and may export if storing
 *   2. build            - deterministic PHP, every number
 *   3. render           - the same payload, to PDF or CSV bytes
 *   4. store + record   - the file, its checksum, and the filters that made it
 *
 * ARTIFACTS ARE NEVER READ BACK AS A DATA SOURCE. Nothing in this class, or
 * anywhere else, extracts a figure from a stored file. A report is a query
 * over operational data; an artifact answers the different question of what
 * was handed over, and only the bytes can answer that.
 *
 * REGENERATION CREATES A NEW ARTIFACT. The earlier one is untouched - it has
 * to be, because the numbers underneath it have moved on and the question
 * "what did we tell them before Session 1?" has only one honest answer.
 */
class ReportArtifactService
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRenderer $renderer,
        private readonly ReportScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Compute a report without storing anything.
     *
     * Requires reports.view. Used for on-screen preview, and by every test
     * that checks the figures rather than the file.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function generate(
        string $reportKey,
        Model $subject,
        User $actor,
        array $parameters = [],
    ): ReportData {
        $this->assertCanView($actor);

        $builder = $this->registry->get($reportKey);
        $this->scope->assertSubjectType($subject, $builder->subjectType());

        $data = $builder->build($subject, $parameters);

        $this->audit->log(
            AuditAction::ReportGenerated,
            $subject,
            null,
            [
                'report_key' => $reportKey,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'parameters' => $parameters,
            ],
            $actor,
        );

        return $data;
    }

    /**
     * Compute, render and store - the delivered artifact.
     *
     * Requires reports.export as well as reports.view: writing a file that
     * leaves the system is a data-exfiltration boundary, which is why Staff
     * holds the first and not the second.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function export(
        string $reportKey,
        Model $subject,
        User $actor,
        string $format = ReportArtifact::FORMAT_PDF,
        array $parameters = [],
    ): ReportArtifact {
        $this->assertCanExport($actor);
        $this->assertFormatIsKnown($format);

        $builder = $this->registry->get($reportKey);
        $this->scope->assertSubjectType($subject, $builder->subjectType());

        $data = $builder->build($subject, $parameters);
        $bytes = $this->renderer->render($data, $format);
        $checksum = hash('sha256', $bytes);

        $disk = (string) config('reports.disk', 'local');
        $path = $this->pathFor($data, $format, $checksum);

        return DB::transaction(function () use ($data, $subject, $actor, $format, $parameters, $bytes, $checksum, $disk, $path): ReportArtifact {
            Storage::disk($disk)->put($path, $bytes);

            $artifact = new ReportArtifact;
            $artifact->forceFill([
                'report_key' => $data->reportKey,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'format' => $format,
                'disk' => $disk,
                'path' => $path,
                // The filters, so a stored file can still be explained later.
                'parameters' => $parameters,
                'checksum_sha256' => $checksum,
                'generated_by' => $actor->getKey(),
                'generated_at' => $data->generatedAt,
            ])->save();

            $fresh = $artifact->fresh();

            $this->audit->log(
                AuditAction::ReportExported,
                $fresh,
                null,
                [
                    'report_key' => $data->reportKey,
                    'format' => $format,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'checksum_sha256' => $checksum,
                ],
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * The delivered bytes, verified against the checksum recorded when they
     * were issued.
     *
     * This is a RETRIEVAL, not a data source: the caller gets a file to hand
     * over, never a figure to compute with.
     */
    public function contents(ReportArtifact $artifact, User $actor): string
    {
        $this->assertCanView($actor);

        $disk = Storage::disk($artifact->disk);

        if (! $disk->exists($artifact->path)) {
            throw new RuntimeException(sprintf(
                'Report artifact %d is recorded but its file is missing from disk [%s].',
                $artifact->getKey(),
                $artifact->disk,
            ));
        }

        $bytes = (string) $disk->get($artifact->path);

        if (hash('sha256', $bytes) !== $artifact->checksum_sha256) {
            // The record says what was issued. If the file no longer matches,
            // it is not the file that was issued, and handing it over would be
            // worse than refusing.
            throw new RuntimeException(sprintf(
                'Report artifact %d does not match its recorded checksum; the stored file is not '
                .'the one that was delivered.',
                $artifact->getKey(),
            ));
        }

        return $bytes;
    }

    /**
     * Every report ever issued about this subject, newest first.
     *
     * @return Collection<int, ReportArtifact>
     */
    public function historyFor(Model $subject): Collection
    {
        return ReportArtifact::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A stable, collision-resistant path that carries its own checksum.
     */
    private function pathFor(ReportData $data, string $format, string $checksum): string
    {
        return sprintf(
            'reports/%s/%s/%d/%s-%s.%s',
            $data->reportKey,
            str_replace('\\', '-', $data->subject->getMorphClass()),
            $data->subject->getKey(),
            $data->generatedAt->format('Ymd-His'),
            substr($checksum, 0, 12),
            $format,
        );
    }

    private function assertCanView(User $actor): void
    {
        if (! $actor->can('reports.view')) {
            throw new RuntimeException(sprintf(
                'User %d cannot read reports: reports.view is required.',
                $actor->getKey(),
            ));
        }
    }

    private function assertCanExport(User $actor): void
    {
        $this->assertCanView($actor);

        if (! $actor->can('reports.export')) {
            throw new RuntimeException(sprintf(
                'User %d cannot export a report: reports.export is required. Export writes a file '
                .'that leaves the system, which is a separate decision from reading a report.',
                $actor->getKey(),
            ));
        }
    }

    private function assertFormatIsKnown(string $format): void
    {
        if (! in_array($format, ReportArtifact::FORMATS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown report format [%s]. Expected one of: %s.',
                $format,
                implode(', ', ReportArtifact::FORMATS),
            ));
        }
    }
}
