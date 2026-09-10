<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Programme\EnrollmentService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Enrolling a business in a batch, and the lifecycle afterwards.
 *
 * The three transitions - enrol, withdraw, complete - are the only ones the
 * domain has, and each goes through EnrollmentService, which holds the
 * capacity check, the uniqueness rule and the audit entry. Nothing here writes
 * an enrolment row directly.
 */
class Enrollments extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public bool $enrolling = false;

    public ?int $batchId = null;

    public string $paymentDueDate = '';

    public ?int $withdrawingId = null;

    public string $withdrawalReason = '';

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startEnrolment(): void
    {
        $this->authorize('create', Enrollment::class);

        $this->reset(['batchId', 'paymentDueDate']);
        $this->enrolling = true;
    }

    public function enrol(EnrollmentService $enrollments): void
    {
        $this->authorize('create', Enrollment::class);

        $this->validate([
            'batchId' => ['required', 'integer', 'exists:batches,id'],
            'paymentDueDate' => ['nullable', 'date'],
        ]);

        $batch = Batch::query()->findOrFail($this->batchId);

        $saved = $this->runGuarded(function () use ($enrollments, $batch): void {
            $enrollments->enrol(
                $this->customer(),
                $batch,
                auth()->user(),
                $this->paymentDueDate !== '' ? $this->paymentDueDate : null,
            );
        }, 'Enrolled.');

        if ($saved) {
            $this->enrolling = false;
        }
    }

    public function startWithdrawal(int $enrollmentId): void
    {
        $enrollment = $this->enrollmentInWorkspace($enrollmentId);

        $this->authorize('update', $enrollment);

        $this->withdrawingId = $enrollmentId;
        $this->withdrawalReason = '';
    }

    public function withdraw(EnrollmentService $enrollments): void
    {
        $enrollment = $this->enrollmentInWorkspace((int) $this->withdrawingId);

        $this->authorize('update', $enrollment);

        $this->validate(['withdrawalReason' => ['required', 'string', 'max:255']]);

        $saved = $this->runGuarded(
            fn () => $enrollments->withdraw($enrollment, $this->withdrawalReason, auth()->user()),
            'Enrolment withdrawn.',
        );

        if ($saved) {
            $this->withdrawingId = null;
        }
    }

    public function complete(int $enrollmentId, EnrollmentService $enrollments): void
    {
        $enrollment = $this->enrollmentInWorkspace($enrollmentId);

        $this->authorize('update', $enrollment);

        $this->runGuarded(
            fn () => $enrollments->complete($enrollment, auth()->user()),
            'Enrolment completed.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();

        $enrollments = $customer->enrollments()
            ->with(['batch.program'])
            ->withCount([
                'attendances',
                'formSubmissions',
                'assignmentSubmissions',
                'actionItems as open_action_items_count' => fn ($q) => $q->where('status', 'open'),
            ])
            ->latest('enrolled_at')
            ->get();

        return view('livewire.customers.enrollments', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'enrollments' => $enrollments,
            'availableBatches' => $this->availableBatches($enrollments->pluck('batch_id')->all()),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Enrolments']);
    }

    /**
     * Batches this business is not already in. UNIQUE (customer_id, batch_id)
     * is a database constraint, so offering one would only produce an error.
     *
     * @param  array<int, int|null>  $alreadyIn
     */
    private function availableBatches(array $alreadyIn): Collection
    {
        return Batch::query()
            ->with('program:id,name')
            ->whereNull('archived_at')
            ->whereNotIn('id', array_filter($alreadyIn))
            ->orderByDesc('starts_on')
            ->get();
    }

    private function enrollmentInWorkspace(int $enrollmentId): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }
}
