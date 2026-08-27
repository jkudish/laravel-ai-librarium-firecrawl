<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Firecrawl\Client\FirecrawlClient;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlDriver;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlResultMapper;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;

uses(CreatesRequests::class);

it('registers the official SDK and adapter without mutating core profiles by default', function (): void {
    expect(app(FirecrawlClient::class))->toBeInstanceOf(FirecrawlClient::class)
        ->and(app(FirecrawlDriver::class))->toBeInstanceOf(FirecrawlDriver::class)
        ->and(config('librarium.profiles.firecrawl-surface'))->toBeNull();
});

it('keeps Firecrawl outside the core package dependency boundary', function (): void {
    $coreRoot = InstalledVersions::getInstallPath('jkudish/laravel-ai-librarium');
    if (! is_string($coreRoot)) {
        $coreFile = (new ReflectionClass(Profile::class))->getFileName();
        $coreRoot = is_string($coreFile) ? dirname($coreFile, 2) : null;
    }
    expect($coreRoot)->toBeString()->not->toBe('');

    $core = json_decode(file_get_contents($coreRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $adapter = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($core['require'])->not->toHaveKey('firecrawl/firecrawl-sdk')
        ->and($adapter['require']['firecrawl/firecrawl-sdk'])->toBe('^1.13')
        ->and($adapter['require']['jkudish/laravel-ai-librarium'])->toBe('^1.0');
});

it('serializes only the allowlisted operation receipt through the core result contract', function (): void {
    $result = app(FirecrawlResultMapper::class)->result(
        $this->request(['mode' => 'agent']),
        [
            'completed' => true,
            'answer' => 'Observed answer.',
            'citations' => [['url' => 'https://example.com/source']],
            'challenge' => 'none',
            'login_wall' => false,
            'latency_ms' => 25,
        ],
        creditsUsed: 0,
        mode: 'agent',
        cleanup: 'not_applicable',
        providerOperationsStarted: 2,
    );

    expect($result->toArray()['provider_meta'])->toMatchArray([
        'operation_receipt' => [
            'mode' => 'agent',
            'stage' => 'observation',
            'cleanup' => 'not_applicable',
            'provider_operations_started' => 2,
        ],
        'credits_used' => 0,
    ])->and(array_keys($result->providerMeta->operation_receipt))->toBe([
        'mode',
        'stage',
        'cleanup',
        'provider_operations_started',
    ]);
});
