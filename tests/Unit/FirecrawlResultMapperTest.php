<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlResultMapper;

/** @return array<string, mixed> */
function mapperObservation(array $overrides = []): array
{
    return [
        'completed' => true,
        'answer' => 'Observed answer from the sanitized surface.',
        'citations' => [['url' => 'https://example.com/source']],
        'challenge' => 'none',
        'login_wall' => false,
        'latency_ms' => 1250,
        ...$overrides,
    ];
}

it('decodes both unfenced JSON and one precisely bounded outer JSON fence', function (bool $fenced): void {
    $json = json_encode(mapperObservation(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $output = $fenced ? "```json\n{$json}\n```\n" : $json;

    expect(app(FirecrawlResultMapper::class)->decode($output))->toBe(mapperObservation());
})->with([
    'existing unfenced JSON' => [false],
    'single outer json fence' => [true],
]);

it('does not extract JSON from non-exact Markdown wrappers', function (Closure $output): void {
    expect(fn () => app(FirecrawlResultMapper::class)->decode($output()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.invalid_output')
                ->and($exception->getMessage())->toBe('Firecrawl returned invalid observation JSON.');
        });
})->with([
    'leading prose' => [fn (): string => "Observation:\n```json\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```"],
    'trailing prose' => [fn (): string => "```json\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```\nDone."],
    'multiple blocks' => [fn (): string => "```json\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```\n```json\n{}\n```"],
    'nested block' => [fn (): string => "```json\n```json\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```\n```"],
    'generic fence' => [fn (): string => "```\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```"],
    'ambiguous fence info' => [fn (): string => "```json observation\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```"],
    'uppercase fence info' => [fn (): string => "```JSON\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```"],
    'indented outer fence' => [fn (): string => " ```json\n".json_encode(mapperObservation(), JSON_THROW_ON_ERROR)."\n```"],
]);

it('rejects malformed JSON inside an exact outer fence', function (): void {
    expect(fn () => app(FirecrawlResultMapper::class)->decode("```json\n{\"completed\": true\n```"))
        ->toThrow(DriverException::class, 'invalid observation JSON');
});

it('still validates the observation schema after removing an exact outer fence', function (): void {
    $json = json_encode(mapperObservation(['provider_envelope' => 'must-not-pass']), JSON_THROW_ON_ERROR);

    expect(fn () => app(FirecrawlResultMapper::class)->decode("```json\n{$json}\n```"))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.invalid_output')
                ->and($exception->getMessage())->toBe('Firecrawl returned an invalid surface observation. Invalid fields: observation.')
                ->and($exception->getMessage())->not->toContain('must-not-pass');
        });
});
