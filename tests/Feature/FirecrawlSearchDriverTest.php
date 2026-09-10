<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlSearchDriver;
use Jkudish\LaravelAiLibrariumFirecrawl\Tests\Support\CreatesRequests;

uses(CreatesRequests::class);

it('sends the exact default Firecrawl Search request with the profile credential', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest());

    expect($result->content)->toBe('No results found.')
        ->and($result->provider)->toBe('firecrawl-search')
        ->and($result->profile)->toBe('firecrawl-search');
    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.firecrawl.test/v2/search'
            && $request->hasHeader('Authorization', 'Bearer fc-test-key')
            && $request->data() === [
                'query' => 'What is new?',
                'limit' => 10,
                'sources' => ['web'],
            ];
    });
    Http::assertSentCount(1);
});

it('sends every TypeScript candidate option without adding newer provider options', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

    app(FirecrawlSearchDriver::class)->run($this->searchRequest([
        'sources' => ['web', 'news'],
        'limit' => 7,
        'tbs' => 'sbd:1,qdr:w',
        'country' => 'ca',
        'location' => ' Toronto, Ontario, Canada ',
        'includeDomains' => ['Docs.Firecrawl.dev'],
        'categories' => ['github', 'research', 'pdf'],
        'ignoreInvalidURLs' => false,
    ], [Corpus::Web, Corpus::News]));

    Http::assertSent(fn ($request): bool => $request->data() === [
        'query' => 'What is new?',
        'limit' => 7,
        'sources' => ['web', 'news'],
        'tbs' => 'sbd:1,qdr:w',
        'country' => 'CA',
        'location' => 'Toronto, Ontario, Canada',
        'includeDomains' => ['docs.firecrawl.dev'],
        'categories' => [['type' => 'github'], ['type' => 'research'], ['type' => 'pdf']],
        'ignoreInvalidURLs' => false,
    ]);
});

it('normalizes asymmetric web and news fixtures in configured source order', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => [
            'web' => [
                [
                    'url' => 'https://www.example.com/path/?utm_source=web',
                    'title' => 'Later web duplicate',
                    'description' => 'Must lose because news is configured first.',
                ],
                ['url' => 'https://web.example/only', 'description' => "  Web-only\n detail. "],
                ['url' => 'http://insecure.example/result', 'title' => 'Unsafe'],
                ['url' => 'javascript:alert(1)', 'title' => 'Unsafe'],
                ['url' => 42, 'title' => 'Malformed'],
            ],
            'news' => [
                [
                    'url' => 'https://example.com/path/#today',
                    'title' => " First\n news duplicate ",
                    'snippet' => " Fresh\tnews summary ",
                    'date' => ' 2026-09-10 ',
                ],
                [
                    'url' => 'https://news.example/story',
                    'title' => 'Distinct news',
                    'snippet' => 'Second summary',
                    'date' => '2026-09-09',
                ],
            ],
        ],
        'creditsUsed' => 4,
    ])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest(
        ['sources' => ['news', 'web']],
        [Corpus::News, Corpus::Web],
    ));

    expect($result->content)->toBe(
        "## Web\n\n- **[Untitled](https://web.example/only)**\n  Web-only detail.\n\n".
        "## News\n\n- **[First news duplicate](https://example.com/path/#today)** (2026-09-10)\n  Fresh news summary\n".
        "- **[Distinct news](https://news.example/story)** (2026-09-09)\n  Second summary",
    )->and($result->citations->map(fn ($citation): string => (string) $citation->source->url)->all())->toBe([
        'https://example.com/path/#today',
        'https://news.example/story',
        'https://web.example/only',
    ])->and($result->citations[0]->source->kind->value)->toBe('news_article')
        ->and($result->citations[0]->excerpt)->toBe('Fresh news summary')
        ->and($result->citations[2]->source->kind->value)->toBe('web_page')
        ->and($result->providerMeta->result_count)->toBe(3)
        ->and($result->providerMeta->credits_used)->toBe(4);
});

it('escapes provider-controlled Markdown while retaining untrusted citation text', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => ['news' => [[
            'title' => 'Trusted](https://attacker.example) [Spoof',
            'url' => 'https://safe.example/story_(one)',
            'snippet' => 'Read [more](https://attacker.example) ~~spoofed~~ ftp://files.example user@example.test',
            'date' => '[today](https://attacker.example)',
        ]]],
    ])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest(
        ['sources' => ['news']],
        [Corpus::News],
    ));

    expect($result->content)
        ->toContain('https://safe.example/story_\(one\)')
        ->toContain('\\[more\\]')
        ->toContain('\\~\\~spoofed\\~\\~')
        ->toContain("ftp:\u{200B}//files.example")
        ->not->toContain('](https://attacker.example)')
        ->and($result->citations[0]->source->title)->toBe('Trusted](https://attacker.example) [Spoof')
        ->and($result->citations[0]->excerpt)->toBe('Read [more](https://attacker.example) ~~spoofed~~ ftp://files.example user@example.test');
});

