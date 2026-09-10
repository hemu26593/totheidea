<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Models\ActionItem;
use App\Models\AssignmentInstance;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\SubmissionScore;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The auto-feed: "session assignments and weak skill areas land here
 * automatically."
 *
 * IDEMPOTENT VIA THE (source_type, source_id) LOOKUP, NOT A UNIQUE KEY. A
 * unique key would also forbid a consultant legitimately adding a second
 * manual task about the same assignment, which is a different thing from the
 * feeder running twice.
 *
 * Provenance, not ownership: the source explains WHY a task exists. Ownership
 * is the enrolment.
 */
class ActionItemFeeder
{
    public function __construct(private readonly ActionItemService $items) {}

    /**
     * Feed one released assignment into one participant's list.
     *
     * Returns the existing item unchanged if the feed has already run for this
     * pair, so calling it on every release is safe.
     */
    public function fromAssignment(AssignmentInstance $instance, Enrollment $enrollment): ActionItem
    {
        $existing = $this->existingFor($instance, $enrollment);

        if ($existing !== null) {
            return $existing;
        }

        return $this->items->create(
            $enrollment,
            $instance->title,
            $instance->instructions,
            // The assignment's own due date. Not recomputed, not offset.
            $instance->due_at?->toDateString(),
            ActionItem::PRIORITY_NORMAL,
            source: $instance,
        );
    }

    /**
     * Feed a scored skill area the CALLER has determined is weak.
     *
     * The caller supplies the score because deciding WHICH areas are weak
     * requires the heat-map band, and that threshold is deferred item L2 - see
     * feedWeakSkillAreas() below. This method does not decide; it records.
     */
    public function fromWeakSkillArea(SubmissionScore $score, Enrollment $enrollment, string $title): ActionItem
    {
        $existing = $this->existingFor($score, $enrollment);

        if ($existing !== null) {
            return $existing;
        }

        return $this->items->create(
            $enrollment,
            $title,
            'Raised from a skill-area score.',
            null,
            ActionItem::PRIORITY_NORMAL,
            source: $score,
        );
    }

    /**
     * Feed every weak area of a scored submission.
     *
     * REFUSES, DELIBERATELY. "Weak" means below a heat-map band, and the band
     * thresholds are not defined by the requirements - deferred item L2, the
     * same decision that makes ScoringService::bandFor() refuse. Picking a
     * threshold here would raise action items against a standard nobody set,
     * and the participant would be told to work on areas chosen by a guess.
     */
    public function feedWeakSkillAreas(FormSubmission $submission, Enrollment $enrollment): never
    {
        throw new RuntimeException(
            'Which skill areas count as weak depends on the heat-map band thresholds, which are '
            .'not defined by the requirements (deferred item L2). Scores are computed and stored; '
            .'the banding rule must come from the client. Use fromWeakSkillArea() with a score the '
            .'caller has already determined is weak.'
        );
    }

    /**
     * Has the feed already produced an item for this source and participant?
     *
     * The lookup that makes the feeder idempotent.
     */
    public function existingFor(Model $source, Enrollment $enrollment): ?ActionItem
    {
        return ActionItem::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->orderBy('id')
            ->first();
    }
}
