<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;

beforeEach(function (): void {
    config()->set('firecrawl-librarium.webhook.secret', 'a-long-test-webhook-secret');
});

function signedFirecrawlPost(array $event, ?string $signature = null)
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $signature ??= 'sha256='.hash_hmac('sha256', $body, 'a-long-test-webhook-secret');

    return test()->call('POST', '/librarium/webhooks/firecrawl', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FIRECRAWL_SIGNATURE' => $signature,
    ], $body);
}

it('verifies the raw-body HMAC, records terminal hints, and deduplicates webhookId delivery', function (): void {
    $store = app(WebhookSignalStore::class);
    $store->bind('firecrawl', 'request-1', 'profile-1', 'job-1');
    $event = ['success' => true, 'type' => 'agent.completed', 'id' => 'job-1', 'webhookId' => 'delivery-1', 'data' => []];

    signedFirecrawlPost($event)->assertNoContent();
    signedFirecrawlPost($event)->assertNoContent();

    expect($store->get('request-1', 'profile-1')?->state)->toBe(ResearchState::Completed);
});

it('rejects invalid signatures and jobs not bound to the exact request and Profile', function (): void {
    signedFirecrawlPost(['type' => 'agent.failed', 'id' => 'job-x', 'webhookId' => 'delivery-x'], 'sha256='.str_repeat('0', 64))
        ->assertUnauthorized();

    signedFirecrawlPost(['type' => 'agent.failed', 'id' => 'job-x', 'webhookId' => 'delivery-x'])
        ->assertUnprocessable();
});

it('ignores signed webhook events outside the terminal Agent allowlist', function (): void {
    signedFirecrawlPost(['type' => 'crawl.completed', 'id' => 'job-x', 'webhookId' => 'delivery-x'])
        ->assertNoContent();

    expect(Cache::has('librarium:firecrawl:webhook:'.hash('sha256', "job-x\0delivery-x")))->toBeFalse();
});
