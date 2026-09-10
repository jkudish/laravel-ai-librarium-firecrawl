<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/**
 * Direct Firecrawl v2 Search transport.
 *
 * The official SDK currently discards the Search response envelope, including
 * creditsUsed, so this adapter-owned seam retains only the bounded documented
 * fields needed for exact raw-search semantics.
 */
final readonly class FirecrawlSearchDriver implements Driver
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int MAX_RESPONSE_BYTES = 1_048_576;

    private const int REQUEST_TIMEOUT_SECONDS = 120;

    public function __construct(private FirecrawlSearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'sources' => ['sometimes', 'array', 'list', 'min:1'],
            'sources.*' => ['string', 'in:web,news', 'distinct:strict'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'tbs' => ['sometimes', 'string', 'min:1'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'location' => ['sometimes', 'string', 'min:1'],
            'includeDomains' => ['sometimes', 'array', 'list', 'min:1'],
            'includeDomains.*' => ['string', 'min:1'],
            'excludeDomains' => ['sometimes', 'array', 'list', 'min:1'],
            'excludeDomains.*' => ['string', 'min:1'],
            'categories' => ['sometimes', 'array', 'list', 'min:1'],
            'categories.*' => ['string', 'in:github,research,pdf', 'distinct:strict'],
            'ignoreInvalidURLs' => ['sometimes', 'boolean'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $options = $this->validatedOptions($request);
        $response = $this->send($request, $this->requestBody($request->prompt, $options));
        $json = $this->json($response);

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException('firecrawl-search.deadline_exceeded', 'Firecrawl Search did not respond before the research deadline.');
        }

        return $this->mapper->result($request, $json, $options['sources']);
    }

    /**
     * @param  array{sources: list<'web'|'news'>, limit: int, tbs?: string, country?: string, location?: string, includeDomains?: list<string>, excludeDomains?: list<string>, categories?: list<'github'|'research'|'pdf'>, ignoreInvalidURLs?: bool}  $options
     * @return array<string, mixed>
     */
    private function requestBody(string $query, array $options): array
    {
        return [
            'query' => $query,
            'limit' => $options['limit'],
            'sources' => $options['sources'],
            ...array_filter([
                'tbs' => $options['tbs'] ?? null,
                'country' => $options['country'] ?? null,
                'location' => $options['location'] ?? null,
                'includeDomains' => $options['includeDomains'] ?? null,
                'excludeDomains' => $options['excludeDomains'] ?? null,
                'categories' => isset($options['categories'])
                    ? array_map(static fn (string $type): array => ['type' => $type], $options['categories'])
                    : null,
                'ignoreInvalidURLs' => $options['ignoreInvalidURLs'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /** @param array<string, mixed> $body */
    private function send(DriverRequest $request, array $body): Response
    {
        $remaining = (int) floor(CarbonImmutable::now()->diffInSeconds($request->deadline, false));
        if ($remaining < 1) {
            throw new DriverException('firecrawl-search.deadline_exceeded', 'The Firecrawl Search request could not start before the research deadline.');
        }

        $baseUrl = config('firecrawl-librarium.api_url', 'https://api.firecrawl.dev');
        if (! is_string($baseUrl) || ! $this->validBaseUrl($baseUrl)) {
            throw new DriverException('firecrawl-search.configuration', 'The Firecrawl API URL is invalid.', false);
        }

        try {
            return Http::withToken((string) $request->profile->credential)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->withOptions(['allow_redirects' => false])
                ->post(rtrim($baseUrl, '/').'/v2/search', $body);
        } catch (ConnectionException) {
            throw CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)
                ? new DriverException('firecrawl-search.deadline_exceeded', 'Firecrawl Search did not respond before the research deadline.')
                : new DriverException('firecrawl-search.connection', 'Firecrawl Search could not be reached.');
        }
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        if ($response->status() === 401) {
            throw new DriverException('firecrawl-search.authentication', 'Firecrawl Search rejected the configured credential.');
        }
        if ($response->status() === 403) {
            throw new DriverException('firecrawl-search.authorization', 'Firecrawl Search denied this request.');
        }
        if ($response->status() === 408) {
            throw new DriverException('firecrawl-search.timeout', 'Firecrawl Search timed out before completing this request.');
        }
        if ($response->status() === 429) {
            throw new DriverException('firecrawl-search.rate_limited', 'Firecrawl Search rate limited this request.');
        }
        if ($response->serverError()) {
            throw new DriverException('firecrawl-search.unavailable', 'Firecrawl Search is temporarily unavailable.');
        }
        if ($response->failed()) {
            throw new DriverException('firecrawl-search.invalid_request', 'Firecrawl Search rejected this request.', false);
        }
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new DriverException('firecrawl-search.invalid_response', 'Firecrawl Search returned an oversized response.');
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw new DriverException('firecrawl-search.invalid_response', 'Firecrawl Search returned a malformed response.');
        }
        if (($json['success'] ?? null) === false || $this->text($json['error'] ?? null) !== null) {
            throw new DriverException('firecrawl-search.provider', 'Firecrawl Search reported an error for this request.');
        }
        if (($json['success'] ?? null) !== true
            || ! is_array($json['data'] ?? null)
            || ($json['data'] !== [] && array_is_list($json['data']))) {
            throw new DriverException('firecrawl-search.invalid_response', 'Firecrawl Search returned a malformed response.');
        }

        return $json;
    }

    /**
     * @return array{sources: list<'web'|'news'>, limit: int, tbs?: string, country?: string, location?: string, includeDomains?: list<string>, excludeDomains?: list<string>, categories?: list<'github'|'research'|'pdf'>, ignoreInvalidURLs?: bool}
     */
    private function validatedOptions(DriverRequest $request): array
    {
        $options = $request->profile->options;
        $sources = $this->sources($options['sources'] ?? ['web']);
        $categories = array_key_exists('categories', $options)
            ? $this->categories($options['categories'])
            : null;
        $limit = $options['limit'] ?? 10;

        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            throw $this->invalidOptions('limit must be an integer between 1 and 100');
        }

        $validated = ['sources' => $sources, 'limit' => $limit];
        $tbs = $this->tbs($options['tbs'] ?? null);
        if ($tbs !== null) {
            $validated['tbs'] = $tbs;
        }
        if (array_key_exists('country', $options)) {
            $country = $this->text($options['country']);
            if ($country === null || preg_match('/^[a-z]{2}$/i', $country) !== 1) {
                throw $this->invalidOptions('country must be a two-letter country code');
            }
            $validated['country'] = strtoupper($country);
        }
        if (array_key_exists('location', $options)) {
            $location = $this->text($options['location']);
            if ($location === null) {
                throw $this->invalidOptions('location must be a nonempty string');
            }
            $validated['location'] = $location;
        }

        $include = array_key_exists('includeDomains', $options) ? $this->domains($options['includeDomains'], 'includeDomains') : null;
        $exclude = array_key_exists('excludeDomains', $options) ? $this->domains($options['excludeDomains'], 'excludeDomains') : null;
        if ($include !== null && $exclude !== null) {
            throw $this->invalidOptions('includeDomains and excludeDomains are mutually exclusive');
        }
        if ($include !== null) {
            $validated['includeDomains'] = $include;
        }
        if ($exclude !== null) {
            $validated['excludeDomains'] = $exclude;
        }
        if ($categories !== null) {
            $validated['categories'] = $categories;
        }
        if (array_key_exists('ignoreInvalidURLs', $options)) {
            if (! is_bool($options['ignoreInvalidURLs'])) {
                throw $this->invalidOptions('ignoreInvalidURLs must be a boolean');
            }
            $validated['ignoreInvalidURLs'] = $options['ignoreInvalidURLs'];
        }

        $expectedCorpora = array_map(static fn (string $source): Corpus => match ($source) {
            'web' => Corpus::Web,
            'news' => Corpus::News,
        }, $sources);
        if ($request->profile->provider !== 'firecrawl-search'
            || $request->profile->resultKind !== ResultKind::SearchResults
            || $request->profile->grounding !== GroundingPolicy::None
            || $request->profile->observation !== ObservationMode::ApiOutput
            || $request->profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
            || $request->profile->corpora->values()->all() !== $expectedCorpora) {
            throw new DriverException('firecrawl-search.invalid_profile', 'The Firecrawl Search Profile has incompatible raw-search semantics.', false);
        }
        if (blank($request->profile->credential)) {
            throw new DriverException('firecrawl-search.authentication', 'Firecrawl Search is not configured.');
        }

        return $validated;
    }

    /** @return list<'web'|'news'> */
    private function sources(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw $this->invalidOptions('sources must be a nonempty array');
        }

        $sources = [];
        foreach ($value as $item) {
            if ($item !== 'web' && $item !== 'news') {
                throw $this->invalidOptions('sources must contain unique supported values');
            }
            $sources[] = $item;
        }
        if (count(array_unique($sources, SORT_STRING)) !== count($sources)) {
            throw $this->invalidOptions('sources must contain unique supported values');
        }

        return $sources;
    }

    /** @return list<'github'|'research'|'pdf'> */
    private function categories(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw $this->invalidOptions('categories must be a nonempty array');
        }

        $categories = [];
        foreach ($value as $item) {
            if ($item !== 'github' && $item !== 'research' && $item !== 'pdf') {
                throw $this->invalidOptions('categories must contain unique supported values');
            }
            $categories[] = $item;
        }
        if (count(array_unique($categories, SORT_STRING)) !== count($categories)) {
            throw $this->invalidOptions('categories must contain unique supported values');
        }

        return $categories;
    }

    /** @return list<string> */
    private function domains(mixed $value, string $name): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw $this->invalidOptions("{$name} must be a nonempty array of hostnames");
        }

        $domains = [];
        foreach ($value as $item) {
            $domain = is_string($item) ? strtolower(trim($item)) : '';
            if (! $this->hostname($domain)) {
                throw $this->invalidOptions("{$name} must contain only nonempty hostnames");
            }
            $domains[] = $domain;
        }
        if (count(array_unique($domains, SORT_STRING)) !== count($domains)) {
            throw $this->invalidOptions("{$name} must not contain duplicate hostnames");
        }

        return $domains;
    }

    private function tbs(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $tbs = $this->text($value);
        if ($tbs === null) {
            throw $this->invalidOptions('tbs must be a nonempty string');
        }

        $parts = explode(',', $tbs);
        foreach ($parts as $part) {
            if (preg_match('/^qdr:[hdwmy]$/', $part) !== 1
                && $part !== 'sbd:1'
                && $part !== 'cdr:1'
                && preg_match('/^cd_(?:min|max):\d{2}\/\d{2}\/\d{4}$/', $part) !== 1) {
                throw $this->invalidOptions('tbs has an unsupported format');
            }
        }
        if (count(array_unique($parts, SORT_STRING)) !== count($parts)) {
            throw $this->invalidOptions('tbs has an unsupported format');
        }

        $custom = in_array('cdr:1', $parts, true);
        $min = $this->tbsPart($parts, 'cd_min:');
        $max = $this->tbsPart($parts, 'cd_max:');
        if ($custom !== ($min !== null && $max !== null)) {
            throw $this->invalidOptions('tbs custom ranges require cdr:1, cd_min, and cd_max');
        }
        $minDate = $min === null ? null : $this->usDate(substr($min, 7));
        $maxDate = $max === null ? null : $this->usDate(substr($max, 7));
        if (($min !== null && $minDate === null) || ($max !== null && $maxDate === null)) {
            throw $this->invalidOptions('tbs contains an invalid date');
        }
        if ($minDate !== null && $maxDate !== null && $minDate > $maxDate) {
            throw $this->invalidOptions('tbs custom range start must not follow its end');
        }

        return $tbs;
    }

    /** @param list<string> $parts */
    private function tbsPart(array $parts, string $prefix): ?string
    {
        foreach ($parts as $part) {
            if (str_starts_with($part, $prefix)) {
                return $part;
            }
        }

        return null;
    }

    private function usDate(string $value): ?int
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) !== 1) {
            return null;
        }
        $month = (int) $matches[1];
        $day = (int) $matches[2];
        $year = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $timestamp = gmmktime(0, 0, 0, $month, $day, $year);

        return $timestamp === false ? null : $timestamp;
    }

    private function hostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253 || str_contains($value, '..')) {
            return false;
        }

        foreach (explode('.', $value) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    private function invalidOptions(string $detail): DriverException
    {
        return new DriverException('firecrawl-search.invalid_options', "The Firecrawl Search option {$detail}.", false);
    }

    private function validBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host)
            && $host !== ''
            && ($host === 'api.firecrawl.dev' || config('firecrawl-librarium.allow_custom_api_url') === true);
    }
}
