<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;

it('completes a paid Firecrawl Agent observation through polling', function (): void {
    if (getenv('LIBRARIUM_LIVE_TESTS') !== '1'
        || getenv('FIRECRAWL_LIVE_SPEND_ACK') !== 'acknowledge-2500-credit-maximum') {
        throw new RuntimeException('The live Firecrawl canary requires explicit test and spend acknowledgement.');
    }

    $profile = config('firecrawl-librarium.profile');
    expect($profile)->toBeArray();
    $profile['options'] = [
        'mode' => 'agent',
        'target_url' => (string) getenv('FIRECRAWL_LIVE_TARGET_URL'),
        'surface' => (string) (getenv('FIRECRAWL_LIVE_SURFACE') ?: 'release-canary'),
        'authentication' => 'anonymous',
    ];
    $profile['credential'] = (string) getenv('FIRECRAWL_API_KEY');
    config()->set('librarium.profiles.firecrawl-live', $profile);
    config()->set('firecrawl-librarium.api_url', 'https://api.firecrawl.dev');

    $response = Librarium::query('Return the visible answer to: What is the title of this surface?')
        ->using('firecrawl-live')
        ->timeout(300)
        ->run();

    expect($response->status)->toBe(ResponseStatus::Succeeded)
        ->and($response->results)->toHaveCount(1)
        ->and($response->results->sole()->provenance->collector)->toBe('firecrawl');
});
