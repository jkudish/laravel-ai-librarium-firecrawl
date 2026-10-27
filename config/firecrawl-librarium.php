<?php

declare(strict_types=1);

return [
    'api_url' => config('firecrawl.api_url', 'https://api.firecrawl.dev'),
    'allow_custom_api_url' => false,
    'webhook' => [
        'url' => null,
        'secret' => null,
    ],
    'poll_interval_seconds' => 2,
    'expected_duration_seconds' => 60,
    // Only exact public HTTPS references listed here may be retained. All others become redacted digest receipts.
    'public_artifact_references' => [],
    'webhook_idempotency_ttl' => 10800,
    'options' => [
        'mode' => 'interact',
        'authentication' => 'anonymous',
    ],
    'urls' => [
        'chatgpt' => 'https://chatgpt.com/',
        'gemini-app' => 'https://gemini.google.com/',
        'google-ai-mode' => 'https://www.google.com/aimode',
        'google-ai-overview' => 'https://www.google.com/',
        'copilot' => 'https://copilot.microsoft.com/',
    ],
];
