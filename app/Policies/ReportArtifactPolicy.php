<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportArtifact;
use App\Models\User;

/**
 * Authorization for reports and the artifacts they produce.
 *
 * TWO PERMISSIONS, AND THE GAP BETWEEN THEM IS THE POINT. reports.view is
 * reading; reports.export writes a file that leaves the system, which is a
 * data-exfiltration boundary. Staff holds the first and not the second, and
 * that line was drawn in Phase 0.
 *
 * Downloading a stored artifact is reading, so it rides on reports.view -
 * but the artifact must already exist, which means somebody who held
 * reports.export made it.
 *
 * Which business's report an actor may reach is NOT decided here. A policy
 * that trusted a request-supplied subject id would authorise the wrong report
 * perfectly correctly, so ReportScope resolves the subject and its owner
 * server-side instead.
 */
class ReportArtifactPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('reports.view');
    }

    public function view(User $actor, ReportArtifact $artifact): bool
    {
        return $actor->can('reports.view');
    }

    /**
     * Reading the delivered bytes back.
     */
    public function download(User $actor, ReportArtifact $artifact): bool
    {
        return $actor->can('reports.view');
    }

    /**
     * Producing a stored artifact.
     */
    public function create(User $actor): bool
    {
        return $actor->can('reports.export');
    }

    public function export(User $actor): bool
    {
        return $actor->can('reports.export');
    }

    /**
     * Immutable. The model enforces the absolute form, because 'update' is not
     * a guarded ability and Gate::before would otherwise grant it to a Super
     * Admin without consulting this class.
     */
    public function update(User $actor, ReportArtifact $artifact): bool
    {
        return false;
    }

    /**
     * An artifact is the record of what was handed over and is never tidied
     * away.
     */
    public function delete(User $actor, ReportArtifact $artifact): bool
    {
        return false;
    }
}
