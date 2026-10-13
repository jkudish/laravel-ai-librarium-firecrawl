<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;

it('completes a paid Firecrawl Agent observation through polling', function (): void {
    if (getenv('LIBRARIUM_LIVE_TESTS') !== '1'
        || getenv('FIRECRAWL_LIVE_SPEND_ACK') !== 'acknowledge-2500-credit-maximum') {
        throw new RuntimeException('The live Firecrawl canary requires explicit test and spend acknowledgement.');
    }

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'librarium.runs.store' => 'memory',
        'librarium.timeout' => 300, 'librarium.engines.collector' => 'firecrawl',
        'librarium.engines.key' => (string) getenv('FIRECRAWL_API_KEY'),
        'librarium.engines.mode' => 'agent',
        'librarium.engines.custom.release-canary' => ['url' => (string) getenv('FIRECRAWL_LIVE_TARGET_URL')],
        'firecrawl-librarium.api_url' => 'https://api.firecrawl.dev']);

    $run = Librarium::research('Return the visible answer to: What is the title of this surface?')->only(['release-canary'])->run();
    expect($run->status())->toBe('done')->and($run->result('release-canary')['provenance']['collector'])->toBe('firecrawl');
});
