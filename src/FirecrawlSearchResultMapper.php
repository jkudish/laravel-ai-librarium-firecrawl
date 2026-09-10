<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Throwable;

/** @phpstan-type NormalizedResult array{kind: 'web'|'news', url: string, title?: string, snippet?: string, date?: string} */
final readonly class FirecrawlSearchResultMapper
{
    private const int MAX_CREDITS_USED = 2_147_483_647;

    private const int MAX_DATE_CHARS = 100;

    private const int MAX_DECODE_DEPTH = 3;

    private const int MAX_NESTED_URL_DEPTH = 3;

    private const int MAX_SNIPPET_CHARS = 5000;

    private const int MAX_TITLE_CHARS = 500;

    private const int MAX_URL_BYTES = 8192;

    /**
     * @param  array<array-key, mixed>  $json
     * @param  list<'web'|'news'>  $sources
     */
    public function result(DriverRequest $request, array $json, array $sources, int $limit): ResearchResult
    {
        $data = $json['data'] ?? null;
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new DriverException('firecrawl-search.invalid_response', 'Firecrawl Search returned a malformed response.');
        }

        $results = $this->normalize($data, $sources, $limit);
        /** @var Collection<int, Citation> $citations */
        $citations = collect($results)->map(fn (array $result): Citation => Citation::make(
            derivation: CitationDerivation::ProviderReported,
            source: new Source(
                kind: $result['kind'] === 'news' ? SourceKind::NewsArticle : SourceKind::WebPage,
                url: $result['url'],
                title: $result['title'] ?? null,
            ),
            excerpt: isset($result['snippet']) ? mb_substr($result['snippet'], 0, 200) : null,
        ));
        $now = CarbonImmutable::now();
        $credits = $this->credits($json['creditsUsed'] ?? null);

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $this->content($results),
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: $citations,
            completedAt: $now,
            providerMeta: (object) array_filter([
                'result_count' => count($results),
                'credits_used' => $credits,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<'web'|'news'>  $sources
     * @return list<NormalizedResult>
     */
    private function normalize(array $data, array $sources, int $limit): array
    {
        $results = [];
        $seen = [];

        foreach ($sources as $source) {
            $entries = $data[$source] ?? null;
            if (! is_array($entries) || ! array_is_list($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                $result = $this->normalizeResult($source, $entry);
                if ($result === null) {
                    continue;
                }
                $key = $this->dedupeKey($result['url']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $results[] = $result;
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return $results;
    }

    /** @return NormalizedResult|null */
    private function normalizeResult(string $kind, mixed $entry): ?array
    {
        if (! is_array($entry) || array_is_list($entry)) {
            return null;
        }
        $url = $this->httpsUrl($entry['url'] ?? null);
        if ($url === null) {
            return null;
        }

        $result = array_filter([
            'kind' => $kind,
            'url' => $url,
            'title' => $this->text($entry['title'] ?? null, self::MAX_TITLE_CHARS),
            'snippet' => $this->text($entry[$kind === 'web' ? 'description' : 'snippet'] ?? null, self::MAX_SNIPPET_CHARS),
            'date' => $kind === 'news' ? $this->text($entry['date'] ?? null, self::MAX_DATE_CHARS) : null,
        ], static fn (mixed $value): bool => $value !== null);

        /** @var NormalizedResult $result */
        return $result;
    }

    /** @param list<NormalizedResult> $results */
    private function content(array $results): string
    {
        if ($results === []) {
            return 'No results found.';
        }

        $sections = [];
        foreach (['web' => 'Web', 'news' => 'News'] as $kind => $heading) {
            $lines = [];
            foreach ($results as $result) {
                if ($result['kind'] !== $kind) {
                    continue;
                }
                $title = $this->markdownText($result['title'] ?? 'Untitled');
                $date = $kind === 'news' && isset($result['date']) ? ' ('.$this->markdownText($result['date']).')' : '';
                $lines[] = '- **['.$title.']('.$this->markdownUrl($result['url']).')**'.$date;
                if (isset($result['snippet'])) {
                    $lines[] = '  '.$this->markdownText($result['snippet']);
                }
            }
            if ($lines !== []) {
                $sections[] = '## '.$heading."\n\n".implode("\n", $lines);
            }
        }

        return implode("\n\n", $sections);
    }

    private function httpsUrl(mixed $value): ?string
    {
        return $this->httpsUrlAtDepth($value, 0);
    }

    private function httpsUrlAtDepth(mixed $value, int $depth): ?string
    {
        if (! is_string($value)
            || trim($value) !== $value
            || $value === ''
            || strlen($value) > self::MAX_URL_BYTES) {
            return null;
        }

        try {
            $uri = new Uri($value);
        } catch (Throwable) {
            return null;
        }
        if (strtolower($uri->getScheme()) !== 'https' || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
            return null;
        }

        foreach ([$uri->getQuery(), $uri->getFragment()] as $parameters) {
            if ($this->hasCredentialParameter($parameters, $depth)) {
                return null;
            }
        }

        if ($uri->getPath() === '') {
            $uri = $uri->withPath('/');
        }

        return (string) $uri;
    }

    private function hasCredentialParameter(string $parameters, int $depth): bool
    {
        foreach (preg_split('/[&;]/', $parameters) ?: [] as $parameter) {
            [$rawKey, $rawValue] = array_pad(explode('=', $parameter, 2), 2, '');
            if ($this->isCredentialKey($rawKey)) {
                return true;
            }

            if ($depth >= self::MAX_NESTED_URL_DEPTH || $rawValue === '') {
                continue;
            }

            $nested = $this->decode($rawValue);
            if (preg_match('#^https?://#i', $nested) === 1
                && $this->httpsUrlAtDepth($nested, $depth + 1) === null) {
                return true;
            }
        }

        return false;
    }

    private function isCredentialKey(string $rawKey): bool
    {
        $key = $this->decode($rawKey);
        $segments = preg_split('/[^a-z0-9]+/i', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ([...$segments, implode('', $segments)] as $candidate) {
            $normalized = strtolower($candidate);
            if (in_array($normalized, ['key', 'sig'], true)
                || preg_match('/(?:signature|credential|token|secret|api(?:access)?key|accesskeyid)$/D', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function decode(string $value): string
    {
        $decoded = str_replace('+', ' ', $value);

        for ($attempt = 0; $attempt < self::MAX_DECODE_DEPTH; $attempt++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return $decoded;
    }

    private function dedupeKey(string $url): string
    {
        $uri = (new Uri($url))->withFragment('');
        $host = strtolower($uri->getHost());
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $retained = [];
        foreach (explode('&', $uri->getQuery()) as $parameter) {
            if ($parameter === '') {
                continue;
            }
            [$key] = explode('=', $parameter, 2);
            $key = strtolower(rawurldecode($key));
            if (str_starts_with($key, 'utm_') || in_array($key, ['ref', 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'], true)) {
                continue;
            }
            $retained[] = $parameter;
        }

        $port = $uri->getPort();
        $normalized = $host.($port === null ? '' : ':'.$port).$uri->getPath();
        if ($retained !== []) {
            $normalized .= '?'.implode('&', $retained);
        }

        return rtrim($normalized, '/');
    }

    private function markdownText(string $value): string
    {
        $value = preg_replace('/([\\\\`*_\[\]{}<>#+!|~])/u', '\\\\$1', $value) ?? $value;
        $value = preg_replace('/^([+-])(?=\s)/u', '\\\\$1', $value) ?? $value;
        $value = preg_replace('/^(\d+)\.(?=\s)/u', '$1\\\\.', $value) ?? $value;
        $value = preg_replace('/\b(https?|ftp):\/\//iu', '$1:'."\u{200B}".'//', $value) ?? $value;
        $value = preg_replace('/\bwww\./iu', 'www'."\u{200B}".'.', $value) ?? $value;

        return str_replace('@', '@'."\u{200B}", $value);
    }

    private function markdownUrl(string $url): string
    {
        return str_replace(['\\', '(', ')', '<', '>'], ['\\\\', '\\(', '\\)', '\\<', '\\>'], $url);
    }

    private function text(mixed $value, int $maxChars = self::MAX_SNIPPET_CHARS): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) && $normalized !== '' ? mb_substr($normalized, 0, $maxChars) : null;
    }

    private function credits(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_CREDITS_USED ? $value : null;
    }
}
