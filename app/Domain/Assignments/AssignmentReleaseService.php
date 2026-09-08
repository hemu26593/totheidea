<?php

declare(strict_types=1);

namespace App\Domain\Assignments;

use App\Enums\AuditAction;
use App\Models\AssignmentInstance;
use App\Models\AssignmentTemplate;
use App\Models\SessionInstance;
use App\Models\User;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Releasing an assignment to a batch.
 *
 * THE WORDING IS SNAPSHOT AT RELEASE. title and instructions are copied onto
 * the instance and never re-read from the template afterwards, so a running
 * batch keeps the assignment text it was actually given. Rendering from the
 * template instead would silently rewrite history the moment the curriculum
 * was edited - and it would do so invisibly, which is the worst kind of
 * wrong.
 *
 * due_at lives here rather than on the template because only a released
 * assignment has a real date. It drives triggers 3 and 4.
 */
class AssignmentReleaseService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Stage an assignment from a template, taking the wording snapshot.
     */
    public function stageFromTemplate(
        SessionInstance $sessionInstance,
        AssignmentTemplate $template,
        DateTimeInterface|string $dueAt,
        ?User $actor = null,
    ): AssignmentInstance {
        $this->assertTemplateBelongsToSession($sessionInstance, $template);

        return DB::transaction(function () use ($sessionInstance, $template, $dueAt, $actor): AssignmentInstance {
            $instance = new AssignmentInstance;
            $instance->forceFill([
                'session_instance_id' => $sessionInstance->getKey(),
                'assignment_template_id' => $template->getKey(),
                // THE SNAPSHOT.
                'title' => $template->title,
                'instructions' => $template->instructions,
                'requires_attachment' => (bool) $template->requires_attachment,
                'due_at' => $dueAt,
                'status' => AssignmentInstance::STATUS_DRAFT,
            ])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $instance,
                null,
                [
                    'session_instance_id' => $sessionInstance->getKey(),
                    'assignment_template_id' => $template->getKey(),
                    'staged' => true,
                ],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * Stage an assignment with no template. Ad-hoc assignments are legitimate,
     * which is why assignment_template_id is nullable.
     */
    public function stageAdHoc(
        SessionInstance $sessionInstance,
        string $title,
        DateTimeInterface|string $dueAt,
        ?string $instructions = null,
        bool $requiresAttachment = false,
        ?User $actor = null,
    ): AssignmentInstance {
        return DB::transaction(function () use ($sessionInstance, $title, $dueAt, $instructions, $requiresAttachment, $actor): AssignmentInstance {
            $instance = new AssignmentInstance;
            $instance->forceFill([
                'session_instance_id' => $sessionInstance->getKey(),
                'assignment_template_id' => null,
                'title' => $title,
                'instructions' => $instructions,
                'requires_attachment' => $requiresAttachment,
                'due_at' => $dueAt,
                'status' => AssignmentInstance::STATUS_DRAFT,
            ])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $instance,
                null,
                ['session_instance_id' => $sessionInstance->getKey(), 'ad_hoc' => true, 'title' => $title],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * Release a staged assignment. From here it is visible to participants and
     * the reminder sweeps read its due date.
     */
    public function release(AssignmentInstance $instance, User $actor): AssignmentInstance
    {
        if ($instance->status !== AssignmentInstance::STATUS_DRAFT) {
            throw new RuntimeException(sprintf(
                'Assignment instance %d is [%s]; only a draft can be released.',
                $instance->getKey(),
                $instance->status,
            ));
        }

        return DB::transaction(function () use ($instance, $actor): AssignmentInstance {
            $instance->forceFill([
                'status' => AssignmentInstance::STATUS_RELEASED,
                'released_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::AssignmentReleased,
                $instance,
                ['status' => AssignmentInstance::STATUS_DRAFT],
                [
                    'status' => AssignmentInstance::STATUS_RELEASED,
                    'title' => $instance->title,
                    'due_at' => $instance->due_at?->toIso8601String(),
                ],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * Close an assignment. Never deleted: submissions refer to it.
     */
    public function close(AssignmentInstance $instance, User $actor, ?string $reason = null): AssignmentInstance
    {
        return DB::transaction(function () use ($instance, $actor, $reason): AssignmentInstance {
            $before = $instance->status;

            $instance->forceFill(['status' => AssignmentInstance::STATUS_CLOSED])->save();

            $this->audit->log(
                AuditAction::AssignmentClosed,
                $instance,
                ['status' => $before],
                ['status' => AssignmentInstance::STATUS_CLOSED, 'reason' => $reason],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * The due date implied by a template's offset from the session's real date.
     *
     * Returns null when either input is missing rather than substituting a
     * date: a due date nobody set is not a due date.
     */
    public function suggestedDueAt(SessionInstance $sessionInstance, AssignmentTemplate $template): ?string
    {
        $anchor = $sessionInstance->actual_date ?? $sessionInstance->planned_date;

        if ($anchor === null || $template->default_due_days === null) {
            return null;
        }

        return $anchor->copy()->addDays((int) $template->default_due_days)->endOfDay()->toDateTimeString();
    }

    private function assertTemplateBelongsToSession(
        SessionInstance $sessionInstance,
        AssignmentTemplate $template,
    ): void {
        if ((int) $template->session_template_id !== (int) $sessionInstance->session_template_id) {
            throw new InvalidArgumentException(sprintf(
                'Assignment template %d belongs to session template %d, but this instance runs '
                .'session template %d.',
                $template->getKey(),
                $template->session_template_id,
                $sessionInstance->session_template_id,
            ));
        }
    }
}
