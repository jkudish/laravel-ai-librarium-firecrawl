<?php

declare(strict_types=1);

use Firecrawl\Client\FirecrawlClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlDriver;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlResultMapper;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;
use Mockery\MockInterface;

uses(CreatesRequests::class);

function sdkWith(array $responses): FirecrawlClient
{
    $mock = new MockHandler(array_map(
        static fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
        $responses,
    ));

    return FirecrawlClient::create(
        apiKey: 'fc-test-key',
        apiUrl: 'https://api.firecrawl.test',
        httpClient: new Client(['handler' => HandlerStack::create($mock)]),
    );
}

function bindSdk(FirecrawlClient $client): stdClass
{
    $calls = new stdClass;
    $calls->count = 0;
    app()->instance(CreatesFirecrawlClient::class, new readonly class($client, $calls) implements CreatesFirecrawlClient
    {
        public function __construct(private FirecrawlClient $client, private stdClass $calls) {}

        public function forRequest(DriverRequest $request): FirecrawlClient
        {
            $this->calls->count++;

            return $this->client;
        }
    });

    return $calls;
}

function observation(array $overrides = []): array
{
    return [
        'completed' => true,
        'answer' => 'Observed answer.',
        'citations' => [['url' => 'https://example.com/source', 'title' => 'Source', 'excerpt' => 'Evidence']],
        'challenge' => 'none',
        'login_wall' => false,
        'latency_ms' => 1250,
        'artifacts' => [['kind' => 'screenshot', 'url' => 'https://artifacts.example/one']],
        ...$overrides,
    ];
}

it('uses the SDK for scrape and cleanup while normalizing prompt interaction facts', function (): void {
    $factory = bindSdk(sdkWith([
        ['success' => true, 'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-1']]],
        ['success' => true],
    ]));
    Http::fake(['*' => Http::response(['success' => true, 'output' => json_encode(observation(), JSON_THROW_ON_ERROR)])]);

    $request = $this->request([
        'locale' => 'en-CA',
        'country' => 'CA',
        'device' => 'mobile',
        'personalization' => 'absent',
        'account_context' => 'signed_out',
    ]);
    $result = app(FirecrawlDriver::class)->run($request);

    expect($result->content)->toBe('Observed answer.')
        ->and($result->provenance->surface)->toBe('example-ai')
        ->and($result->provenance->collector)->toBe('firecrawl')
        ->and($result->provenance->context['authentication']->value)->toBe('anonymous')
        ->and($result->provenance->context['country'])->toBe('CA')
        ->and($result->providerMeta->challenge)->toBe('none')
        ->and($result->providerMeta->consumer_declared_context)->toBe([
            'personalization' => 'absent',
            'account_context' => 'signed_out',
        ])
        ->and($result->providerMeta->evidence_receipts)->toBe([[
            'kind' => 'screenshot',
            'reference_sha256' => hash('sha256', 'https://artifacts.example/one'),
            'reference_state' => 'redacted',
        ]])
        ->and($result->providerMeta->operation_receipt)->toBe([
            'mode' => 'interact',
            'stage' => 'observation',
            'cleanup' => 'completed',
            'provider_operations_started' => 3,
        ])
        ->and($result->citations)->toHaveCount(1)
        ->and($factory->count)->toBe(2);
});

it('maps unexpected scrape failures to a fixed safe stage error', function (): void {
    app()->instance(CreatesFirecrawlClient::class, new readonly class implements CreatesFirecrawlClient
    {
        public function forRequest(DriverRequest $request): FirecrawlClient
        {
            throw new RuntimeException('secret-key https://provider.test/path scrape-id headers body stack');
        }
    });

    expect(fn () => app(FirecrawlDriver::class)->run($this->request()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.scrape_failed')
                ->and($exception->getMessage())->toBe('Firecrawl could not complete the scrape stage.')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain('secret-key')
                ->not->toContain('provider.test')
                ->not->toContain('scrape-id');
        });
});

it('maps unexpected interaction failures to a fixed safe stage error', function (): void {
    bindSdk(sdkWith([
        ['success' => true, 'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-secret']]],
        ['success' => true],
    ]));
    Http::fake(static function (): never {
        throw new RuntimeException('secret-key https://provider.test/path scrape-secret headers body stack');
    });

    expect(fn () => app(FirecrawlDriver::class)->run($this->request()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.interaction_failed')
                ->and($exception->getMessage())->toBe('Firecrawl could not complete the interaction stage.')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain('secret-key')
                ->not->toContain('provider.test')
                ->not->toContain('scrape-secret');
        });
});

