<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Firecrawl\Client\FirecrawlClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlDriver;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlResultMapper;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlSearchDriver;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;

uses(CreatesRequests::class);

/** @param list<array<string, mixed>> $responses */
function packageIntegrationSdk(array $responses): FirecrawlClient
{
    $handler = new MockHandler(array_map(
        static fn (array $body): Response => new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        ),
        $responses,
    ));

    return FirecrawlClient::create(
        apiKey: 'fc-test-key',
        apiUrl: 'https://api.firecrawl.test',
        httpClient: new Client(['handler' => HandlerStack::create($handler)]),
    );
}

function bindPackageIntegrationSdk(FirecrawlClient $client): stdClass
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

/** @return array<string, mixed> */
function packageIntegrationObservation(): array
{
    return [
        'completed' => true,
        'answer' => 'Observed through the package runtime.',
        'citations' => [['url' => 'https://example.com/source']],
        'challenge' => 'none',
        'login_wall' => false,
        'latency_ms' => 25,
        'artifacts' => [['kind' => 'screenshot', 'url' => 'https://private.example/session/token']],
    ];
}

/** @return array<string, mixed> */
function packageIntegrationProfile(string $mode): array
{
    $profile = config('firecrawl-librarium.profile');
    if (! is_array($profile)) {
        throw new RuntimeException('The Firecrawl package Profile is not configured.');
    }

    $profile['credential'] = 'fc-test-key';
    $profile['options'] = [
        'mode' => $mode,
        'target_url' => 'https://example.com/ai',
        'surface' => 'example-ai',
        'authentication' => 'anonymous',
        'personalization' => 'absent',
        'account_context' => 'signed_out',
        ...($mode === 'interact' ? [
            'locale' => 'en-CA',
            'country' => 'CA',
            'device' => 'desktop',
        ] : []),
    ];

    return $profile;
}

it('registers the official SDK and adapter without mutating core profiles by default', function (): void {
    expect(app(FirecrawlClient::class))->toBeInstanceOf(FirecrawlClient::class)
        ->and(app(FirecrawlDriver::class))->toBeInstanceOf(FirecrawlDriver::class)
        ->and(app(FirecrawlSearchDriver::class))->toBeInstanceOf(FirecrawlSearchDriver::class)
        ->and(config('librarium.profiles.firecrawl-surface'))->toBeNull()
        ->and(config('librarium.profiles.firecrawl-search'))->toBeNull();
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

it('runs raw Search through core preflight and terminal result acceptance', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => ['news' => [[
            'title' => 'Core-compatible result',
            'url' => 'https://news.example/core',
            'snippet' => 'Accepted by the shared contract.',
            'date' => '2026-09-10',
        ]]],
        'creditsUsed' => 2,
    ])]);
    $profile = config('firecrawl-librarium.search_profile');
    expect($profile)->toBeArray();
    assert(is_array($profile));
    $profile['credential'] = 'fc-test-key';
    $profile['corpora'] = ['news'];
    $profile['options'] = ['sources' => ['news'], 'limit' => 3];
    config()->set('librarium.profiles.firecrawl-search', $profile);

    $result = Librarium::query('What is new?')
        ->using('firecrawl-search')
        ->run()
        ->results
        ->sole();
    $serialized = $result->toArray();

    expect($result->provider)->toBe('firecrawl-search')
        ->and($result->profile)->toBe('firecrawl-search')
        ->and($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->collector)->toBeNull()
        ->and($result->provenance->surface)->toBeNull()
        ->and($result->providerMeta->credits_used)->toBe(2)
        ->and(json_encode(ResearchResult::fromArray($serialized)->toArray(), JSON_THROW_ON_ERROR))
        ->toBe(json_encode($serialized, JSON_THROW_ON_ERROR));
    Http::assertSentCount(1);
});

it('keeps raw Search preview pricing unavailable without a truthful preflight credit quantity', function (): void {
    $profile = config('firecrawl-librarium.search_profile');
    expect($profile)->toBeArray()->not->toHaveKey('pricing');
    assert(is_array($profile));
    $profile['credential'] = 'fc-test-key';
    config()->set('librarium.profiles.firecrawl-search', $profile);

    $preview = Librarium::query('What is new?')->using('firecrawl-search')->preview();
    $pricing = $preview->pricingQuotes->sole();

    expect($pricing['identity'])->toBeNull()
        ->and($pricing['usage'])->toBeNull()
        ->and($pricing['quote']->completeness)->toBe(CostCompleteness::Unavailable)
        ->and($preview->pricingStatus)->toBe('unavailable')
        ->and($preview->hardCostCeilingAvailable)->toBeFalse()
        ->and($preview->maximumEstimatedCost)->toBeNull();
    Http::assertNothingSent();
});

