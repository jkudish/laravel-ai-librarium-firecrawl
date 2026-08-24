<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Carbon\CarbonImmutable;
use Firecrawl\Client\FirecrawlClient;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;

final readonly class FirecrawlClientFactory implements CreatesFirecrawlClient
{
    public function forRequest(DriverRequest $request): FirecrawlClient
    {
        $remaining = (int) floor(CarbonImmutable::now()->diffInSeconds($request->deadline, false));
        $apiUrl = config('firecrawl-librarium.api_url', 'https://api.firecrawl.dev');
        if ($remaining < 1) {
            throw new DriverException('firecrawl.deadline_exceeded', 'Firecrawl could not start before the research deadline.');
        }
        if (! is_string($apiUrl) || filter_var($apiUrl, FILTER_VALIDATE_URL) === false
            || parse_url($apiUrl, PHP_URL_SCHEME) !== 'https'
            || (! $this->officialHost($apiUrl) && config('firecrawl-librarium.allow_custom_api_url') !== true)) {
            throw new DriverException('firecrawl.configuration', 'The Firecrawl API URL is invalid.', false);
        }

        return FirecrawlClient::create(
            apiKey: (string) $request->profile->credential,
            apiUrl: $apiUrl,
            timeoutSeconds: min(120, $remaining),
            maxRetries: 0,
        );
    }

    private function officialHost(string $url): bool
    {
        return parse_url($url, PHP_URL_HOST) === 'api.firecrawl.dev';
    }
}
