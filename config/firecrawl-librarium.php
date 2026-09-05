<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlDriver;

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
    'register_profile' => false,
    'profile_id' => 'firecrawl-surface',
    'profile' => [
        'driver' => FirecrawlDriver::class,
        'provider' => 'firecrawl',
        'model' => null,
        'result_kind' => 'surface_observation',
        'grounding' => 'optional',
        'observation' => 'surface_snapshot',
        'corpora' => ['web'],
        'retrieval_methods' => ['surface_collector'],
        'prompt' => '{{ query }}',
        'enabled' => true,
        'options' => [
            'mode' => 'interact',
            'target_url' => null,
            'surface' => null,
            'authentication' => 'anonymous',
        ],
        'credential' => config('firecrawl.api_key'),
    ],
];