it('runs each provider mode through core preflight and result acceptance', function (string $mode): void {
    $observation = packageIntegrationObservation();
    $responses = $mode === 'interact'
        ? [
            ['success' => true, 'data' => ['markdown' => 'initial', 'metadata' => ['scrapeId' => 'scrape-1']]],
            ['success' => true],
        ]
        : [
            ['success' => true, 'id' => 'agent-job-1'],
            ['success' => true, 'status' => 'completed', 'data' => $observation],
        ];
    $factory = bindPackageIntegrationSdk(packageIntegrationSdk($responses));
    Http::fake($mode === 'interact' ? [
        '*' => Http::response(['success' => true, 'output' => json_encode($observation, JSON_THROW_ON_ERROR)]),
    ] : []);
    config()->set('librarium.profiles.firecrawl-package', packageIntegrationProfile($mode));

    $result = Librarium::query('What is new?')
        ->using('firecrawl-package')
        ->run()
        ->results
        ->sole();

    expect($result->provenance->resultKind)->toBe(ResultKind::SurfaceObservation)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SurfaceCollector])
        ->and($result->provenance->observationMode)->toBe(ObservationMode::SurfaceSnapshot)
        ->and($result->provenance->collector)->toBe('firecrawl')
        ->and($result->provenance->surface)->toBe('example-ai')
        ->and($result->provenance->context)->toBe(['authentication' => Authentication::Unknown])
        ->and($result->providerMeta->consumer_declared_context)->toBe([
            ...($mode === 'interact' ? [
                'locale' => 'en-CA',
                'country' => 'CA',
                'device' => 'desktop',
            ] : []),
            'authentication' => 'anonymous',
            'personalization' => 'absent',
            'account_context' => 'signed_out',
        ])
        ->and($result->providerMeta->evidence_receipts[0])->toBe([
            'kind' => 'screenshot',
            'reference_sha256' => hash('sha256', 'https://private.example/session/token'),
            'reference_state' => 'redacted',
        ])
        ->and($result->providerMeta->operation_receipt['cleanup'])->toBe(
            $mode === 'interact' ? 'completed' : 'not_applicable',
        )
        ->and($factory->count)->toBe(2);

    if ($mode === 'interact') {
        expect($result->providerMeta->configured_context)->toBe([
            'locale' => 'en-CA',
            'country' => 'CA',
            'device' => 'desktop',
        ]);
    } else {
        expect($result->providerMeta)->not->toHaveProperty('configured_context');
    }
})->with(['interact', 'agent']);

it('rejects legacy Firecrawl surface semantics during core preflight without reaching the provider', function (): void {
    $factory = bindPackageIntegrationSdk(packageIntegrationSdk([]));
    $profile = packageIntegrationProfile('agent');
    $profile['result_kind'] = 'grounded_answer';
    $profile['retrieval_methods'] = ['research_agent'];
    config()->set('librarium.profiles.firecrawl-package', $profile);

    expect(fn () => Librarium::query('What is new?')->using('firecrawl-package')->preview())
        ->toThrow(ValidationException::class, 'Surface Profiles must use surface_observation');
    expect($factory->count)->toBe(0);
});

it('retains both context metadata layers through terminal core serialization', function (): void {
    $result = app(FirecrawlResultMapper::class)->result(
        $this->request([
            'locale' => 'en-CA',
            'country' => 'CA',
            'device' => 'mobile',
            'personalization' => 'present',
            'account_context' => 'unknown',
        ]),
        [
            'completed' => true,
            'answer' => 'Observed answer.',
            'citations' => [['url' => 'https://example.com/source']],
            'challenge' => 'none',
            'login_wall' => false,
            'latency_ms' => 25,
        ],
        creditsUsed: 0,
        mode: 'interact',
        cleanup: 'completed',
        providerOperationsStarted: 3,
    );
    $serialized = $result->toArray();
    $roundTrip = ResearchResult::fromArray($serialized)->toArray();

    expect($serialized['provenance']['context'])->toBe(['authentication' => 'unknown'])
        ->and($serialized['provider_meta'])->toMatchArray([
            'consumer_declared_context' => [
                'locale' => 'en-CA',
                'country' => 'CA',
                'device' => 'mobile',
                'authentication' => 'anonymous',
                'personalization' => 'present',
                'account_context' => 'unknown',
            ],
            'configured_context' => [
                'locale' => 'en-CA',
                'country' => 'CA',
                'device' => 'mobile',
            ],
            'operation_receipt' => [
                'mode' => 'interact',
                'stage' => 'observation',
                'cleanup' => 'completed',
                'provider_operations_started' => 3,
            ],
            'credits_used' => 0,
        ])->and(json_encode($roundTrip, JSON_THROW_ON_ERROR))
        ->toBe(json_encode($serialized, JSON_THROW_ON_ERROR));
});

it('round-trips historical Firecrawl provenance without backfilling new metadata', function (): void {
    $historical = app(FirecrawlResultMapper::class)->result(
        $this->request(),
        [
            'completed' => true,
            'answer' => 'Historical observed answer.',
            'citations' => [['url' => 'https://example.com/historical-source']],
            'challenge' => 'none',
            'login_wall' => false,
            'latency_ms' => 25,
        ],
    )->toArray();
    $historical['provenance']['context'] = [
        'locale' => 'en-CA',
        'country' => 'CA',
        'device' => 'desktop',
        'authentication' => 'anonymous',
    ];
    unset($historical['provider_meta']->consumer_declared_context, $historical['provider_meta']->configured_context);

    expect(json_encode(ResearchResult::fromArray($historical)->toArray(), JSON_THROW_ON_ERROR))
        ->toBe(json_encode($historical, JSON_THROW_ON_ERROR));
});
