<?php

declare(strict_types=1);

namespace App\Livewire\Sessions;

use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Sessions\AttendanceService;
use App\Domain\Sessions\SessionSchedulingService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AssignmentInstance;
use App\Models\AssignmentTemplate;
use App\Models\Enrollment;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One session: what was planned, who attended, and what was set.
 *
 * ATTENDANCE IS RAW STATUS ONLY. No percentage is shown anywhere on this page.
 * AttendanceWeighting is unbound - late and excused carry no agreed credit -
 * so a percentage would be a formula this application has not been given.
 * Counts of each status are facts and are shown instead.
 *
 * Marking goes through AttendanceService, which refuses a mark against a
 * session that was never held (invariant I16). The UI reflects that by only
 * offering the control once the session has begun, but the refusal is the
 * service's, not the template's.
 */
class Show extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public int $sessionId;

    public string $actualDate = '';

    public bool $releasingAssignment = false;

    public ?int $assignmentTemplateId = null;

    public string $assignmentDueAt = '';

    public function mount(SessionInstance $session): void
    {
        $this->authorize('view', $session);

        $this->sessionId = (int) $session->getKey();
        $this->actualDate = now()->toDateString();
    }

    public function begin(SessionSchedulingService $scheduler): void
    {
        $session = $this->session();

        $this->authorize('update', $session);

        $this->validate(['actualDate' => ['required', 'date']]);

        $this->runGuarded(
            fn () => $scheduler->begin($session, $this->actualDate, auth()->user()),
            'Session started. Attendance can now be marked.',
        );
    }

    public function completeSession(SessionSchedulingService $scheduler): void
    {
        $session = $this->session();

        $this->authorize('complete', $session);

        $this->runGuarded(
            fn () => $scheduler->complete($session, auth()->user()),
            'Session completed.',
        );
    }

    /**
     * Mark one participant. The status vocabulary is the domain's -
     * SessionAttendance::STATUSES - and nothing here adds to it.
     */
    public function mark(int $enrollmentId, string $status, AttendanceService $attendance): void
    {
        $session = $this->session();
        $enrollment = $this->enrollmentInBatch($session, $enrollmentId);

        $this->authorize('create', SessionAttendance::class);

        if (! in_array($status, SessionAttendance::STATUSES, true)) {
            abort(422);
        }

        $existing = SessionAttendance::query()
            ->where('session_instance_id', $session->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->first();

        $this->runGuarded(function () use ($attendance, $session, $enrollment, $status, $existing): void {
            // An existing mark is AMENDED, never overwritten: the amendment
            // carries who changed it and is the record the audit trail keeps.
            $existing === null
                ? $attendance->mark($session, $enrollment, $status, auth()->user())
                : $attendance->amend($existing, $status, auth()->user());
        }, 'Attendance recorded.');
    }

    public function startAssignmentRelease(): void
    {
        $this->authorize('create', AssignmentInstance::class);

        $this->reset(['assignmentTemplateId', 'assignmentDueAt']);
        $this->releasingAssignment = true;
    }

    public function stageAssignment(AssignmentReleaseService $assignments): void
    {
        $session = $this->session();

        $this->authorize('create', AssignmentInstance::class);

        $this->validate([
            'assignmentTemplateId' => ['required', 'integer', 'exists:assignment_templates,id'],
            'assignmentDueAt' => ['required', 'date'],
        ]);

        $template = AssignmentTemplate::query()->findOrFail($this->assignmentTemplateId);

        $saved = $this->runGuarded(
            fn () => $assignments->stageFromTemplate($session, $template, $this->assignmentDueAt, auth()->user()),
            'Assignment staged as a draft. Release it when the session is ready.',
        );

        if ($saved) {
            $this->releasingAssignment = false;
        }
    }

    public function releaseAssignment(int $assignmentId, AssignmentReleaseService $assignments): void
    {
        $instance = $this->assignmentInSession($assignmentId);

        $this->authorize('release', $instance);

        $this->runGuarded(
            fn () => $assignments->release($instance, auth()->user()),
            'Assignment released.',
        );
    }

    public function render(): View
    {
        $session = $this->session();

        $roster = Enrollment::query()
            ->where('batch_id', $session->batch_id)
            ->where('status', 'enrolled')
            ->with('customer:id,name,code')
            ->get();

        $marks = SessionAttendance::query()
            ->where('session_instance_id', $session->getKey())
            ->get()
            ->keyBy('enrollment_id');

        return view('livewire.sessions.show', [
            'session' => $session,
            'roster' => $roster,
            'marks' => $marks,
            'statusCounts' => $marks->groupBy('status')->map->count(),
            'statuses' => SessionAttendance::STATUSES,
            'assignments' => $session->assignmentInstances()->withCount('submissions')->orderBy('due_at')->get(),
            'assignmentTemplates' => AssignmentTemplate::query()
                ->where('session_template_id', $session->session_template_id)
                ->whereNull('archived_at')
                ->orderBy('position')
                ->get(),
            'sessionForms' => $session->sessionTemplate?->templateForms()->with('formTemplate')->get() ?? collect(),
        ])->layout('components.layouts.app', [
            'title' => 'Session '.($session->sessionTemplate?->sequence ?? '').' — '.($session->sessionTemplate?->title ?? ''),
        ]);
    }

    private function session(): SessionInstance
    {
        return SessionInstance::query()
            ->with(['batch.program', 'sessionTemplate', 'conductedBy'])
            ->findOrFail($this->sessionId);
    }

    /**
     * An enrolment id arriving from the browser must belong to THIS session's
     * batch. AttendanceService asserts the same thing; checking here means a
     * mismatched id is a 404 rather than a 500.
     */
    private function enrollmentInBatch(SessionInstance $session, int $enrollmentId): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        if ((int) $enrollment->batch_id !== (int) $session->batch_id) {
            abort(404);
        }

        return $enrollment;
    }

    private function assignmentInSession(int $assignmentId): AssignmentInstance
    {
        $instance = AssignmentInstance::query()->findOrFail($assignmentId);

        if ((int) $instance->session_instance_id !== $this->sessionId) {
            abort(404);
        }

        return $instance;
    }
}
