<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Which transport AiProvider resolves to. The default is 'null', which
    | binds a provider that REFUSES loudly - the same posture the notification
    | channel takes. An application with no provider configured must fail
    | clearly rather than return plausible text, because plausible text from
    | nowhere is worse than an error: it looks like an answer.
    |
    | There is deliberately no 'fake' or 'echo' driver here. Test doubles
    | belong in tests, where they cannot reach production by a misconfigured
    | environment variable.
    |
    */

    'provider' => env('AI_PROVIDER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Anthropic transport
    |--------------------------------------------------------------------------
    |
    | Credentials come from the environment and are never committed. An absent
    | key is not an error at boot - it is an error at the moment a generation
    | is attempted, which is where it can be reported honestly and recorded
    | against the generation that failed.
    |
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),

        // The default model. A prompt version may pin its own
        // model_identifier for provenance, and that wins when set.
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),

        // Seconds. A web request never waits on this - generation runs in a
        // job - but an unbounded wait would still hold a worker forever.
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context minimisation
    |--------------------------------------------------------------------------
    |
    | Ceilings on what CustomerContextAssembler may send. The model is given
    | the smallest amount of a business's information that can answer the
    | question, never a database dump: every extra record is one more thing
    | that has left the building for no gain.
    |
    | These are caps, not targets. The assembler selects deliberately and
    | these stop a selection from growing unnoticed as the data does.
    |
    */

    'context' => [

        // Recent enrolments summarised per generation.
        'max_enrollments' => (int) env('AI_CONTEXT_MAX_ENROLLMENTS', 5),

        // Skill-area score rows carried into a narrative.
        'max_score_rows' => (int) env('AI_CONTEXT_MAX_SCORE_ROWS', 40),

        // Free-text answers carried across as quoted material.
        'max_free_text_answers' => (int) env('AI_CONTEXT_MAX_FREE_TEXT_ANSWERS', 10),

        // Characters of any single piece of customer-authored text. Truncation
        // is visible in the snapshot, so a clipped context can be recognised
        // rather than mistaken for the whole picture.
        'max_text_length' => (int) env('AI_CONTEXT_MAX_TEXT_LENGTH', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Generation is a slow external call and never blocks a web request
    | (CLAUDE.md architecture rule 11).
    |
    */

    'queue' => env('AI_QUEUE', 'default'),

];
