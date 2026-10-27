<?php

declare(strict_types=1);

use Firecrawl\Client\FirecrawlClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\StoredRun;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'librarium.runs.store' => 'memory',
        'librarium.engines.collector' => 'firecrawl', 'librarium.engines.key' => 'fc-test-key',
        'librarium.engines.custom.example-ai' => ['url' => 'https://example.com/ai']]);
    Bus::fake();
    Sleep::fake();
});

/** @param list<array<string, mixed>> $responses */
function engineSdk(array $responses, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        fn (array $body) => new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
        $responses,
    )));
    $stack->push(Middleware::history($history));
    $client = FirecrawlClient::create(apiKey: 'fc-test-key', apiUrl: 'https://api.firecrawl.test', httpClient: new Client(['handler' => $stack]));
    app()->instance(CreatesFirecrawlClient::class, new readonly class($client) implements CreatesFirecrawlClient
    {
        public function __construct(private FirecrawlClient $client) {}

        public function forRequest(DriverRequest|RecoveryRequest $request): FirecrawlClient
        {
            return $this->client;
        }
    });
}

/** @return array<string, mixed> */
function engineObservation(): array
{
    return ['completed' => true, 'answer' => 'Observed answer.', 'citations' => [['url' => 'https://example.com/source']],
        'challenge' => 'none', 'login_wall' => false, 'latency_ms' => 25,
        'artifacts' => [['kind' => 'screenshot', 'url' => 'https://private.example/session/token']]];
}

it('registers built-in and custom engines as named-only surface sources while search stays in core', function (): void {
    $sources = Librarium::sources();
    expect(array_column($sources['engines'], 'id'))->toBe(['chatgpt', 'gemini-app', 'google-ai-mode', 'google-ai-overview', 'copilot', 'example-ai']);
    $plan = Librarium::research('q')->only(['example-ai', 'chatgpt'])->dryRun();
    expect(array_column($plan->toArray()['results'], 'source'))->toBe(['chatgpt', 'example-ai'])
        ->and($plan->result('chatgpt')['observation'])->toBe('surface')
        ->and(Librarium::find($plan->id()))->toBeNull()
        ->and(array_column(Librarium::research('q')->dryRun()->toArray()['results'], 'source'))->toBe(['firecrawl']);
    Http::assertNothingSent();
});

it('uses Interact inline for a custom URL and stores its redacted evidence', function (): void {
    $history = [];
    engineSdk([
        ['success' => true, 'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-1']]],
        ['success' => true],
    ], $history);
    Http::fake(['*' => Http::response(['success' => true, 'output' => json_encode(engineObservation()), 'apiKey' => 'fc-test-key'])]);
    $run = Librarium::research('q')->only(['example-ai'])->run();
    expect($run->status())->toBe('done')->and($run->result('example-ai')['answer'])->toBe('Observed answer.')
        ->and($run->result('example-ai')['provenance']['collector'])->toBe('firecrawl')
        ->and($run->citations()[0]['url'])->toBe('https://example.com/source')
        ->and($run->evidence('example-ai'))->toHaveCount(1)
        ->and(json_encode($run->evidence('example-ai')))->not->toContain('fc-test-key')
        ->and(app(LibrariumManager::class)->store()->find($run->id())->state)->not->toHaveKey('handles')
        ->and(json_decode((string) $history[0]['request']->getBody(), true)['url'])->toBe('https://example.com/ai');
    Http::assertSentCount(1);
});

it('queues a quick Agent engine and recovers a signed private handle without trusting webhook output', function (): void {
    config(['librarium.engines.mode' => 'agent', 'firecrawl-librarium.webhook.url' => 'https://app.example/webhook',
        'firecrawl-librarium.webhook.secret' => 'a-long-test-webhook-secret']);
    $history = [];
    engineSdk([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'processing'],
        ['success' => true, 'status' => 'completed', 'data' => engineObservation(), 'creditsUsed' => 9],
    ], $history);
    $queued = Librarium::research('q')->only(['example-ai'])->queue();
    $id = $queued->id();
    unset($queued);
    Bus::assertDispatched(RunResearch::class, fn (RunResearch $job) => $job->runId === $id);
    app(RunRuntime::class)->start($id);
    $store = app(LibrariumManager::class)->store();
    $doc = $store->find($id);
    expect($doc->state['handles']['example-ai']['reference'])->toBe('agent-job-1')
        ->and(json_encode($doc))->not->toContain('fc-test-key')
        ->and(json_encode(Librarium::find($id)))->not->toContain('agent-job-1');
    $event = json_encode(['type' => 'agent.completed', 'id' => 'agent-job-1', 'webhookId' => 'delivery-1', 'data' => ['answer' => 'Untrusted webhook answer.']]);
    $this->call('POST', '/librarium/webhooks/firecrawl', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FIRECRAWL_SIGNATURE' => 'sha256='.hash_hmac('sha256', $event, 'a-long-test-webhook-secret'),
    ], $event)->assertNoContent();
    expect(Librarium::find($id)->status())->toBe('running');
    expect(Librarium::find($id)->refresh()->status())->toBe('running');
    $done = Librarium::find($id)->refresh();
    expect($done->status())->toBe('done')->and($done->result('example-ai')['answer'])->toBe('Observed answer.')
        ->and($done->evidence('example-ai'))->toHaveCount(3)
        ->and($done->toArray()['cost']['reported'])->toBeNull()
        ->and(array_map(fn (array $exchange) => $exchange['request']->getMethod(), $history))->toBe(['POST', 'GET', 'GET']);
    Sleep::assertNeverSlept();
});

it('rejects a tampered Agent reference before SDK retrieval', function (): void {
    config(['librarium.engines.mode' => 'agent']);
    $history = [];
    engineSdk([['success' => true, 'id' => 'agent-job-1']], $history);
    $queued = Librarium::research('q')->only(['chatgpt'])->queue();
    $id = $queued->id();
    unset($queued);
    app(RunRuntime::class)->start($id);
    $store = app(LibrariumManager::class)->store();
    $doc = $store->find($id);
    $state = $doc->state;
    $state['handles']['chatgpt']['reference'] = 'agent-job-other';
    $store->save(new StoredRun($doc->run, $state, $doc->files, $doc->version), $doc->version);
    expect(fn () => Librarium::find($id)->refresh())->toThrow(ValidationException::class);
    expect($history)->toHaveCount(1);
});

it('rejects target URL userinfo before any provider call', function (): void {
    config(['librarium.engines.custom.example-ai.url' => 'https://consumer@example.com/ai']);
    expect(fn () => Librarium::research('q')->only(['example-ai'])->dryRun())->toThrow(ValidationException::class, 'must not contain user information');
    Http::assertNothingSent();
});

it('preserves strict fenced observation decoding through a stored Run', function (bool $valid): void {
    $history = [];
    engineSdk([
        ['success' => true, 'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-1']]],
        ['success' => true],
    ], $history);
    $json = json_encode(engineObservation());
    Http::fake(['*' => Http::response(['success' => true, 'output' => "```json\n{$json}\n```".($valid ? '' : '\nsecret-prose')])]);
    $run = Librarium::research('q')->only(['example-ai'])->run();
    expect($run->status())->toBe($valid ? 'done' : 'failed')
        ->and(json_encode($run))->not->toContain('secret-prose');
    if (! $valid) {
        expect($run->result('example-ai')['error']['code'])->toBe('firecrawl.invalid_output');
    }
})->with([true, false]);