it('keeps direct search provenance distinct from surface observation provenance', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest());

    expect($result->provenance->resultKind)->toBe(ResultKind::SearchResults)
        ->and($result->provenance->retrievalMethods->all())->toBe([RetrievalMethod::SearchEndpoint])
        ->and($result->provenance->corpora->all())->toBe([Corpus::Web])
        ->and($result->provenance->collector)->toBeNull()
        ->and($result->provenance->surface)->toBeNull()
        ->and($result->provenance->observationMode)->toBeNull()
        ->and($result->provider)->toBe('firecrawl-search')
        ->and($result->profile)->toBe('firecrawl-search');
});

it('retains only bounded integer provider-reported credit metering', function (mixed $reported, ?int $expected): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => [], 'creditsUsed' => $reported])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest());

    if ($expected === null) {
        expect($result->providerMeta)->not->toHaveProperty('credits_used');
    } else {
        expect($result->providerMeta->credits_used)->toBe($expected);
    }
    expect($result->usage)->toBeNull();
})->with([
    'zero is valid' => [0, 0],
    'positive integer' => [4, 4],
    'negative' => [-1, null],
    'fractional' => [1.5, null],
    'numeric string' => ['4', null],
    'above PHP-compatible bound' => [2_147_483_648, null],
]);

it('rejects invalid options before sending any request', function (array $options, array $corpora, string $message): void {
    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest($options, $corpora)))
        ->toThrow(DriverException::class, $message);
    Http::assertNothingSent();
})->with([
    'empty sources' => [['sources' => []], [Corpus::Web], 'sources'],
    'duplicate sources' => [['sources' => ['web', 'web']], [Corpus::Web], 'unique'],
    'unsupported source' => [['sources' => ['images']], [Corpus::Web], 'supported'],
    'limit above maximum' => [['limit' => 101], [Corpus::Web], 'limit'],
    'empty categories' => [['categories' => []], [Corpus::Web], 'categories'],
    'unsupported category' => [['categories' => ['developer']], [Corpus::Web], 'supported'],
    'unsupported tbs' => [['tbs' => 'soon'], [Corpus::Web], 'tbs'],
    'incomplete date range' => [['tbs' => 'cdr:1,cd_min:01/01/2026'], [Corpus::Web], 'custom ranges'],
    'impossible date' => [['tbs' => 'cdr:1,cd_min:02/30/2026,cd_max:03/01/2026'], [Corpus::Web], 'invalid date'],
    'reversed date range' => [['tbs' => 'cdr:1,cd_min:12/31/2026,cd_max:01/01/2026'], [Corpus::Web], 'must not follow'],
    'invalid country' => [['country' => 'Canada'], [Corpus::Web], 'country'],
    'invalid hostname' => [['includeDomains' => ['https://example.com']], [Corpus::Web], 'hostnames'],
    'duplicate hostname after normalization' => [['includeDomains' => ['Example.com', 'example.com']], [Corpus::Web], 'duplicate'],
    'conflicting domains' => [['includeDomains' => ['example.com'], 'excludeDomains' => ['other.example']], [Corpus::Web], 'mutually exclusive'],
    'non-boolean URL control' => [['ignoreInvalidURLs' => 1], [Corpus::Web], 'boolean'],
]);

it('rejects missing credentials and contradictory profile corpora before HTTP', function (): void {
    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest(credential: null)))
        ->toThrow(DriverException::class, 'not configured');
    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest(
        ['sources' => ['news']],
        [Corpus::Web],
    )))->toThrow(DriverException::class, 'incompatible raw-search semantics');
    Http::assertNothingSent();
});

it('redacts credentials and raw provider bodies from every failure', function (int $status, array|string $body, string $code): void {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest()))
        ->toThrow(function (DriverException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->getMessage())->not->toContain('fc-test-key')
                ->not->toContain('raw-secret')
                ->not->toContain('provider.example')
                ->and($exception->getPrevious())->toBeNull();
        });
})->with([
    'authentication' => [401, ['error' => 'raw-secret fc-test-key'], 'firecrawl-search.authentication'],
    'authorization' => [403, ['error' => 'raw-secret'], 'firecrawl-search.authorization'],
    'timeout' => [408, ['error' => 'raw-secret'], 'firecrawl-search.timeout'],
    'rate limit' => [429, ['error' => 'raw-secret'], 'firecrawl-search.rate_limited'],
    'server failure' => [500, 'raw-secret https://provider.example/body', 'firecrawl-search.unavailable'],
    'provider envelope' => [200, ['success' => false, 'error' => 'raw-secret fc-test-key'], 'firecrawl-search.provider'],
]);

it('rejects oversized and malformed successful responses without retaining raw bodies', function (array|string $body, string $message): void {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest()))
        ->toThrow(DriverException::class, $message);
})->with([
    'oversized' => [str_repeat('raw-secret', 120_000), 'oversized'],
    'non-object JSON' => [['not', 'an', 'object'], 'malformed'],
    'missing success' => [['data' => []], 'malformed'],
    'missing data' => [['success' => true], 'malformed'],
]);
