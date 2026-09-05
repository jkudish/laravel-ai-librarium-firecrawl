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
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlDriver;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlResultMapper;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;

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
    ];

    return $profile;
}

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
        ->and($result->provenance->context)->not->toHaveKeys(['personalization', 'account_context'])
        ->and($result->providerMeta->consumer_declared_context)->toBe([
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
