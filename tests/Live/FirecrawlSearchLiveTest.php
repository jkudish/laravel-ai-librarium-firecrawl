<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchError;

it('completes one paid raw Firecrawl Search request', function (): void {
    if (getenv('LIBRARIUM_LIVE_TESTS') !== '1'
        || getenv('FIRECRAWL_SEARCH_LIVE_CREDIT_ACK') !== 'acknowledge-one-search-request-up-to-3-results'
        || trim((string) getenv('FIRECRAWL_API_KEY')) === '') {
        throw new RuntimeException('The live Firecrawl Search canary requires explicit test and credit acknowledgement.');
    }

    $profile = config('firecrawl-librarium.search_profile');
    expect($profile)->toBeArray();
    $profile['credential'] = (string) getenv('FIRECRAWL_API_KEY');
    $profile['options'] = ['sources' => ['web'], 'limit' => 3];
    config()->set('librarium.profiles.firecrawl-search-live', $profile);
    config()->set('firecrawl-librarium.api_url', 'https://api.firecrawl.dev');

    Http::allowStrayRequests(['https://api.firecrawl.dev/v2/search'])->record();

    $response = Librarium::query('Firecrawl Search API documentation')
        ->using('firecrawl-search-live')
        ->timeout(120)
        ->run();

    $errors = $response->errors
        ->map(static fn (ResearchError $error): string => $error->code.': '.$error->message)
        ->implode('; ');

    expect($response->status)->toBe(ResponseStatus::Succeeded, $errors)
        ->and($response->results)->toHaveCount(1)
        ->and($response->results->sole()->providerMeta->result_count)->toBeLessThanOrEqual(3);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.firecrawl.dev/v2/search'
        && $request->data() === [
            'query' => 'Firecrawl Search API documentation',
            'limit' => 3,
            'sources' => ['web'],
        ]);
});
