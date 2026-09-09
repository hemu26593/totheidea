<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Reporting\ReportArtifactService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\ReportArtifact;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Reports about this business.
 *
 * SCOPED TO THIS CUSTOMER'S OWN ENROLMENTS, and the scoping is by subject id
 * rather than by a filter the client could change: the artifact list is built
 * from the enrolment ids this workspace owns, so another business's report is
 * not in the result set at all.
 *
 * Participant Progress is the enrolment-level report; batch-level reports live
 * on the global Reports screen, because a batch spans businesses and is not
 * one customer's to see in this context.
 */
class Reports extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public ?int $enrollmentId = null;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);

        $this->enrollmentId = $customer->enrollments()->where('status', 'enrolled')->value('id')
            ?? $customer->enrollments()->value('id');
    }

    public function export(string $format, ReportArtifactService $reports): void
    {
        $this->authorize('export', ReportArtifact::class);

        if (! in_array($format, ReportArtifact::FORMATS, true)) {
            abort(422);
        }

        $enrollment = $this->enrollment();

        $this->runGuarded(
            fn () => $reports->export('participant_progress', $enrollment, auth()->user(), $format),
            strtoupper($format).' generated.',
        );
    }

    public function download(int $artifactId)
    {
        $artifact = $this->artifactInWorkspace($artifactId);

        $this->authorize('download', $artifact);

        if (! Storage::disk($artifact->disk)->exists($artifact->path)) {
            $this->addError('domain', 'The stored report file is missing from the disk.');

            return null;
        }

        return Storage::disk($artifact->disk)->download(
            $artifact->path,
            $artifact->report_key.'.'.$artifact->format,
        );
    }

    public function render(): View
    {
        $customer = $this->customer();
        $enrollmentIds = $customer->enrollments()->pluck('id');

        return view('livewire.customers.reports', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            // Built from this workspace's own enrolment ids. Another
            // business's artifact is not in the query, let alone the page.
            'artifacts' => ReportArtifact::query()
                ->where('subject_type', (new Enrollment)->getMorphClass())
                ->whereIn('subject_id', $enrollmentIds)
                ->with('generatedBy:id,name')
                ->latest('generated_at')
                ->get(),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Reports']);
    }

    private function enrollment(): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($this->enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }

    /**
     * An artifact id from the browser is verified to be about one of THIS
     * workspace's enrolments before its bytes are served.
     */
    private function artifactInWorkspace(int $artifactId): ReportArtifact
    {
        $artifact = ReportArtifact::query()->findOrFail($artifactId);

        if ($artifact->subject_type !== (new Enrollment)->getMorphClass()) {
            abort(404);
        }

        $enrollment = Enrollment::query()->find($artifact->subject_id);

        $this->assertOwnedByWorkspace((int) ($enrollment?->customer_id));

        return $artifact;
    }
}
