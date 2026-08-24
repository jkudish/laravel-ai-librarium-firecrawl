<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;

/**
 * Temporary prompt-only Interact transport.
 *
 * Firecrawl PHP SDK 1.13 always sends `code` and cannot represent the API's
 * prompt-only one-of request. Remove this class once the SDK exposes that call.
 */
final readonly class PromptInteractClient
{
    private const int MAX_RESPONSE_BYTES = 1_048_576;

    /** @return array<array-key, mixed> */
    public function interact(DriverRequest $request, string $scrapeId, string $prompt): array
    {
        $length = mb_strlen($prompt);
        if ($length < 1 || $length > 10_000) {
            throw new DriverException(
                'firecrawl.invalid_prompt',
                'The Firecrawl interaction prompt must contain between 1 and 10,000 characters.',
                false,
            );
        }

        $remaining = (int) floor(CarbonImmutable::now()->diffInSeconds($request->deadline, false));
        if ($remaining < 1) {
            throw $this->deadlineExceeded();
        }

        $baseUrl = config('firecrawl-librarium.api_url', 'https://api.firecrawl.dev');
        if (! is_string($baseUrl) || ! $this->validBaseUrl($baseUrl)) {
            throw new DriverException('firecrawl.configuration', 'The Firecrawl API URL is invalid.', false);
        }

        try {
            $response = Http::withToken((string) $request->profile->credential)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(3, $remaining))
                ->timeout(min(120, $remaining))
                ->withOptions(['allow_redirects' => false])
                ->post(rtrim($baseUrl, '/').'/v2/scrape/'.rawurlencode($scrapeId).'/interact', [
                    'prompt' => $prompt,
                    'timeout' => min(120, $remaining),
                ]);
        } catch (ConnectionException) {
            throw CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)
                ? $this->deadlineExceeded()
                : new DriverException('firecrawl.connection', 'Firecrawl could not be reached.');
        }

        $json = $this->json($response);
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }

        return $json;
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        if ($response->status() === 401) {
            throw new DriverException('firecrawl.authentication', 'Firecrawl rejected the configured credential.');
        }
        if ($response->status() === 403) {
            throw new DriverException('firecrawl.authorization', 'Firecrawl denied this interaction.');
        }
        if ($response->status() === 429) {
            throw new DriverException('firecrawl.rate_limited', 'Firecrawl rate limited this interaction.');
        }
        if ($response->serverError()) {
            throw new DriverException('firecrawl.unavailable', 'Firecrawl is temporarily unavailable.');
        }
        if ($response->failed()) {
            throw new DriverException('firecrawl.invalid_request', 'Firecrawl rejected this interaction.', false);
        }
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new DriverException('firecrawl.invalid_response', 'Firecrawl returned an oversized interaction response.');
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json) || ($json['success'] ?? null) !== true) {
            throw new DriverException('firecrawl.invalid_response', 'Firecrawl returned a malformed interaction response.');
        }

        $output = $json['output'] ?? null;
        if (! is_string($output) || trim($output) === '' || strlen($output) > 131_072) {
            throw new DriverException('firecrawl.invalid_response', 'Firecrawl returned no bounded interaction output.');
        }

        return ['output' => $output];
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException('firecrawl.deadline_exceeded', 'Firecrawl did not complete before the research deadline.');
    }

    private function validBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return $host === 'api.firecrawl.dev' || config('firecrawl-librarium.allow_custom_api_url') === true;
    }
}
