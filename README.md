# Laravel AI Librarium: Firecrawl

Optional **browser engine collector** for `jkudish/laravel-ai-librarium`.
Firecrawl search is built into core with Laravel HTTP; this adapter owns only
SDK Interact and asynchronous Agent collection.

## Install and configure

PHP 8.4+, Laravel 13 and the compatible Librarium 1.0 surface are required.
Neither candidate is published; the following command applies after release:

```bash
composer require jkudish/laravel-ai-librarium-firecrawl
php artisan vendor:publish --tag=firecrawl-librarium-config
```

Set `FIRECRAWL_API_KEY` through the SDK's `config/firecrawl.php`, or set
`services.firecrawl.key`. An explicit `engines.key` takes precedence. Select
the collector in Librarium configuration:

```php
// config/librarium.php
'engines' => [
    'collector' => 'firecrawl',
    'mode' => 'interact',
    'chatgpt' => ['options' => ['locale' => 'en-CA', 'country' => 'CA', 'device' => 'desktop']],
],
```

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$run = Librarium::research('What does this product say about Laravel?')
    ->only(['chatgpt', 'gemini-app'])
    ->run();

$run->result('chatgpt'); // observation: surface
$run->evidence('chatgpt'); // Redacted, separate from public Run JSON.
```

Supported engines: `chatgpt`, `gemini-app`, `google-ai-mode`,
`google-ai-overview`, `copilot`. Their starting URLs are in
`firecrawl-librarium.urls`. Engines run only when named, never by default, and
have no deep variant. `deep()` selects variants; it does not select Agent mode.

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

Use `only(['example-ai'])` like any other engine. URLs and credentials belong
in configuration, not request overrides. Targets may not contain userinfo.
Apps own target authorization, network policy and permission to collect the
surface. Changing a URL or binding invalidates saved signed handles.

## Inline Interact and durable Agent

- **Interact:** the official SDK scrapes and cleans up the browser; the existing
  narrow prompt-only Laravel HTTP seam supplies the interaction the SDK cannot
  express. Locale/country/device are configured controls, not proof of the
  observed user's context. Cleanup is best effort within the deadline.
- **Agent:** one SDK submission saves its signed handle in the Run's private
  state. `run()` waits; `queue()` dispatches core's single encrypted job;
  `find()->refresh()` observes each pending task once, with no submission,
  sleep, cancellation or paid retry. Agent rejects locale/country/device
  because its API does not enforce them. Credits remain credits, never USD.

```php
$queued = Librarium::research('Observe the configured surface')
    ->only(['example-ai'])
    ->queue();
$id = $queued->id();
// The job starts the task. Come back after the worker runs:
$run = Librarium::find($id)->refresh();
```

Storage, retention, deadlines and budget admission belong to core. Firecrawl
has no shipped bounded USD estimate; a dollar cap skips it as `unpriced`.
Tasks can outlive local waiting. Losing an accepted submission's response is
ambiguous, not permission to resubmit. Recover saved handles with the original
app key, credential and binding. Provider/account limits are still necessary.

## Webhooks and evidence

Set `firecrawl-librarium.webhook.url` to your HTTPS receiver and
`firecrawl-librarium.webhook.secret` to its signing secret. The route verifies
the raw body's `X-Firecrawl-Signature: sha256=<hex>`, permits only
`agent.completed`/`agent.failed`, deduplicates `webhookId`, and requires a job
bound to the Run and engine variant. A verified event records a wake hint;
it cannot finish the Run or supply an answer. SDK retrieval stays authoritative.

Anonymous collection is the only execution policy. Authentication and
personalization are not established by a declaration. Challenges and login
walls describe one observation, not universal behavior. Citations are limited
to 20, excerpts to 1,000 characters and artifact receipts to 10. Capability
URLs, credentials and browser payloads are excluded from terminal output.
Only exact, safe URLs in `public_artifact_references` survive as artifact
references; otherwise receipts contain digests. Treat answers and URLs as
untrusted. Core redacts evidence before saving it; restrict Run access and
choose retention for private source material.

## Development

```bash
composer test
composer analyse
composer format:check
composer validate --strict
```

`composer format` and `format:check` are nonmutating Pint checks. Offline tests
use Laravel HTTP fakes and a Guzzle MockHandler for the official SDK. Test PHP
8.4 with lowest dependencies and PHP 8.5 with highest dependencies, against
the exact core candidate. Development dependency overrides belong in an
isolated Composer harness, never the published library's repositories.

Commit all intended changes, require a clean worktree and run `composer pr:check`
at the final HEAD. It writes only a local exact-SHA receipt. No signoff,
provider call, publication, migration or deployment is authorized by that gate.
`composer test:live` is a separate paid browser canary requiring fresh approval,
credentials, exact opt-in and spend acknowledgement. It was not run for this
candidate; its acknowledgement is not provider-enforced spending control.

See [CONTRIBUTING.md](CONTRIBUTING.md). [MIT license](LICENSE.md).
