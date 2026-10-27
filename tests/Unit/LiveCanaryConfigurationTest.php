<?php

declare(strict_types=1);

it('keeps only the explicitly gated browser canary in this adapter', function (): void {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $surface = $composer['scripts']['test:live'];

    expect($surface)->toBeArray()
        ->and(implode("\n", $surface))->toContain('LIBRARIUM_LIVE_TESTS')
        ->toContain('FIRECRAWL_LIVE_SPEND_ACK')
        ->not->toContain('FIRECRAWL_SEARCH_LIVE_CREDIT_ACK')
        ->and($surface[array_key_last($surface)])->toBe('vendor/bin/pest tests/Live/FirecrawlLiveTest.php --fail-on-skipped')
        ->and($composer['scripts'])->not->toHaveKey('test:live-search');
});
