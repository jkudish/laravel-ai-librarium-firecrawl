<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
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
                ['url' => 'https://user:pass@private.example/result', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?X-Goog-Signature=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?apiKey=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?AWSAccessKeyId=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?redirect%5Btoken%5D=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result#access_token=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?redirect=https%3A%2F%2Fprivate.example%2F%3Ftoken%3Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?%2561%2570%2569%254b%2565%2579=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?api%2525254Bey=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/?url=https%3A%2F%2Fa.example%2F%3Furl%3Dhttps%253A%252F%252Fb.example%252F%253Furl%253Dhttps%25253A%25252F%25252Fc.example%25252F%25253Furl%25253Dhttps%2525253A%2525252F%2525252Fd.example%2525252F%2525253Ftoken%2525253Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/redirect/https%3A%2F%2Fprivate.example%2F%3Ftoken%3Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?redirect=+https%3A%2F%2Fprivate.example%2F%3Ftoken%3Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?redirect=%2F%2Fprivate.example%2F%3FapiKey%3Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?redirect=%2Fpath%3Ftoken%3Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?redirect=token%3Dsigned-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?password=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?authorization=signed-secret', 'title' => 'Unsafe'],
                ['url' => 'https://signed.example/result?session_id=signed-secret', 'title' => 'Unsafe'],
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

    expect(json_encode($result->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('private.example')
        ->not->toContain('signed.example')
        ->not->toContain('signed-secret');
});

it('rejects decoded nested userinfo and credential wrapper keys without dropping benign controls', function (string $unsafe, string $benign): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => ['web' => [
            ['url' => $unsafe, 'title' => 'Unsafe'],
            ['url' => $benign, 'title' => 'Benign'],
        ]],
    ])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest());

    expect($result->citations->pluck('source.url')->all())->toBe([$benign])
        ->and($result->content)->toContain('Benign')
        ->not->toContain('Unsafe');
})->with([
    'nested percent-decoded URL userinfo' => [
        'https://search.example/result?redirect=https%253A%252F%252Fuser%253Apass%2540private.example%252Fresult',
        'https://search.example/result?redirect=https%253A%252F%252Fpublic.example%252Fresult%253Fpage%253D2',
    ],
    'accessKey' => [
        'https://search.example/result?accessKey=secret',
        'https://search.example/result?accessLevel=public',
    ],
    'tokenValue' => [
        'https://search.example/result?tokenValue=secret',
        'https://search.example/result?tokenizerValue=words',
    ],
    'vendorSessionTokenValue' => [
        'https://search.example/result?vendorSessionTokenValue=secret',
        'https://search.example/result?vendorSessionTimeoutValue=30',
    ],
]);

it('canonicalizes path slashes without collapsing distinct nontracking query values ending in slashes', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => ['web' => [
            ['url' => 'https://example.com/item/?variant=one/', 'title' => 'Slash-valued query wins'],
            ['url' => 'https://www.example.com/item?variant=one/&utm_source=duplicate', 'title' => 'Tracking duplicate'],
            ['url' => 'https://example.com/item?variant=one', 'title' => 'Distinct query value'],
        ]],
    ])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest());

    expect($result->citations->pluck('source.url')->all())->toBe([
        'https://example.com/item/?variant=one/',
        'https://example.com/item?variant=one',
    ])->and($result->content)->toContain('Slash-valued query wins')
        ->toContain('Distinct query value')
        ->not->toContain('Tracking duplicate');
});

it('caps retained results to the requested per-source limit and bounds rendered provider text', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => ['web' => [
            [
                'url' => 'https://one.example/result',
                'title' => str_repeat('T', 600),
                'description' => str_repeat('S', 6000),
            ],
            ['url' => 'https://two.example/result', 'title' => 'Second'],
            ['url' => 'https://three.example/result', 'title' => 'Must not be retained'],
        ]],
    ])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest(['limit' => 2]));

    expect($result->citations)->toHaveCount(2)
        ->and($result->providerMeta->result_count)->toBe(2)
        ->and($result->citations[0]->source->title)->toHaveLength(500)
        ->and($result->citations[0]->excerpt)->toHaveLength(200)
        ->and($result->content)->toContain(str_repeat('S', 5000))
        ->and($result->content)->not->toContain(str_repeat('S', 5001))
        ->and($result->content)->not->toContain('Must not be retained');
});

