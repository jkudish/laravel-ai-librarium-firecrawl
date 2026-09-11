<?php

declare(strict_types=1);

it('keeps surface and raw Search live canaries separately and exactly gated', function (): void {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $surface = $composer['scripts']['test:live'];
    $search = $composer['scripts']['test:live-search'];

    expect($surface)->toBeArray()
        ->and($search)->toBeArray()
        ->and(implode("\n", $surface))->toContain('LIBRARIUM_LIVE_TESTS')
        ->toContain('FIRECRAWL_LIVE_SPEND_ACK')
        ->not->toContain('FIRECRAWL_SEARCH_LIVE_CREDIT_ACK')
        ->and($surface[array_key_last($surface)])->toBe('vendor/bin/pest tests/Live/FirecrawlLiveTest.php --fail-on-skipped')
        ->and(implode("\n", $search))->toContain('LIBRARIUM_LIVE_TESTS')
        ->toContain('FIRECRAWL_API_KEY')
        ->toContain('FIRECRAWL_SEARCH_LIVE_CREDIT_ACK')
        ->toContain('acknowledge-one-search-request-up-to-3-results')
        ->not->toContain('FIRECRAWL_LIVE_SPEND_ACK')
        ->and($search[array_key_last($search)])->toBe('vendor/bin/pest tests/Live/FirecrawlSearchLiveTest.php --fail-on-skipped');
});
