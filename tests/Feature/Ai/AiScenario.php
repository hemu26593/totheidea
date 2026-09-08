<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\PromptVersionService;
use App\Models\AiPromptVersion;
use App\Models\Customer;
use App\Models\FormTemplate;
use App\Models\User;
use Tests\Support\RecordingAiProvider;

/**
 * The arrangement most AI tests need: a business, a template to draft into,
 * and a published prompt with a real schema.
 *
 * Prompts are published through PromptVersionService rather than written
 * directly, so every test runs against a prompt that got there the way a real
 * one would.
 */
final class AiScenario
{
    public Customer $customer;

    public FormTemplate $template;

    public AiPromptVersion $prompt;

    public function __construct(User $author)
    {
        $this->customer = Customer::factory()->active()->create();
        $this->template = FormTemplate::factory()->create();

        $this->prompt = app(PromptVersionService::class)->publish(
            app(PromptVersionService::class)->createDraft(
                key: 'form_draft',
                template: 'Propose an intake form for {{ business_name }}.',
                author: $author,
                outputSchema: AiFormDraftService::outputSchema(),
            ),
            $author,
        );
    }

    /**
     * A well-formed proposal, as a model would return it.
     */
    public static function validFormProposal(string $title = 'Operations intake'): string
    {
        return json_encode([
            'title' => $title,
            'sections' => [
                [
                    'title' => 'Today',
                    'description' => 'How the business runs now.',
                    'questions' => [
                        [
                            'label' => 'How many people work in the business?',
                            'type' => 'number',
                            'is_required' => true,
                        ],
                        [
                            'label' => 'Which area needs attention first?',
                            'type' => 'select_one',
                            'is_required' => false,
                            'options' => [
                                ['label' => 'Sales'],
                                ['label' => 'Operations'],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Bind a scripted provider for the duration of a test.
     *
     * @param  array<int, string|\Throwable>|string|\Throwable  $script
     */
    public static function bindProvider($script): RecordingAiProvider
    {
        $double = new RecordingAiProvider($script);

        app()->instance(AiProvider::class, $double);

        return $double;
    }
}
