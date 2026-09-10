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

    /**
     * @param  array<array-key, mixed>  $json
     * @param  list<'web'|'news'>  $sources
     */
    public function result(DriverRequest $request, array $json, array $sources): ResearchResult
    {
        $data = $json['data'] ?? null;
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new DriverException('firecrawl-search.invalid_response', 'Firecrawl Search returned a malformed response.');
        }

        $results = $this->normalize($data, $sources);
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
    private function normalize(array $data, array $sources): array
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
            'title' => $this->text($entry['title'] ?? null),
            'snippet' => $this->text($entry[$kind === 'web' ? 'description' : 'snippet'] ?? null),
            'date' => $kind === 'news' ? $this->text($entry['date'] ?? null) : null,
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
        $url = $this->text($value);
        if ($url === null) {
            return null;
        }

        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            return null;
        }
        if (strtolower($uri->getScheme()) !== 'https' || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
            return null;
        }

        if ($this->hasCredentialQueryKey($uri->getQuery())) {
            return null;
        }

        if ($uri->getPath() === '') {
            $uri = $uri->withPath('/');
        }

        return (string) $uri;
    }

    private function hasCredentialQueryKey(string $query): bool
    {
        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            [$rawKey] = explode('=', $parameter, 2);
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $segments = preg_split('/[^a-z0-9]+/i', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $candidates = [...$segments, implode('', $segments)];

            foreach ($candidates as $candidate) {
                $normalized = strtolower($candidate);
                if (in_array($normalized, ['key', 'sig'], true)
                    || preg_match('/(?:signature|credential|token|secret|api(?:access)?key|accesskeyid)$/', $normalized) === 1) {
                    return true;
                }
            }
        }

        return false;
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

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    private function credits(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_CREDITS_USED ? $value : null;
    }
}