it('maps unexpected Agent transport failures to the fixed interaction stage error', function (): void {
    app()->instance(CreatesFirecrawlClient::class, new readonly class implements CreatesFirecrawlClient
    {
        public function forRequest(DriverRequest $request): FirecrawlClient
        {
            throw new RuntimeException('secret-key https://provider.test/path agent-id headers body stack');
        }
    });

    expect(fn () => app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent'])))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.interaction_failed')
                ->and($exception->getMessage())->toBe('Firecrawl could not complete the interaction stage.')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain('secret-key')
                ->not->toContain('provider.test')
                ->not->toContain('agent-id');
        });
});

it('maps unexpected observation failures to a fixed safe stage error', function (): void {
    bindSdk(sdkWith([
        ['success' => true, 'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-secret']]],
        ['success' => true],
    ]));
    Http::fake(['*' => Http::response(['success' => true, 'output' => json_encode(observation(), JSON_THROW_ON_ERROR)])]);
    $validator = Mockery::mock(ValidationFactory::class, function (MockInterface $mock): void {
        $mock->shouldReceive('make')
            ->andThrow(new RuntimeException('secret-key https://provider.test/path scrape-secret headers body stack'));
    });
    app()->instance(FirecrawlResultMapper::class, new FirecrawlResultMapper($validator));

    expect(fn () => app(FirecrawlDriver::class)->run($this->request()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.observation_failed')
                ->and($exception->getMessage())->toBe('Firecrawl could not map the surface observation.')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain('secret-key')
                ->not->toContain('provider.test')
                ->not->toContain('scrape-secret');
        });
});

it('preserves specific driver errors across stage boundaries', function (): void {
    app()->instance(CreatesFirecrawlClient::class, new readonly class implements CreatesFirecrawlClient
    {
        public function forRequest(DriverRequest $request): FirecrawlClient
        {
            throw new DriverException('firecrawl.connection', 'Firecrawl could not be reached.', false);
        }
    });

    expect(fn () => app(FirecrawlDriver::class)->run($this->request()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl.connection')
                ->and($exception->getMessage())->toBe('Firecrawl could not be reached.')
                ->and($exception->fallbackAllowed)->toBeFalse();
        });
});

it('rejects incompatible grounding and corpora before creating an SDK client', function (GroundingPolicy $grounding, array $corpora): void {
    $factory = bindSdk(sdkWith([]));

    expect(fn () => app(FirecrawlDriver::class)->run($this->request(
        ['mode' => 'agent'],
        grounding: $grounding,
        corpora: $corpora,
    )))->toThrow(DriverException::class, 'incompatible surface semantics');
    expect($factory->count)->toBe(0);
})->with([
    'required grounding' => [GroundingPolicy::Required, [Corpus::Web]],
    'no grounding' => [GroundingPolicy::None, [Corpus::Web]],
    'extra corpus' => [GroundingPolicy::Optional, [Corpus::Web, Corpus::News]],
    'wrong corpus' => [GroundingPolicy::Optional, [Corpus::News]],
]);

it('rejects invalid consumer-declared context before creating an SDK client', function (array $options): void {
    $factory = bindSdk(sdkWith([]));

    expect(fn () => app(FirecrawlDriver::class)->run($this->request($options)))
        ->toThrow(DriverException::class, 'consumer-declared context is invalid');
    expect($factory->count)->toBe(0);
})->with([
    'personalization prose' => [['personalization' => 'probably personalized']],
    'unsupported account claim' => [['account_context' => 'premium-account']],
]);

