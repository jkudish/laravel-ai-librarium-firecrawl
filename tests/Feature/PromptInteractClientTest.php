<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrariumFirecrawl\Http\PromptInteractClient;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;

uses(CreatesRequests::class);

it('sends a contract-valid prompt-only Interact request and maps only bounded output', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'output' => '{"completed":true}',
        'cdpUrl' => 'wss://secret.example/cdp',
        'interactiveLiveViewUrl' => 'https://secret.example/live',
    ])]);

    $result = app(PromptInteractClient::class)->interact($this->request(), 'scrape/one', 'Observe this surface.');

    expect($result)->toBe(['output' => '{"completed":true}']);
    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.firecrawl.test/v2/scrape/scrape%2Fone/interact'
            && $data['prompt'] === 'Observe this surface.'
            && isset($data['timeout'])
            && ! array_key_exists('code', $data)
            && ! array_key_exists('language', $data)
            && $request->hasHeader('Authorization', 'Bearer fc-test-key');
    });
});

it('rejects invalid prompt lengths and malformed output with safe errors', function (): void {
    expect(fn () => app(PromptInteractClient::class)->interact($this->request(), 'id', ''))
        ->toThrow(DriverException::class, 'between 1 and 10,000');
    expect(fn () => app(PromptInteractClient::class)->interact($this->request(), 'id', str_repeat('x', 10_001)))
        ->toThrow(DriverException::class, 'between 1 and 10,000');

    Http::fake(['*' => Http::response(['success' => true, 'cdpUrl' => 'wss://secret'])]);
    expect(fn () => app(PromptInteractClient::class)->interact($this->request(), 'id', 'valid'))
        ->toThrow(DriverException::class, 'no bounded interaction output');

    Http::fake(['*' => Http::response(['success' => true, 'output' => str_repeat('x', 131_073)])]);
    expect(fn () => app(PromptInteractClient::class)->interact($this->request(), 'id', 'valid'))
        ->toThrow(DriverException::class, 'no bounded interaction output');
});

it('does not start an Interact request after the deadline', function (): void {
    Http::fake();

    expect(fn () => app(PromptInteractClient::class)->interact($this->request(deadlineSeconds: 0), 'id', 'valid'))
        ->toThrow(DriverException::class, 'research deadline');
    Http::assertNothingSent();
});

it('requires an explicit opt-in for custom HTTPS API endpoints', function (): void {
    config()->set('firecrawl-librarium.allow_custom_api_url', false);

    expect(fn () => app(PromptInteractClient::class)->interact($this->request(), 'id', 'valid'))
        ->toThrow(DriverException::class, 'API URL is invalid');
});
