<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\AiGeneration;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\ReportArtifact;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The operational console.
 *
 * EVERY FIGURE HERE IS A COUNT OF ROWS THAT EXIST. Nothing is modelled,
 * projected or weighted. Two metrics a console like this would normally carry
 * are deliberately absent, because the rules behind them are unresolved client
 * decisions and inventing either would put a number in front of a consultant
 * that nobody has agreed:
 *
 *   - Attendance percentage. AttendanceWeighting is unbound, so late and
 *     excused have no credit. "Attendance issues" below counts ABSENCES,
 *     which is a fact, not a formula.
 *   - Completion or progress scores. No weighting exists for those either.
 *
 * ROLE AWARENESS IS BY PERMISSION, never by role name. A panel an actor
 * cannot open is not rendered, and each destination re-authorizes anyway.
 */
class Index extends Component
{
    /** How far back "recent" reaches, and how far forward "upcoming" looks. */
    private const WINDOW_DAYS = 14;

    private const ROW_LIMIT = 8;

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.dashboard.index', [
            'metrics' => $this->metrics($user),
            'attentionCustomers' => $user->can('customers.view') ? $this->customersNeedingAttention() : null,
            'upcomingSessions' => $user->can('sessions.view') ? $this->upcomingSessions() : null,
            'overdueAssignments' => $user->can('assignments.view') ? $this->overdueAssignments() : null,
            'recentSubmissions' => $user->can('forms.view') ? $this->recentSubmissions() : null,
            'recentGenerations' => $user->can('ai.analysis.view') ? $this->recentGenerations() : null,
            'recentReports' => $user->can('reports.view') ? $this->recentReports() : null,
        ])->layout('components.layouts.app', ['title' => 'Dashboard']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function metrics(User $user): array
    {
        $today = CarbonImmutable::today();
        $horizon = $today->addDays(self::WINDOW_DAYS);

        $metrics = [];

        if ($user->can('customers.view')) {
            $metrics[] = [
                'label' => 'Customers',
                'value' => Customer::query()->whereNull('archived_at')->count(),
                'hint' => 'Not archived',
                'href' => route('customers.index'),
            ];

            $metrics[] = [
                'label' => 'Active enrolments',
                'value' => Enrollment::query()->where('status', 'enrolled')->count(),
                'hint' => 'Currently enrolled',
            ];
        }

        if ($user->can('batches.view')) {
            $metrics[] = [
                'label' => 'Active batches',
                'value' => Batch::query()
                    ->whereNull('archived_at')
                    ->whereIn('status', ['planned', 'active', 'in_progress'])
                    ->count(),
                'hint' => 'Planned or running',
                'href' => route('batches.index'),
            ];
        }

        if ($user->can('sessions.view')) {
            $metrics[] = [
                'label' => 'Sessions due',
                'value' => SessionInstance::query()
                    ->where('status', SessionInstance::STATUS_SCHEDULED)
                    ->whereBetween('planned_date', [$today->toDateString(), $horizon->toDateString()])
                    ->count(),
                'hint' => 'Next '.self::WINDOW_DAYS.' days',
                'href' => route('sessions.index'),
            ];
        }

        if ($user->can('assignments.view')) {
            $metrics[] = [
                'label' => 'Pending review',
                'value' => AssignmentSubmission::query()
                    ->where('status', AssignmentSubmission::STATUS_SUBMITTED)
                    ->count(),
                'hint' => 'Submitted, not reviewed',
                'tone' => 'warning',
                'href' => route('assignments.index'),
            ];

            $metrics[] = [
                'label' => 'Overdue assignments',
                'value' => $this->overdueAssignmentQuery()->count(),
                'hint' => 'Released, past due, unsubmitted',
                'tone' => 'danger',
            ];
        }

        if ($user->can('attendance.view')) {
            $metrics[] = [
                'label' => 'Absences recorded',
                'value' => SessionAttendance::query()
                    ->where('status', SessionAttendance::STATUS_ABSENT)
                    ->count(),
                // Deliberately a count, not a percentage - see the class docblock.
                'hint' => 'Count, not a percentage',
                'tone' => 'warning',
                'href' => route('attendance.index'),
            ];
        }

        if ($user->can('forms.view')) {
            $metrics[] = [
                'label' => 'Intake incomplete',
                'value' => FormSubmission::query()->where('status', 'draft')->count(),
                'hint' => 'Draft submissions',
            ];
        }

        if ($user->can('mmd.view')) {
            $metrics[] = [
                'label' => 'Dashboards missing today',
                'value' => $this->customersWithoutTodaysMmd(),
                'hint' => 'Enrolled customers with no MMD entry today',
                'tone' => 'warning',
            ];
        }

        return $metrics;
    }

    /**
     * Enrolled customers that recorded nothing on the MMD today.
     *
     * GRAIN-AGNOSTIC. It asks only whether ANY row exists for the customer on
     * the date, which is true under either reading of the unresolved B1
     * decision. It never counts rows, and never assumes one per business.
     */
    private function customersWithoutTodaysMmd(): int
    {
        $today = CarbonImmutable::today()->toDateString();

        return Customer::query()
            ->whereNull('archived_at')
            ->whereHas('enrollments', fn ($q) => $q->where('status', 'enrolled'))
            ->whereNotExists(function ($query) use ($today): void {
                $query->selectRaw('1')
                    ->from('mmd_entries')
                    ->whereColumn('mmd_entries.customer_id', 'customers.id')
                    ->whereDate('mmd_entries.entry_date', $today);
            })
            ->count();
    }

    /**
     * @return Builder<AssignmentInstance>
     */
    private function overdueAssignmentQuery()
    {
        return AssignmentInstance::query()
            ->where('status', AssignmentInstance::STATUS_RELEASED)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /**
     * Customers with something outstanding: a recorded absence, or an intake
     * still in draft.
     *
     * Written as EXISTS subqueries rather than HAVING on an aggregate alias:
     * HAVING without GROUP BY behaves differently on SQLite and MySQL, and a
     * dashboard that silently differs between development and production is
     * worse than one that is a little more verbose.
     *
     * @return Collection<int, Customer>
     */
    private function customersNeedingAttention(): Collection
    {
        $absences = fn ($query) => $query->selectRaw('1')
            ->from('session_attendances')
            ->join('enrollments', 'enrollments.id', '=', 'session_attendances.enrollment_id')
            ->whereColumn('enrollments.customer_id', 'customers.id')
            ->where('session_attendances.status', SessionAttendance::STATUS_ABSENT);

        $draftIntake = fn ($query) => $query->selectRaw('1')
            ->from('form_submissions')
            ->whereColumn('form_submissions.customer_id', 'customers.id')
            ->where('form_submissions.status', 'draft');

        return Customer::query()
            ->whereNull('archived_at')
            ->where(function ($query) use ($absences, $draftIntake): void {
                $query->whereExists($absences)->orWhereExists($draftIntake);
            })
            ->withCount([
                'enrollments as open_enrollments_count' => fn ($q) => $q->where('status', 'enrolled'),
            ])
            ->orderBy('name')
            ->limit(self::ROW_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, SessionInstance>
     */
    private function upcomingSessions(): Collection
    {
        return SessionInstance::query()
            ->with(['batch:id,name,code', 'sessionTemplate:id,title,sequence'])
            ->where('status', SessionInstance::STATUS_SCHEDULED)
            ->whereDate('planned_date', '>=', CarbonImmutable::today()->toDateString())
            ->orderBy('planned_date')
            ->limit(self::ROW_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, AssignmentInstance>
     */
    private function overdueAssignments(): Collection
    {
        return $this->overdueAssignmentQuery()
            ->with(['sessionInstance.batch:id,name,code'])
            ->orderBy('due_at')
            ->limit(self::ROW_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, FormSubmission>
     */
    private function recentSubmissions(): Collection
    {
        return FormSubmission::query()
            ->with(['enrollment.customer:id,name', 'formVersion.formTemplate:id,name'])
            ->whereNotNull('submitted_at')
            ->latest('submitted_at')
            ->limit(self::ROW_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, AiGeneration>
     */
    private function recentGenerations(): Collection
    {
        return AiGeneration::query()
            ->with(['customer:id,name', 'generatedBy:id,name'])
            ->latest('generated_at')
            ->limit(self::ROW_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, ReportArtifact>
     */
    private function recentReports(): Collection
    {
        return ReportArtifact::query()
            ->with('generatedBy:id,name')
            ->latest('generated_at')
            ->limit(self::ROW_LIMIT)
            ->get();
    }
}
