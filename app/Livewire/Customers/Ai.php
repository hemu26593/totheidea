<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Ai\AiGenerationService;
use App\Domain\Ai\CustomerContextAssembler;
use App\Domain\Ai\PromptVersionService;
use App\Enums\AiPurpose;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AiGeneration;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * AI activity for one business, and the place a generation is commissioned.
 *
 * GENERATION NEVER PUBLISHES. This screen asks a model for a proposal and
 * records it; drafting and approving are separate acts on separate screens by
 * separate people. Nothing here writes a published form version, a score, an
 * attendance mark or an MMD figure.
 *
 * The permission asked depends on the purpose: drafting a form changes the
 * instrument every participant answers (ai.forms.generate), while a narrative
 * is analysis (ai.analysis.generate). Staff hold neither.
 */
class Ai extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public bool $generating = false;

    public string $brief = '';

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startGeneration(): void
    {
        $this->authorizeGenerate();

        $this->brief = '';
        $this->generating = true;
    }

    /**
     * Commission a form-draft generation for THIS customer.
     *
     * The context is assembled by the domain, scoped to this customer's id;
     * AiGenerationService refuses a context assembled for anyone else, so a
     * tampered workspace id cannot produce a cross-customer prompt.
     */
    public function generate(
        AiGenerationService $generations,
        CustomerContextAssembler $assembler,
        PromptVersionService $prompts,
    ): void {
        $this->authorizeGenerate();

        $this->validate(['brief' => ['required', 'string', 'max:2000']]);

        $customer = $this->customer();

        $saved = $this->runGuarded(function () use ($generations, $assembler, $prompts, $customer): void {
            $generations->generate(
                customer: $customer,
                prompt: $prompts->publishedFor('form_draft'),
                purpose: AiPurpose::FormDraft,
                context: $assembler->forFormDraft($customer, $this->brief),
                actor: auth()->user(),
                placeholders: ['business_name' => $customer->name],
            );
        }, 'Generation recorded. Review it before anything is applied.');

        if ($saved) {
            $this->generating = false;
        }
    }

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.customers.ai', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'generations' => AiGeneration::query()
                ->where('customer_id', $customer->getKey())
                ->with(['generatedBy:id,name', 'promptVersion:id,key,version_number', 'approval.decidedBy'])
                ->latest('generated_at')
                ->get(),
            'canGenerate' => auth()->user()->can('ai.forms.generate'),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — AI']);
    }

    private function authorizeGenerate(): void
    {
        if (! auth()->user()->can('ai.forms.generate')) {
            abort(403);
        }
    }
}
