<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Access\FormLinkService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * A business's forms: what it can be sent, and what it has answered.
 *
 * A NEW SUBMISSION ALWAYS BINDS TO A PUBLISHED VERSION, resolved by
 * FormPublishingService. This screen never picks "the current version" itself
 * and never offers a draft: answering a draft would produce a submission whose
 * questions could still change underneath it.
 *
 * Templates offered are the shared curriculum plus this customer's own -
 * never another business's.
 *
 * SENDING A FORM LINK IS ONE BUTTON HERE AND ALL DOMAIN WORK ELSEWHERE.
 * FormLinkService issues the grant, builds the URL from the external route and
 * emails the business's own contact. This component resolves which enrolment
 * and form were clicked, proves both belong to this workspace, asks the policy,
 * and shows the answer.
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

    /**
     * Issue a scoped link for one of this business's forms and email it.
     *
     * Authorized twice over: the policy for the ability, and the workspace for
     * the records. A public Livewire method is an HTTP endpoint whatever the
     * page renders, so both ids from the browser are re-read and re-proved
     * rather than trusted.
     */
    public function sendFormLink(int $enrollmentId, int $templateId, FormLinkService $links): void
    {
        $this->authorize('create', AccessGrant::class);

        $enrollment = $this->enrollmentInWorkspace($enrollmentId);
        $template = $this->templateInScope($templateId);

        $contact = null;

        $sent = $this->runGuarded(function () use ($links, $enrollment, $template, &$contact): void {
            $contact = $links->send($enrollment, $template, auth()->user());
        }, 'Form link sent.');

        // Named after the fact: runGuarded sets its own message on success, and
        // the address is not known until the send has picked the contact.
        if ($sent && $contact !== null) {
            session()->flash('status', 'Form link sent to '.$contact->email.'.');
        }
    }

    /**
     * Withdraw the live link for this form.
     */
    public function revokeFormLink(int $enrollmentId, int $templateId, FormLinkService $links): void
    {
        $enrollment = $this->enrollmentInWorkspace($enrollmentId);
        $template = $this->templateInScope($templateId);

        // The policy is asked about the real grant, not about the class.
        $grant = $links->latestGrantFor($enrollment, $template);

        if ($grant === null) {
            abort(404);
        }

        $this->assertOwnedByWorkspace((int) $grant->customer_id);
        $this->authorize('revoke', $grant);

        $this->runGuarded(
            fn () => $links->revoke($enrollment, $template, auth()->user()),
            'Form link withdrawn. It no longer opens the form.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.customers.forms', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'sendable' => $this->sendableForms($customer),
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
     * Every form this business could be sent, and where its link stands.
     *
     * One row per (enrolment, published form). That pair is what a link is FOR
     * and what the grant is scoped to; a submission is what the business
     * creates by opening the link, which is what makes the answers theirs.
     *
     * @return Collection<int, object>
     */
    private function sendableForms(Customer $customer): Collection
    {
        $links = app(FormLinkService::class);
        $templates = $this->availableTemplates($customer);
        $rows = collect();

        foreach ($customer->enrollments()->with('batch:id,name,code')->get() as $enrollment) {
            foreach ($templates as $template) {
                $rows->push((object) [
                    'enrollment' => $enrollment,
                    'template' => $template,
                    'version' => $template->publishedVersion(),
                    'grant' => $links->latestGrantFor($enrollment, $template),
                    // Presentation only. Both actions re-check the policy and
                    // the workspace server-side.
                    'sendable' => $links->canBeSent($customer, $enrollment, $template),
                ]);
            }
        }

        return $rows;
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
