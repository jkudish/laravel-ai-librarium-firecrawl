# Laravel AI Librarium: Firecrawl

[![Packagist version](https://img.shields.io/packagist/v/jkudish/laravel-ai-librarium-firecrawl)](https://packagist.org/packages/jkudish/laravel-ai-librarium-firecrawl)
[![PHP version](https://img.shields.io/packagist/dependency-v/jkudish/laravel-ai-librarium-firecrawl/php)](composer.json)
[![License: MIT](https://img.shields.io/packagist/l/jkudish/laravel-ai-librarium-firecrawl?color=blue)](LICENSE.md)

<p align="center">
  <img src=".github/librarium-banner.png" alt="librarium: Give your agent more than one source." />
</p>

This adapter lets [Laravel AI Librarium](https://github.com/jkudish/laravel-ai-librarium) collect answers from AI answer engines, such as ChatGPT and Google AI Overview, by asking them in a Firecrawl browser session.

Firecrawl search is already built into core. Install this package only for browser collection. See the [Firecrawl section on librarium.si](https://librarium.si/docs/php/providers#firecrawl).

## Requirements

- PHP 8.4 or newer
- Laravel 13.30 or newer, required by the core package
- A Firecrawl API key

Composer installs `jkudish/laravel-ai-librarium` 1.x and the official `firecrawl/firecrawl-sdk` if your application does not have them yet.

## Install

```bash
composer require jkudish/laravel-ai-librarium-firecrawl
php artisan vendor:publish --tag=librarium-config
php artisan vendor:publish --tag=firecrawl-librarium-config
```

The first command publishes core's `config/librarium.php`; skip it if you already have that file. The second publishes `config/firecrawl-librarium.php`, which holds engine URLs and webhook settings.

Add your Firecrawl key to `.env`:

```dotenv
FIRECRAWL_API_KEY=fc-...
```

The Firecrawl SDK reads it as `firecrawl.api_key`, and the adapter copies it to `services.firecrawl.key` when that is empty. That key also configures core's `firecrawl` search source, so it joins research that does not call `only()`. An explicit `engines.key` in `config/librarium.php` takes precedence for engines.

## First observation

Select the collector in core's configuration:

```php
// config/librarium.php
'engines' => [
    'collector' => 'firecrawl',
    'mode' => 'interact',
    'chatgpt' => ['options' => ['locale' => 'en-CA', 'country' => 'CA', 'device' => 'desktop']],
],
```

Then name the engine:

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$run = Librarium::research('What does this product say about Laravel?')
    ->only(['chatgpt'])
    ->run();

$run->status();                         // "done"
$run->result('chatgpt')['observation']; // "surface"
$run->result('chatgpt')['answer'];      // The answer ChatGPT showed
$run->evidence('chatgpt');              // Redacted exchanges, separate from public Run JSON
```

In `interact` mode, Firecrawl opens the engine's starting URL, asks the question, and returns the observed answer and citations inline. Firecrawl charges credits. Librarium never converts credits to USD, so the reported cost stays `null`.

## What it does

- Registers the `firecrawl` collector for `chatgpt`, `gemini-app`, `google-ai-mode`, `google-ai-overview`, and `copilot`. Their starting URLs are in `firecrawl-librarium.urls`.
- Adds your own engines by URL.
- Runs `interact` mode inline, or `agent` mode as a durable Firecrawl task that `queue()` and `Librarium::find($id)->refresh()` can recover.
- Accepts signed Firecrawl webhooks at `POST /librarium/webhooks/firecrawl` as wake hints for Agent tasks.

## Limits

- Engines run only when named, never by default, and have no deep variant. `deep()` does not select Agent mode.
- Firecrawl has no bounded USD estimate in Librarium. Under `maxCost()`, engines are skipped as `unpriced`. Use Firecrawl account limits to cap spend.
- Collection is anonymous only. Locale, country, and device are configured controls, not proof of the observed user's context. Agent mode rejects them because its API does not enforce them.
- Each Run is one observation. A challenge or login wall describes that observation, not the engine's general behavior.
- Treat answers and URLs as untrusted. Your application owns target authorization, network policy, and permission to collect each surface.

## Custom engines by URL

```php
// config/librarium.php
'engines' => [
    'collector' => 'firecrawl',
    'custom' => [
        'example-ai' => ['url' => 'https://example.ai/', 'mode' => 'agent'],
    ],
],
```

Use `only(['example-ai'])` like any other engine. URLs and credentials belong in configuration, not request options. Targets may not contain userinfo. Changing a URL or binding invalidates saved signed handles.

## Interact and Agent modes

- **Interact:** the official SDK scrapes and cleans up the browser session. A narrow prompt-only Laravel HTTP call supplies the interaction the SDK cannot express. Cleanup is best effort within the deadline.
- **Agent:** one SDK submission saves its signed handle in the Run's private state. `run()` waits; `queue()` dispatches core's single encrypted job; `find()->refresh()` observes each pending task once, with no submission, sleep, cancellation, or paid retry.

```php
$queued = Librarium::research('Observe the configured surface')
    ->only(['example-ai'])
    ->queue();
$id = $queued->id();
// The job starts the task. Come back after the worker runs:
$run = Librarium::find($id)->refresh();
```

Storage, retention, deadlines, and budget admission belong to core. Tasks can outlive local waiting. A lost response to an accepted submission is ambiguous, not permission to resubmit. Recover saved handles with the original app key, credential, and binding.

## Webhooks and evidence

Set `firecrawl-librarium.webhook.url` to the HTTPS URL of your app's `/librarium/webhooks/firecrawl` route and `firecrawl-librarium.webhook.secret` to its signing secret. The route verifies the raw body's `X-Firecrawl-Signature: sha256=<hex>`, accepts only `agent.completed` and `agent.failed`, deduplicates `webhookId`, and requires a job bound to the Run and engine variant. A verified event records a wake hint. It cannot finish the Run or supply an answer; SDK retrieval stays authoritative.

Citations are limited to 20, excerpts to 1,000 characters, and artifact receipts to 10. Capability URLs, credentials, and browser payloads are excluded from terminal output. Only exact, safe URLs listed in `firecrawl-librarium.public_artifact_references` survive as artifact references; other receipts contain digests. Core redacts evidence before saving it. Restrict Run access and choose retention for private source material.

## Learn more

- [Laravel AI Librarium](https://github.com/jkudish/laravel-ai-librarium) for core setup, storage, and recovery
- [Sources and AI answer engines](https://librarium.si/docs/php/providers) and [costs and limits](https://librarium.si/docs/php/costs-and-limits) on librarium.si
- [Librarium spec](https://github.com/jkudish/librarium-spec) for the shared engine catalog and Run schema

Report bugs in [GitHub issues](https://github.com/jkudish/laravel-ai-librarium-firecrawl/issues).

## The Librarium family

- [Librarium](https://github.com/jkudish/librarium): the JavaScript CLI, MCP server and library (`npm install -g librarium`).
- [Laravel AI Librarium](https://github.com/jkudish/laravel-ai-librarium): the Laravel package (`composer require jkudish/laravel-ai-librarium`).
- [librarium-spec](https://github.com/jkudish/librarium-spec): the shared Run schemas, source catalog and conformance fixtures.
- [librarium.si](https://librarium.si): documentation for all of them.

## Sponsoring

If you find this adapter useful, consider becoming a [sponsor](https://github.com/sponsors/jkudish) or [donating](https://stripe.com/@jkudish).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) to work on the code. To report a vulnerability, see [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE.md)
