<?php

declare(strict_types=1);

namespace App\Domain\Sessions;

use App\Enums\AuditAction;
use App\Models\AssignmentTemplate;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\SessionTemplate;
use App\Models\SessionTemplateForm;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authoring the curriculum: session templates, the forms attached to them, and
 * the assignment definitions that hang off them.
 *
 * Step 3B asks for an audit entry on curriculum change across tables 20, 21
 * and 24, which is why all three live behind one service rather than three:
 * they are one editorial act, and splitting them would produce three
 * unrelated-looking audit trails for a single change.
 *
 * Everything here is ARCHIVE-ONLY. session_instances and
 * assignment_instances RESTRICT, so a delivered curriculum row survives its
 * own retirement.
 */
class CurriculumService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Define one session of a program's curriculum.
     */
    public function defineSession(
        Program $program,
        int $sequence,
        string $title,
        ?string $theme = null,
        ?string $objectives = null,
        ?User $actor = null,
    ): SessionTemplate {
        if ($sequence < 1) {
            throw new InvalidArgumentException('A session sequence starts at 1.');
        }

        return DB::transaction(function () use ($program, $sequence, $title, $theme, $objectives, $actor): SessionTemplate {
            $template = new SessionTemplate;
            $template->forceFill([
                'program_id' => $program->getKey(),
                'sequence' => $sequence,
                'title' => $title,
                'theme' => $theme,
                'objectives' => $objectives,
            ])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $template,
                null,
                ['program_id' => $program->getKey(), 'sequence' => $sequence, 'title' => $title],
                $actor,
            );

            return $template->fresh();
        });
    }

    /**
     * Attach a form template to a session.
     *
     * is_required drives trigger 2 ("intake forms not filled"), so an optional
     * worksheet must be attached as optional rather than reminded about daily.
     */
    public function attachForm(
        SessionTemplate $template,
        FormTemplate $form,
        bool $isRequired = true,
        int $position = 0,
        ?User $actor = null,
    ): SessionTemplateForm {
        $this->assertNotArchived($template);

        return DB::transaction(function () use ($template, $form, $isRequired, $position, $actor): SessionTemplateForm {
            $attachment = new SessionTemplateForm;
            $attachment->forceFill([
                'session_template_id' => $template->getKey(),
                'form_template_id' => $form->getKey(),
                'is_required' => $isRequired,
                'position' => $position,
            ])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $template,
                null,
                ['attached_form_template_id' => $form->getKey(), 'is_required' => $isRequired],
                $actor,
            );

            return $attachment->fresh();
        });
    }

    /**
     * Define a reusable assignment on a session.
     *
     * default_due_days is an offset, never a date: only a released assignment
     * has a due date, and that belongs to the instance.
     */
    public function defineAssignment(
        SessionTemplate $template,
        string $title,
        ?string $instructions = null,
        ?int $defaultDueDays = null,
        bool $requiresAttachment = false,
        int $position = 0,
        ?User $actor = null,
    ): AssignmentTemplate {
        $this->assertNotArchived($template);

        return DB::transaction(function () use (
            $template, $title, $instructions, $defaultDueDays, $requiresAttachment, $position, $actor
        ): AssignmentTemplate {
            $assignment = new AssignmentTemplate;
            $assignment->forceFill([
                'session_template_id' => $template->getKey(),
                'title' => $title,
                'instructions' => $instructions,
                'default_due_days' => $defaultDueDays,
                'requires_attachment' => $requiresAttachment,
                'position' => $position,
            ])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $assignment,
                null,
                ['session_template_id' => $template->getKey(), 'title' => $title],
                $actor,
            );

            return $assignment->fresh();
        });
    }

    public function archiveSession(SessionTemplate $template, User $actor): SessionTemplate
    {
        return DB::transaction(function () use ($template, $actor): SessionTemplate {
            $template->forceFill(['archived_at' => now()])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $template,
                ['archived_at' => null],
                ['archived_at' => $template->archived_at?->toIso8601String()],
                $actor,
            );

            return $template->fresh();
        });
    }

    public function archiveAssignment(AssignmentTemplate $assignment, User $actor): AssignmentTemplate
    {
        return DB::transaction(function () use ($assignment, $actor): AssignmentTemplate {
            $assignment->forceFill(['archived_at' => now()])->save();

            $this->audit->log(
                AuditAction::CurriculumChanged,
                $assignment,
                ['archived_at' => null],
                ['archived_at' => $assignment->archived_at?->toIso8601String()],
                $actor,
            );

            return $assignment->fresh();
        });
    }

    /**
     * A program's curriculum is complete when it has as many sessions as the
     * program declares. Checked when publishing rather than enforced by a
     * constraint, because a curriculum is necessarily incomplete while it is
     * being authored.
     */
    public function curriculumIsComplete(Program $program): bool
    {
        $defined = SessionTemplate::query()
            ->where('program_id', $program->getKey())
            ->whereNull('archived_at')
            ->count();

        return $defined === (int) $program->session_count;
    }

    private function assertNotArchived(SessionTemplate $template): void
    {
        if ($template->isArchived()) {
            throw new RuntimeException(
                "Session template {$template->getKey()} is archived and cannot be extended."
            );
        }
    }
}
