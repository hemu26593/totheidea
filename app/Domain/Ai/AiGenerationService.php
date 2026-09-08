<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Enums\AuditAction;
use App\Exceptions\AiGenerationFailedException;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Exceptions\AiSchemaValidationException;
use App\Exceptions\CustomerIsolationException;
use App\Models\AiGeneration;
use App\Models\AiPromptVersion;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * GENERATE -> VALIDATE. The first two stages of the AI lifecycle, and no more.
 *
 * This class calls a provider, validates what comes back, and records the
 * whole thing. It DOES NOT draft, does not approve and does not publish. The
 * stages are separate classes because collapsing them is exactly how "an
 * admin reviewed this" quietly becomes "the system decided this" - a
 * generation that drafted and published itself would leave no moment at which
 * a person could have said no.
 *
 * PROVENANCE IS WRITTEN BEFORE THE CALL, NOT AFTER. The row exists as
 * `pending` with its context snapshot already stored, so a crash, a timeout
 * or a provider outage leaves evidence rather than nothing.
 *
 * A FAILURE IS A RECORD, NOT AN EXCEPTION THAT ESCAPES. Transport failures,
 * refusals and schema violations all settle the row as `failed` with the
 * reason, because "the model was asked and could not answer" is information
 * the approval queue needs.
 *
 * validated_output IS SET ONLY WHEN VALIDATION PASSED. That is the whole
 * contract of the column: its presence means the output conformed to the
 * schema the prompt declared. Nothing else may write it.
 */
class AiGenerationService
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly PromptRenderer $renderer,
        private readonly SchemaValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Run one invocation for one customer and settle its outcome.
     *
     * @param  array<string, string|int|float|null>  $placeholders  values for the prompt template - system values only
     */
    public function generate(
        Customer $customer,
        AiPromptVersion $prompt,
        AiPurpose $purpose,
        CustomerContext $context,
        User $actor,
        array $placeholders = [],
    ): AiGeneration {
        $this->assertContextMatchesCustomer($customer, $context);

        $generation = $this->recordAttempt($customer, $prompt, $purpose, $context, $actor);

        try {
            $response = $this->provider->generate(new AiRequest(
                instructions: $this->renderer->render($prompt, $placeholders),
                context: $context->render(),
                outputSchema: $prompt->output_schema,
                modelIdentifier: $prompt->model_identifier,
            ));
        } catch (AiProviderNotConfiguredException|AiGenerationFailedException $e) {
            return $this->settleFailed($generation, $e->getMessage());
        }

        return $this->validateAndSettle($generation, $prompt, $response, $actor);
    }

    /**
     * The row that exists before anything can go wrong.
     */
    private function recordAttempt(
        Customer $customer,
        AiPromptVersion $prompt,
        AiPurpose $purpose,
        CustomerContext $context,
        User $actor,
    ): AiGeneration {
        return DB::transaction(function () use ($customer, $prompt, $purpose, $context, $actor): AiGeneration {
            $generation = new AiGeneration;
            $generation->forceFill([
                'customer_id' => $customer->getKey(),
                'ai_prompt_version_id' => $prompt->getKey(),
                'purpose' => $purpose,
                // Written once. Never read back into business logic.
                'input_context' => $context->toArray(),
                'status' => AiGenerationStatus::Pending,
                'generated_by' => $actor->getKey(),
                'generated_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::AiGenerated,
                $generation,
                null,
                [
                    'customer_id' => (int) $customer->getKey(),
                    'purpose' => $purpose->value,
                    'ai_prompt_version_id' => (int) $prompt->getKey(),
                ],
                $actor,
            );

            return $generation->refresh();
        });
    }

    /**
     * Validate the proposal and settle the row accordingly.
     *
     * A prompt with no declared schema is prose - a narrative - and the raw
     * text is stored without a validated_output, because there is no
     * structure to conform to. It still cannot become a figure: everything
     * downstream treats a narrative as text.
     */
    private function validateAndSettle(
        AiGeneration $generation,
        AiPromptVersion $prompt,
        AiResponse $response,
        User $actor,
    ): AiGeneration {
        if ($prompt->output_schema === null) {
            $generation->forceFill([
                'raw_output' => $response->text,
                'status' => AiGenerationStatus::Succeeded,
                'tokens_used' => $response->tokensUsed,
            ])->save();

            return $generation->refresh();
        }

        try {
            $validated = $this->validator->validate($response->text, $prompt->output_schema);
        } catch (AiSchemaValidationException $e) {
            // The raw output IS kept: reviewing what the model actually said
            // is how a prompt gets fixed. What is not kept is any pretence
            // that it was usable.
            return $this->settleFailed($generation, $e->getMessage(), $response);
        }

        $generation->forceFill([
            'raw_output' => $response->text,
            'validated_output' => $validated,
            'status' => AiGenerationStatus::Succeeded,
            'tokens_used' => $response->tokensUsed,
        ])->save();

        return $generation->refresh();
    }

    private function settleFailed(AiGeneration $generation, string $reason, ?AiResponse $response = null): AiGeneration
    {
        $generation->forceFill([
            'raw_output' => $response?->text,
            'validated_output' => null,
            'status' => AiGenerationStatus::Failed,
            'validation_error' => $reason,
            'tokens_used' => $response?->tokensUsed,
        ])->save();

        return $generation->refresh();
    }

    /**
     * The context and the customer must be the same business.
     *
     * Belt and braces over an already-scoped assembler: the one way this
     * could go wrong is a caller assembling for one customer and recording
     * against another, and that is worth refusing outright rather than
     * trusting a convention.
     */
    private function assertContextMatchesCustomer(Customer $customer, CustomerContext $context): void
    {
        if ($context->customerId !== (int) $customer->getKey()) {
            throw CustomerIsolationException::contextCustomerMismatch(
                $context->customerId,
                (int) $customer->getKey(),
            );
        }
    }
}
