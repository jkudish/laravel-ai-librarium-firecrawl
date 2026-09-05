<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support;

use Carbon\CarbonImmutable;
use Closure;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlDriver;

trait CreatesRequests
{
    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(string, string): void|null  $progress
     * @param  list<Corpus>  $corpora
     * @param  list<RetrievalMethod>  $retrievalMethods
     */
    private function request(
        array $options = [],
        ?Closure $progress = null,
        int $deadlineSeconds = 300,
        GroundingPolicy $grounding = GroundingPolicy::Optional,
        array $corpora = [Corpus::Web],
        ResultKind $resultKind = ResultKind::SurfaceObservation,
        ObservationMode $observation = ObservationMode::SurfaceSnapshot,
        array $retrievalMethods = [RetrievalMethod::SurfaceCollector],
    ): DriverRequest {
        $profile = new Profile(
            id: 'firecrawl-surface',
            driver: FirecrawlDriver::class,
            provider: 'firecrawl',
            model: null,
            resultKind: $resultKind,
            grounding: $grounding,
            observation: $observation,
            corpora: collect($corpora),
            retrievalMethods: collect($retrievalMethods),
            prompt: '{{ query }}',
            enabled: true,
            options: [
                'mode' => 'interact',
                'target_url' => 'https://example.com/ai',
                'surface' => 'example-ai',
                'authentication' => 'anonymous',
                ...$options,
            ],
            credential: 'fc-test-key',
        );

        return new DriverRequest(
            requestId: 'request-1',
            requestedProfile: $profile,
            profile: $profile,
            prompt: 'What is new?',
            deadline: CarbonImmutable::now()->addSeconds($deadlineSeconds),
            progressCallback: $progress ?? static function (): void {},
        );
    }
}
