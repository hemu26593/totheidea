<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\AiGenerationService;
use App\Domain\Ai\CustomerContextAssembler;
use App\Domain\Ai\PromptVersionService;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use App\Models\Customer;
use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generation off the web request (CLAUDE.md architecture rule 11).
 *
 * A model call takes seconds at best, so nothing waits on it: the browser
 * gets an immediate answer and the approval queue gets the result.
 *
 * IT CARRIES IDS, NOT MODELS. A serialised model would be a snapshot of a row
 * that may have moved, and authorization has to be decided against the record
 * as it is when the work runs.
 *
 * AUTHORIZATION IS RE-CHECKED HERE. A queued job is a separate execution with
 * no session behind it; a permission revoked between the request and the run
 * must take effect. AI-initiated work goes through the same policies as any
 * other work.
 *
 * THE JOB STOPS AT A DRAFT. It never approves and never publishes - those
 * need a second person, which is the whole reason the stages are separate.
 */
class GenerateFormDraftJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $formTemplateId,
        public readonly int $actorId,
        public readonly string $brief,
    ) {}

    public function handle(
        AiGenerationService $generations,
        CustomerContextAssembler $assembler,
        PromptVersionService $prompts,
        AiFormDraftService $drafts,
    ): void {
        $customer = Customer::query()->find($this->customerId);
        $template = FormTemplate::query()->find($this->formTemplateId);
        $actor = User::query()->find($this->actorId);

        if ($customer === null || $template === null || $actor === null) {
            return;
        }

        // Same policy, same answer, whether the caller is a browser or a
        // worker.
        if (! $actor->can('generate', [AiGeneration::class, AiPurpose::FormDraft])) {
            return;
        }

        $generation = $generations->generate(
            customer: $customer,
            prompt: $prompts->publishedFor('form_draft'),
            purpose: AiPurpose::FormDraft,
            context: $assembler->forFormDraft($customer, $this->brief),
            actor: $actor,
            placeholders: ['business_name' => $customer->name],
        );

        // A failed generation stops here, recorded. It is not retried
        // silently: a schema failure usually means the prompt needs work, and
        // repeating the same call would only spend money to fail again.
        if ($generation->status !== AiGenerationStatus::Succeeded) {
            return;
        }

        $drafts->draftFrom($generation, $template);
    }
}
