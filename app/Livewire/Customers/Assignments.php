<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Assignments\AssignmentSubmissionService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Customer;
use App\Models\Enrollment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * This business's assignments: what was set, and what it has submitted.
 *
 * Only RELEASED assignments appear. A draft is staged work that the batch has
 * not been given yet, and listing it here would tell a participant about work
 * that does not exist for them.
 *
 * Staff may record a submission on a participant's behalf - participants have
 * no account - and the actor triple records that it was staff who did it.
 */
class Assignments extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public ?int $submittingInstanceId = null;

    public ?int $submittingEnrollmentId = null;

    public string $body = '';

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startSubmission(int $instanceId, int $enrollmentId): void
    {
        $this->authorize('create', AssignmentSubmission::class);

        $this->enrollmentInWorkspace($enrollmentId);

        $this->submittingInstanceId = $instanceId;
        $this->submittingEnrollmentId = $enrollmentId;
        $this->body = '';
    }

    public function submit(AssignmentSubmissionService $submissions): void
    {
        $this->authorize('create', AssignmentSubmission::class);

        $this->validate(['body' => ['required', 'string', 'max:20000']]);

        $enrollment = $this->enrollmentInWorkspace((int) $this->submittingEnrollmentId);
        $instance = AssignmentInstance::query()->findOrFail($this->submittingInstanceId);

        $saved = $this->runGuarded(function () use ($submissions, $instance, $enrollment): void {
            // The service asserts that the instance and the enrolment resolve
            // to the same batch (invariant I4). Recorded as a staff action.
            $submissions->submit($instance, $enrollment, $this->body, auth()->user());
        }, 'Submission recorded.');

        if ($saved) {
            $this->submittingInstanceId = null;
        }
    }

    public function render(): View
    {
        $customer = $this->customer();
        $enrollments = $customer->enrollments()->with('batch:id,name,code')->get();

        $instances = AssignmentInstance::query()
            ->where('status', AssignmentInstance::STATUS_RELEASED)
            ->whereHas('sessionInstance', fn ($q) => $q->whereIn('batch_id', $enrollments->pluck('batch_id')->filter()))
            ->with(['sessionInstance.batch:id,name,code', 'sessionInstance.sessionTemplate:id,sequence,title'])
            ->orderBy('due_at')
            ->get();

        $submissions = AssignmentSubmission::query()
            ->where('customer_id', $customer->getKey())
            ->with('reviews.reviewedBy')
            ->get()
            ->keyBy('assignment_instance_id');

        return view('livewire.customers.assignments', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'instances' => $instances,
            'submissions' => $submissions,
            'enrollments' => $enrollments->keyBy('batch_id'),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Assignments']);
    }

    private function enrollmentInWorkspace(int $enrollmentId): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }
}