it('applies the documented result limit independently to every configured source', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data' => [
            'web' => [
                ['url' => 'https://web.example/one', 'title' => 'Web one'],
                ['url' => 'https://web.example/two', 'title' => 'Web two'],
                ['url' => 'https://web.example/three', 'title' => 'Web three'],
            ],
            'news' => [
                ['url' => 'https://news.example/one', 'title' => 'News one'],
                ['url' => 'https://news.example/two', 'title' => 'News two'],
                ['url' => 'https://news.example/three', 'title' => 'News three'],
            ],
        ],
    ])]);

    $result = app(FirecrawlSearchDriver::class)->run($this->searchRequest(
        ['sources' => ['web', 'news'], 'limit' => 2],
        [Corpus::Web, Corpus::News],
    ));

    expect($result->citations->pluck('source.url')->all())->toBe([
        'https://web.example/one',
        'https://web.example/two',
        'https://news.example/one',
        'https://news.example/two',
    ])->and($result->providerMeta->result_count)->toBe(4)
        ->and($result->content)->not->toContain('Web three')
        ->and($result->content)->not->toContain('News three');
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
    'orphan custom range marker' => [['tbs' => 'cdr:1'], [Corpus::Web], 'custom ranges'],
    'incomplete date range' => [['tbs' => 'cdr:1,cd_min:01/01/2026'], [Corpus::Web], 'custom ranges'],
    'orphan minimum date' => [['tbs' => 'cd_min:01/01/2026'], [Corpus::Web], 'custom ranges'],
    'orphan maximum date' => [['tbs' => 'cd_max:01/31/2026'], [Corpus::Web], 'custom ranges'],
    'orphan date pair' => [['tbs' => 'cd_min:01/01/2026,cd_max:01/31/2026'], [Corpus::Web], 'custom ranges'],
    'differing duplicate relative ranges' => [['tbs' => 'qdr:d,qdr:w'], [Corpus::Web], 'unsupported format'],
    'differing duplicate minimum dates' => [['tbs' => 'cdr:1,cd_min:01/01/2026,cd_min:02/30/2026,cd_max:01/31/2026'], [Corpus::Web], 'unsupported format'],
    'differing duplicate maximum dates' => [['tbs' => 'cdr:1,cd_min:01/01/2026,cd_max:01/31/2026,cd_max:02/30/2026'], [Corpus::Web], 'unsupported format'],
    'impossible date' => [['tbs' => 'cdr:1,cd_min:02/30/2026,cd_max:03/01/2026'], [Corpus::Web], 'invalid date'],
    'reversed date range' => [['tbs' => 'cdr:1,cd_min:12/31/2026,cd_max:01/01/2026'], [Corpus::Web], 'must not follow'],
    'invalid country' => [['country' => 'Canada'], [Corpus::Web], 'country'],
    'invalid hostname' => [['includeDomains' => ['https://example.com']], [Corpus::Web], 'hostnames'],
    'duplicate hostname after normalization' => [['includeDomains' => ['Example.com', 'example.com']], [Corpus::Web], 'duplicate'],
    'conflicting domains' => [['includeDomains' => ['example.com'], 'excludeDomains' => ['other.example']], [Corpus::Web], 'mutually exclusive'],
    'non-boolean URL control' => [['ignoreInvalidURLs' => 1], [Corpus::Web], 'boolean'],
]);

it('accepts and forwards a complete valid custom date range', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

    app(FirecrawlSearchDriver::class)->run($this->searchRequest([
        'tbs' => 'sbd:1,cdr:1,cd_min:01/01/2026,cd_max:01/31/2026',
    ]));

    Http::assertSent(fn ($request): bool => $request->data()['tbs'] === 'sbd:1,cdr:1,cd_min:01/01/2026,cd_max:01/31/2026');
});

it('rejects missing credentials and contradictory profile corpora before HTTP', function (): void {
    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest(credential: null)))
        ->toThrow(DriverException::class, 'not configured');
    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest(
        ['sources' => ['news']],
        [Corpus::Web],
    )))->toThrow(DriverException::class, 'incompatible raw-search semantics');
    Http::assertNothingSent();
});

it('rejects malformed credentials before constructing an HTTP request', function (string $credential): void {
    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest(credential: $credential)))
        ->toThrow(DriverException::class, 'malformed');
    Http::assertNothingSent();
})->with([
    'control character' => ["fc-key\r\ninjected: value"],
    'space' => ['fc key'],
    'non-ASCII' => ['fc-key-é'],
    'oversized' => [str_repeat('k', 4097)],
]);

it('rejects custom API URLs that are not a clean HTTPS authority', function (string $url): void {
    config()->set('firecrawl-librarium.api_url', $url);
    config()->set('firecrawl-librarium.allow_custom_api_url', true);

    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest()))
        ->toThrow(DriverException::class, 'API URL');
    Http::assertNothingSent();
})->with([
    'userinfo' => ['https://user:pass@api.firecrawl.test'],
    'path' => ['https://api.firecrawl.test/base'],
    'query' => ['https://api.firecrawl.test?token=secret'],
    'fragment' => ['https://api.firecrawl.test#fragment'],
    'HTTP' => ['http://api.firecrawl.test'],
]);

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

it('preserves the safe response-size failure through Laravel transport wrapping', function (): void {
    $guardFailure = new DriverException('firecrawl-search.invalid_response', 'Firecrawl Search returned an oversized response.');
    $guzzleFailure = new GuzzleRequestException(
        'provider body details',
        new GuzzleRequest('POST', 'https://api.firecrawl.test/v2/search'),
        null,
        $guardFailure,
    );
    Http::fake(static fn () => throw new ConnectionException('transport details', 0, $guzzleFailure));

    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl-search.invalid_response')
                ->and($exception->getMessage())->toBe('Firecrawl Search returned an oversized response.')
                ->and($exception->getMessage())->not->toContain('provider body details')
                ->not->toContain('fc-test-key');
        });
});

it('accepts only the documented HTTP 200 success status', function (int $status): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => []], $status)]);

    expect(fn () => app(FirecrawlSearchDriver::class)->run($this->searchRequest()))
        ->toThrow(function (DriverException $exception): void {
            expect($exception->errorCode)->toBe('firecrawl-search.invalid_response')
                ->and($exception->getMessage())->not->toContain('fc-test-key');
        });
})->with([
    'created' => [201],
    'no content' => [204],
    'redirect' => [302],
]);