it('retains only explicitly allowlisted non-capability artifact references', function (): void {
    config()->set('firecrawl-librarium.public_artifact_references', [
        'https://artifacts.example/public/shot.png',
        'https://artifacts.example/browser/session/secret-recording',
        'https://artifacts.example/%2574oken/value',
        'https://artifacts.example/public/abcdefghijklmnopqrstuvwxyz123456.png',
        'https://artifacts.example/public/token=short',
        'https://artifacts.example/public/key%3Ftoken=short',
    ]);
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => observation([
            'artifacts' => [
                ['kind' => 'screenshot', 'url' => 'https://artifacts.example/public/shot.png'],
                ['kind' => 'recording', 'url' => 'https://artifacts.example/browser/session/secret-recording'],
                ['kind' => 'trace', 'url' => 'https://artifacts.example/trace.json?token=secret'],
                ['kind' => 'trace', 'url' => 'https://artifacts.example/%2574oken/value'],
                ['kind' => 'trace', 'url' => 'https://artifacts.example/public/abcdefghijklmnopqrstuvwxyz123456.png'],
                ['kind' => 'trace', 'url' => 'https://artifacts.example/public/token=short'],
                ['kind' => 'trace', 'url' => 'https://artifacts.example/public/key%3Ftoken=short'],
            ],
        ])],
    ]));

    $result = app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));
    $receipts = $result->providerMeta->evidence_receipts;

    expect($receipts[0])->toBe([
        'kind' => 'screenshot',
        'reference_sha256' => hash('sha256', 'https://artifacts.example/public/shot.png'),
        'reference_state' => 'retained',
        'reference' => 'https://artifacts.example/public/shot.png',
    ])->and($receipts[1])->not->toHaveKey('reference')
        ->and($receipts[1]['reference_state'])->toBe('redacted')
        ->and($receipts[2])->not->toHaveKey('reference')
        ->and($receipts[2]['reference_state'])->toBe('redacted')
        ->and($receipts[3])->not->toHaveKey('reference')
        ->and($receipts[3]['reference_state'])->toBe('redacted')
        ->and($receipts[4])->not->toHaveKey('reference')
        ->and($receipts[4]['reference_state'])->toBe('redacted')
        ->and($receipts[5])->not->toHaveKey('reference')
        ->and($receipts[5]['reference_state'])->toBe('redacted')
        ->and($receipts[6])->not->toHaveKey('reference')
        ->and($receipts[6]['reference_state'])->toBe('redacted');

    expect($result->toArray())->toBeArray();
});

it('submits and retrieves an Agent job through the official SDK', function (): void {
    $factory = bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => observation(), 'model' => 'spark', 'creditsUsed' => 9],
    ]));

    $result = app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));

    expect($result->content)->toBe('Observed answer.')
        ->and($result->model)->toBe('spark')
        ->and($result->providerMeta->credits_used)->toBe(9)
        ->and($result->providerMeta->operation_receipt)->toBe([
            'mode' => 'agent',
            'stage' => 'observation',
            'cleanup' => 'not_applicable',
            'provider_operations_started' => 2,
        ])
        ->and($factory->count)->toBe(2);
});

it('omits provider credits outside the bounded nonnegative range', function (int $credits): void {
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => observation(), 'creditsUsed' => $credits],
    ]));

    $result = app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));

    expect($result->providerMeta)->not->toHaveProperty('credits_used')
        ->and(array_keys($result->providerMeta->operation_receipt))->toBe([
            'mode',
            'stage',
            'cleanup',
            'provider_operations_started',
        ]);
})->with([
    'negative' => -1,
    'above interoperable bound' => 2_147_483_648,
]);

it('does not let best-effort browser cleanup start after the request deadline', function (): void {
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-1']],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = FirecrawlClient::create(
        apiKey: 'fc-test-key',
        apiUrl: 'https://api.firecrawl.test',
        httpClient: new Client(['handler' => $stack]),
    );
    app()->instance(CreatesFirecrawlClient::class, new readonly class($client) implements CreatesFirecrawlClient
    {
        public function __construct(private FirecrawlClient $client) {}

        public function forRequest(DriverRequest $request): FirecrawlClient
        {
            if (now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('firecrawl.deadline_exceeded', 'deadline');
            }

            return $this->client;
        }
    });
    Http::fake(function () {
        now()->addSeconds(3)->setTestNow();

        return Http::response(['success' => true, 'output' => json_encode(observation(), JSON_THROW_ON_ERROR)]);
    });

    $result = app(FirecrawlDriver::class)->run($this->request(deadlineSeconds: 2));

    expect($result->content)->toBe('Observed answer.')
        ->and($result->providerMeta->operation_receipt)->toBe([
            'mode' => 'interact',
            'stage' => 'observation',
            'cleanup' => 'failed',
            'provider_operations_started' => 3,
        ])
        ->and($history)->toHaveCount(1);
});

it('omits insecure webhook callback URLs from Agent submissions', function (): void {
    config()->set('firecrawl-librarium.webhook.url', 'http://consumer.example/webhooks/firecrawl');
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"id":"agent-job-1"}'),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'status' => 'completed',
            'data' => observation(),
        ], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    bindSdk(FirecrawlClient::create(
        apiKey: 'fc-test-key',
        apiUrl: 'https://api.firecrawl.test',
        httpClient: new Client(['handler' => $stack]),
    ));

    app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));
    $submission = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($submission)->not->toHaveKey('webhook')
        ->and($submission['schema']['properties']['citations']['items'])->toBe([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['url'],
            'properties' => [
                'url' => ['type' => 'string', 'maxLength' => 2048],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 500],
                'excerpt' => ['type' => ['string', 'null'], 'maxLength' => 1000],
            ],
        ])->and($submission['schema']['properties']['artifacts']['items'])->toBe([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['kind', 'url'],
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['screenshot', 'recording', 'trace']],
                'url' => ['type' => 'string', 'maxLength' => 2048],
            ],
        ]);
});

