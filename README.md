# Laravel AI Librarium: Firecrawl

Optional Firecrawl search and surface-collection adapter for
`jkudish/laravel-ai-librarium`. Firecrawl and its SDK stay outside the core
package.

## Install

Requires PHP 8.4+ and Laravel 13.

```bash
composer require jkudish/laravel-ai-librarium-firecrawl
php artisan vendor:publish --tag=firecrawl-librarium-config
```

Composer also installs `jkudish/laravel-ai-librarium` 1.x if your application
does not have it yet; follow that package's README for core setup.

Add your Firecrawl key to `.env`:

```dotenv
FIRECRAWL_API_KEY=fc-...
```

The Firecrawl SDK reads it as `firecrawl.api_key`, and the adapter uses that
value for any Profile without its own `credential`. Both Profiles are off by
default. Enable only the one you need.

## Collect a consumer surface

In the published `config/firecrawl-librarium.php`, edit these existing values.
Keep every unshown Profile field.

```php
// config/firecrawl-librarium.php
'register_profile' => true,
'profile' => [
    'options' => [
        'mode' => 'interact',
        'target_url' => 'https://example.ai/',
        'surface' => 'example-ai',
        'authentication' => 'anonymous',
        'personalization' => 'unknown',
        'account_context' => 'signed_out',
        'locale' => 'en-CA',
        'country' => 'CA',
        'device' => 'desktop',
    ],
],
```

Anonymous collection is the only accepted execution policy. Firecrawl does not
prove authentication or personalization. Consumer declarations stay labelled
in provider metadata; they are not promoted to observed provenance facts.

- `interact` uses the official SDK for scrape and cleanup, plus one narrow
  prompt-only HTTP seam the SDK does not currently expose.
- `agent` uses the SDK's asynchronous Agent API. It rejects locale, country,
  and device options because that API does not enforce them.
- Polling is authoritative. Signed webhooks are idempotent wake hints.
- Challenge and login-wall output describes one configured surface and context,
  not universal behavior.

Every new surface result uses `surface_observation`, `surface_snapshot`, and
`surface_collector`. Contradictory semantics fail preflight.

## Run raw search

Raw Search is a separate opt-in Profile. In the published config, edit these
existing values and keep every unshown Profile field:

```php
// config/firecrawl-librarium.php
'register_search_profile' => true,
'search_profile' => [
    'corpora' => ['web', 'news'],
    'options' => [
        'sources' => ['web', 'news'],
        'limit' => 10,
        'country' => 'CA',
        'includeDomains' => ['example.com'],
    ],
],
```

Run the enabled Profile. This may incur Firecrawl charges:

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$response = Librarium::query('What changed in Laravel this week?')
    ->using('firecrawl-search')
    ->run();
```

When `sources` changes, update the Profile `corpora` in the same order.
Search accepts only absolute HTTPS result URLs, normalizes web descriptions and
news snippets, and keeps provider-reported citations. It records bounded
`creditsUsed` as `provider_meta.credits_used` without inventing a currency cost.

The shipped Search Profile has no pricing declaration. A result limit is not a
conservative preflight credit bound. Applications may add a configured-only
pricing identity only when they own a truthful bound for their Firecrawl plan.

## Safety limits

- Citations are limited to 20 and excerpts to 1,000 characters.
- At most 10 artifacts are retained as bounded receipts.
- Capability URLs, signed URLs, credentials, browser payloads, and raw provider
  bodies are excluded from results and exceptions.
- A raw artifact URL is retained only when it exactly matches the explicit
  `public_artifact_references` allowlist and passes URL safety checks.
- Digests and redaction states are receipts, not retrievable references.
- The default API URL is `https://api.firecrawl.dev`. Custom HTTPS endpoints
  require `allow_custom_api_url` and remain the application's network-policy
  responsibility.

## Webhooks

Set `firecrawl-librarium.webhook.url` and the matching
`firecrawl-librarium.webhook.secret`. The route verifies
`X-Firecrawl-Signature: sha256=<hex>`, accepts only `agent.completed` and
`agent.failed`, deduplicates `webhookId`, and requires the job to match the
Librarium request and Profile.

## Development

```bash
composer install
composer test
composer analyse
composer format
composer validate --strict
```

Here, `composer format` runs `pint --test` and does not rewrite files. Ordinary
tests make no network calls.

`composer test:live` and `composer test:live-search` are separate paid canaries.
They require exact opt-in and spend-acknowledgement values in addition to a
credential. Do not run either command without fresh authorization for that
specific provider request.

For an exact committed candidate, run `composer pr:check` from a clean
worktree. It runs the repository-owned offline checks and writes a local
exact-SHA receipt. It does not install dependencies, call Firecrawl, publish,
release, deploy, push, or write a GitHub status.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contributor path.

## License

This package is open-source software licensed under the
[MIT license](LICENSE.md).
