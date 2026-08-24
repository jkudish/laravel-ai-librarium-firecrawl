<?php

declare(strict_types=1);

use Firecrawl\Client\FirecrawlClient;
use GuzzleHttp\Client;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlClientFactory;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;

uses(CreatesRequests::class);

it('creates an official SDK client bound to the request identity and remaining deadline', function (): void {
    config()->set('firecrawl-librarium.api_url', 'https://private.firecrawl.example/v2');
    config()->set('firecrawl-librarium.allow_custom_api_url', true);

    $client = app(FirecrawlClientFactory::class)->forRequest($this->request(deadlineSeconds: 65));
    $firecrawlReflection = new ReflectionClass(FirecrawlClient::class);
    $http = $firecrawlReflection->getProperty('http')->getValue($client);
    $httpReflection = new ReflectionObject($http);
    $guzzle = $httpReflection->getProperty('httpClient')->getValue($http);

    expect($httpReflection->getProperty('apiKey')->getValue($http))->toBe('fc-test-key')
        ->and($httpReflection->getProperty('baseUrl')->getValue($http))->toBe('https://private.firecrawl.example/v2')
        ->and($httpReflection->getProperty('maxRetries')->getValue($http))->toBe(0)
        ->and($guzzle)->toBeInstanceOf(Client::class)
        ->and($guzzle->getConfig('timeout'))->toBeGreaterThanOrEqual(63.0)
        ->toBeLessThanOrEqual(65.0);
});

it('rejects insecure or unapproved custom SDK endpoints', function (string $url, bool $allowCustom): void {
    config()->set('firecrawl-librarium.api_url', $url);
    config()->set('firecrawl-librarium.allow_custom_api_url', $allowCustom);

    expect(fn () => app(FirecrawlClientFactory::class)->forRequest($this->request()))
        ->toThrow(DriverException::class, 'API URL is invalid');
})->with([
    'insecure' => ['http://api.firecrawl.dev', true],
    'custom without opt-in' => ['https://private.firecrawl.example', false],
]);