it('uses a bound terminal webhook only once as a polling wake hint', function (): void {
    Sleep::fake();
    config()->set('firecrawl-librarium.webhook.url', 'https://consumer.example/webhooks/firecrawl');
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"id":"agent-job-1"}'),
        function () {
            app(WebhookSignalStore::class)->record('firecrawl', 'agent-job-1', 'delivery-1', ResearchState::Completed);

            return new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"status":"processing"}');
        },
        new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"status":"processing"}'),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'status' => 'completed',
            'data' => observation(),
        ], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    bindSdk(FirecrawlClient::create(
        apiKey: 'fc-test-key',
        apiUrl: 'https://api.firecrawl.test',
        httpClient: new Client(['handler' => $stack]),
    ));

    $result = app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));
    $submission = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($result->content)->toBe('Observed answer.')
        ->and($submission['webhook']['url'])->toBe('https://consumer.example/webhooks/firecrawl')
        ->and($submission['webhook']['events'])->toBe(['completed', 'failed'])
        ->and($submission['webhook']['metadata'])->toBe(['request_id' => 'request-1', 'profile' => 'firecrawl-surface']);
    Sleep::assertSleptTimes(1);
});

it('reports delayed and stalled states as nonterminal until the earlier deadline', function (): void {
    Sleep::fake(true, true);
    config()->set('firecrawl-librarium.expected_duration_seconds', 1);
    config()->set('firecrawl-librarium.poll_interval_seconds', 1);
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'processing'],
        ['success' => true, 'status' => 'processing'],
        ['success' => true, 'status' => 'processing'],
        ['success' => true, 'status' => 'processing'],
    ]));
    $progress = new stdClass;
    $progress->messages = [];

    expect(fn () => app(FirecrawlDriver::class)->run($this->request(
        ['mode' => 'agent'],
        function (string $message) use ($progress): void {
            $progress->messages[] = $message;
        },
        3,
    )))->toThrow(DriverException::class, 'trustworthy deadline');

    expect($progress->messages)->toContain('Firecrawl Agent is delayed but remains nonterminal.')
        ->toContain('Firecrawl Agent appears stalled but remains nonterminal.');
});

it('rejects expired provider deadlines', function (): void {
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'processing', 'expiresAt' => now()->subSecond()->toIso8601String()],
    ]));

    expect(fn () => app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent'])))
        ->toThrow(DriverException::class, 'trustworthy deadline');

});

it('retains challenge and login-wall observations without inventing an answer', function (array $facts, string $text): void {
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => observation($facts)],
    ]));

    $result = app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));
    expect($result->content)->toBe($text);
})->with([
    'captcha' => [['completed' => false, 'answer' => null, 'challenge' => 'captcha'], 'The observed surface presented a captcha challenge.'],
    'login wall' => [['completed' => false, 'answer' => null, 'login_wall' => true], 'The observed surface presented a login wall.'],
]);

it('fails deterministic validation for incomplete ordinary output and oversized evidence', function (array $facts): void {
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => observation($facts)],
    ]));

    expect(fn () => app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent'])))
        ->toThrow(DriverException::class, 'invalid surface observation');
})->with([
    'missing completion' => [['completed' => false, 'answer' => null]],
    'too many artifacts' => [['artifacts' => array_fill(0, 11, ['kind' => 'trace', 'url' => 'https://example.com/a'])]],
    'capability scheme' => [['artifacts' => [['kind' => 'trace', 'url' => 'cdp://browser/session']]]],
    'unknown field' => [['raw_payload' => 'forbidden']],
]);

it('reports only invalid field paths without leaking rejected provider values', function (): void {
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => observation([
            'citations' => [['url' => 'not-a-url', 'excerpt' => 'secret-provider-value']],
        ])],
    ]));

    expect(fn () => app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent'])))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->getMessage())->toContain('Invalid fields: citations.0.url.')
                ->not->toContain('not-a-url')
                ->not->toContain('secret-provider-value');
        });
});

it('normalizes omitted optional artifacts to no evidence receipts', function (): void {
    $observation = observation();
    unset($observation['artifacts']);
    bindSdk(sdkWith([
        ['success' => true, 'id' => 'agent-job-1'],
        ['success' => true, 'status' => 'completed', 'data' => $observation],
    ]));

    $result = app(FirecrawlDriver::class)->run($this->request(['mode' => 'agent']));

    expect($result->content)->toBe('Observed answer.')
        ->and($result->providerMeta)->not->toHaveProperty('evidence_receipts');
});
