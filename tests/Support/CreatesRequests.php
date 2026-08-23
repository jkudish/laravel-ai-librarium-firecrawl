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
     */
    private function request(
        array $options = [],
        ?Closure $progress = null,
        int $deadlineSeconds = 300,
        GroundingPolicy $grounding = GroundingPolicy::Optional,
        array $corpora = [Corpus::Web],
    ): DriverRequest {
        $profile = new Profile(
            id: 'firecrawl-surface',
            driver: FirecrawlDriver::class,
            provider: 'firecrawl',
            model: null,
            resultKind: ResultKind::GroundedAnswer,
            grounding: $grounding,
            observation: ObservationMode::SurfaceSnapshot,
            corpora: collect($corpora),
            retrievalMethods: collect([RetrievalMethod::ResearchAgent]),
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
