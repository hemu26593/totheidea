<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * A business's forms: what has been asked, and what is outstanding.
 *
 * A NEW SUBMISSION ALWAYS BINDS TO A PUBLISHED VERSION, resolved by
 * FormPublishingService. This screen never picks "the current version" itself
 * and never offers a draft: answering a draft would produce a submission whose
 * questions could still change underneath it.
 *
 * Templates offered are the shared curriculum plus this customer's own -
 * never another business's.
 */
class Forms extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public bool $starting = false;

    public ?int $enrollmentId = null;

    public ?int $templateId = null;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startSubmission(): void
    {
        $this->authorize('create', FormSubmission::class);

        $this->reset(['enrollmentId', 'templateId']);
        $this->starting = true;
    }

    public function start(SubmissionService $submissions, FormPublishingService $publishing): void
    {
        $this->authorize('create', FormSubmission::class);

        $this->validate([
            'enrollmentId' => ['required', 'integer'],
            'templateId' => ['required', 'integer'],
        ]);

        $enrollment = $this->enrollmentInWorkspace((int) $this->enrollmentId);
        $template = $this->templateInScope((int) $this->templateId);

        $saved = $this->runGuarded(function () use ($submissions, $publishing, $enrollment, $template): void {
            $submissions->startDraft(
                $enrollment,
                // The published version, resolved by the domain. Never "the
                // latest", never a draft.
                $publishing->versionForNewSubmission($template),
                auth()->user(),
            );
        }, 'Form started.');

        if ($saved) {
            $this->starting = false;
        }
    }

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.customers.forms', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'submissions' => FormSubmission::query()
                ->where('customer_id', $customer->getKey())
                ->with(['formVersion.formTemplate', 'enrollment.batch:id,name,code'])
                ->withCount('scores')
                ->latest('updated_at')
                ->get(),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            'templates' => $this->availableTemplates($customer),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Forms']);
    }

    /**
     * Templates that have something answerable: shared curriculum, or this
     * customer's own. A template belonging to another business is not in this
     * list and would be refused by templateInScope() anyway.
     */
    private function availableTemplates(Customer $customer): Collection
    {
        return FormTemplate::query()
            ->where(function ($query) use ($customer): void {
                $query->whereNull('customer_id')->orWhere('customer_id', $customer->getKey());
            })
            ->whereHas('versions', fn ($v) => $v->where('status', 'published'))
            ->orderBy('name')
            ->get();
    }

    private function templateInScope(int $templateId): FormTemplate
    {
        $template = FormTemplate::query()->findOrFail($templateId);

        // Null customer_id is shared curriculum and is legitimately reachable.
        if ($template->customer_id !== null) {
            $this->assertOwnedByWorkspace((int) $template->customer_id);
        }

        return $template;
    }

    private function enrollmentInWorkspace(int $enrollmentId): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }
}
