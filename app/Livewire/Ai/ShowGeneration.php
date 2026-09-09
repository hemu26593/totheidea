<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Domain\Ai\AiApprovalService;
use App\Domain\Ai\AiFormDraftService;
use App\Enums\AiGenerationStatus;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AiGeneration;
use App\Models\FormTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One generation: its provenance, the draft it produced, and the decision.
 *
 * THE LIFECYCLE IS NOT COLLAPSED. Drafting and approving are separate buttons
 * because they are separate acts by (necessarily) different people. There is
 * no control on this page that generates and publishes in one step, and there
 * is no code path from generation to a published form version that does not
 * pass through a human decision.
 *
 * THE SELF-APPROVAL INVARIANT IS SHOWN, NOT JUST ENFORCED. When the viewer is
 * the generator the decision controls are replaced by an explanation, so the
 * rule is legible rather than a mysterious 403. The enforcement itself lives
 * in three places behind this screen - AiGenerationPolicy, AiApprovalService,
 * and AiApproval's creating guard - and a tampered request meets all three.
 *
 * NO PROVIDER CONFIGURATION IS EXPOSED. The prompt key and version and the
 * model identifier are provenance; the API key, base URL and credentials are
 * not shown anywhere and are not reachable from this component.
 */
class ShowGeneration extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public int $generationId;

    public bool $deciding = false;

    public string $remark = '';

    public ?int $draftTemplateId = null;

    public bool $drafting = false;

    public function mount(AiGeneration $generation): void
    {
        $this->authorize('view', $generation);

        $this->generationId = (int) $generation->getKey();
    }

    public function startDraft(): void
    {
        $generation = $this->generation();

        $this->authorize('draft', $generation);

        $this->draftTemplateId = null;
        $this->drafting = true;
    }

    /**
     * Turn validated output into a DRAFT form version. It is never published
     * here: publication follows approval by a second person.
     */
    public function draft(AiFormDraftService $drafts): void
    {
        $generation = $this->generation();

        $this->authorize('draft', $generation);

        $this->validate(['draftTemplateId' => ['required', 'integer']]);

        $template = FormTemplate::query()->findOrFail($this->draftTemplateId);

        $saved = $this->runGuarded(
            fn () => $drafts->draftFrom($generation, $template),
            'Draft created. It now needs a decision from somebody other than the generator.',
        );

        if ($saved) {
            $this->drafting = false;
        }
    }

    public function startDecision(): void
    {
        $generation = $this->generation();

        // Gate::before does NOT short-circuit `approve` - it is a guarded
        // ability - so this reaches AiGenerationPolicy even for a Super Admin.
        $this->authorize('approve', $generation);

        $this->remark = '';
        $this->deciding = true;
    }

    public function approve(AiApprovalService $approvals): void
    {
        $generation = $this->generation();

        $this->authorize('approve', $generation);

        $saved = $this->runGuarded(
            fn () => $approvals->approve($generation, auth()->user(), $this->remark ?: null),
            'Approved. Any draft it produced has been published through the ordinary form engine.',
        );

        if ($saved) {
            $this->deciding = false;
        }
    }

    public function reject(AiApprovalService $approvals): void
    {
        $generation = $this->generation();

        $this->authorize('reject', $generation);

        $this->validate(['remark' => ['required', 'string', 'max:2000']], [
            'remark.required' => 'Say why it was rejected — the remark is the record of the judgement.',
        ]);

        $saved = $this->runGuarded(
            fn () => $approvals->reject($generation, auth()->user(), $this->remark),
            'Rejected. The draft stays a draft and is never published.',
        );

        if ($saved) {
            $this->deciding = false;
        }
    }

    public function render(): View
    {
        $generation = $this->generation();

        return view('livewire.ai.show-generation', [
            'generation' => $generation,
            // The whole point of the invariant, surfaced for the viewer.
            'viewerIsGenerator' => $generation->wasGeneratedBy(auth()->user()),
            'canDecide' => auth()->user()->can('approve', $generation),
            'templates' => $this->draftableTemplates($generation),
        ])->layout('components.layouts.app', ['title' => 'AI generation #'.$generation->getKey()]);
    }

    private function generation(): AiGeneration
    {
        return AiGeneration::query()
            ->with(['customer', 'generatedBy', 'promptVersion', 'approval.decidedBy', 'resultingFormVersion.formTemplate'])
            ->findOrFail($this->generationId);
    }

    /**
     * Shared curriculum, or this generation's own customer. A template
     * belonging to another business is refused by AiFormDraftService and is
     * not offered here either.
     */
    private function draftableTemplates(AiGeneration $generation): Collection
    {
        if ($generation->status !== AiGenerationStatus::Succeeded) {
            return collect();
        }

        return FormTemplate::query()
            ->where(function ($query) use ($generation): void {
                $query->whereNull('customer_id')->orWhere('customer_id', $generation->customer_id);
            })
            ->orderBy('name')
            ->get();
    }
}
