# Laravel AI Librarium — Firecrawl

Optional first-party Firecrawl surface-collection adapter for
`jkudish/laravel-ai-librarium`. Firecrawl and its browser/SDK dependencies stay
outside the core package.

## Install

```bash
composer require jkudish/laravel-ai-librarium-firecrawl
php artisan vendor:publish --tag=firecrawl-librarium-config
```

Set `FIRECRAWL_API_KEY`; the official SDK exposes it to this adapter as
`firecrawl.api_key`. Then opt into the adapter Profile:

```dotenv
FIRECRAWL_API_KEY=fc-your-api-key
```

```php
// config/firecrawl-librarium.php
'register_profile' => true,
'profile' => [
    // Keep the shipped semantic fields, then configure:
    'options' => [
        'mode' => 'interact', // default; use "agent" for async collection
        'target_url' => 'https://example.ai/',
        'surface' => 'example-ai',
        'authentication' => 'anonymous',
        // Consumer declarations, not provider-measured facts:
        'personalization' => 'unknown', // present|absent|unknown
        'account_context' => 'signed_out', // signed_out|unknown
        'locale' => 'en-CA',
        'country' => 'CA',
        'device' => 'desktop',
    ],
],
```

Anonymous collection is the only currently accepted authentication mode.
Consumers own credentials, persistence, evidence policy, and any future
authenticated browser context.

## Behavior

- `interact` mode uses the official SDK for the initial scrape and browser
  cleanup. A narrow Laravel HTTP seam sends the prompt-only Interact request.
- `agent` mode uses official SDK `startAgent()` and `getAgentStatus()` calls.
  Polling is authoritative; signed webhooks are idempotent wake hints.
  Because the Agent API has no location/device controls, Agent Profiles reject
  locale, country, and device options rather than claiming unenforced context.
- Output is accepted only after deterministic validation. Citations are limited
  to 20 and excerpts to 1,000 characters. Artifact input is shape-checked and
  capped at 10. Each artifact becomes a bounded receipt containing its
  allowlisted kind, a stable SHA-256 reference digest, and an explicit retained
  or redacted state. A raw HTTPS reference is retained only when that exact URL
  appears in `firecrawl-librarium.public_artifact_references` and it has no
  query, fragment, credentials, encoded capability marker, opaque high-entropy
  path segment, or capability-shaped browser/session path. Firecrawl, CDP,
  interactive-view, and browser bearer-capability URLs are always redacted.
  Digests and redaction states are receipts, not retrievable references. Large
  browser payloads are never retained.
- Challenge and login-wall results are observations of one configured surface
  and context, not universal truth.
- `personalization` (`present|absent|unknown`) and `account_context`
  (`signed_out|unknown`) are constrained consumer declarations. Core 1.0
  provenance context currently accepts only locale, country, device, and
  authentication, so these declarations remain explicitly labelled under
  `provider_meta.consumer_declared_context`; they are not represented as
  collector-measured provenance facts. A future core contract would be required
  before they can move into provenance context.
- Delayed and stalled progress remains nonterminal until the earlier trustworthy
  provider deadline, request deadline, or Librarium two-hour ceiling.

### Temporary Interact seam

Firecrawl PHP SDK 1.13 requires a `code` argument and always serializes it, while
the Firecrawl API requires prompt-only Interact requests to omit both `code` and
`language`. Its response DTO also discards `output`. `PromptInteractClient`
exists only for that one unsupported call, sends only `prompt` and `timeout`, and
returns only bounded `output`; it must be removed when the SDK exposes a valid
prompt-only method and output getter. It never exposes CDP or interactive-view
URLs.

Each SDK client is constructed from the exact Profile credential, adapter API
URL, and remaining shared deadline; the temporary Interact transport uses those
same inputs. The default URL is restricted to `https://api.firecrawl.dev`.
Self-hosted/custom HTTPS endpoints require the explicit
`allow_custom_api_url` opt-in and remain the consumer's network-policy
responsibility.

## Webhooks

Set `firecrawl-librarium.webhook.url` to the public route and configure the same
account webhook secret at `firecrawl-librarium.webhook.secret`. The controller
verifies `X-Firecrawl-Signature: sha256=<hex>` over the untouched raw body,
accepts only `agent.completed` and `agent.failed`, deduplicates `webhookId`, and
requires the job to have been bound to the exact Librarium request and Profile.

## Verification

```bash
composer test
composer analyse
composer format
composer validate --strict
```

Ordinary tests make no network calls. `composer test:live` is an explicit,
release-gated paid canary and is not authorized by normal package verification.
It requires `LIBRARIUM_LIVE_TESTS=1`, `FIRECRAWL_API_KEY`, a target URL, and the
`FIRECRAWL_LIVE_SPEND_ACK` variable set to the exact value
`acknowledge-2500-credit-maximum`. The expected maximum is Firecrawl Agent's
documented default request ceiling of 2,500 credits; actual credit-to-currency
cost depends on the consumer's Firecrawl plan. This command must not be run
without fresh authorization for that provider spend.

Pull requests can produce repository-owned Amp-orb evidence with:

```bash
composer pr:check
```

The command requires a clean commit, runs the ordered offline package checks in
an allowlist environment with a disposable home and no GitHub, signoff,
Firecrawl, other provider, or Composer credentials, then writes a mode-`0600`
receipt beneath Git administrative storage. The receipt binds the full commit
SHA, verification-plan ID, PHP `^8.4` compatibility policy and 8.4.0 floor, and
the bounded PHP, Composer, operating-system, and architecture evidence actually
used. Changing the commit, plan, runtime policy, or actual runtime invalidates
eligibility.

After a human separately approves the exact full lowercase SHA, the local
pull-request skill may run:

```bash
composer pr:signoff -- --approved-sha <full-sha>
```

The guarded command also requires an open GitHub pull request at that remote
head and the `basecamp/gh-signoff` extension. It maps `GH_SIGNOFF_TOKEN` to
`GH_TOKEN` only for the final exact `gh signoff --commit <sha>` call and never
uses force. The fine-grained token should be limited to this repository with
**Contents: read**, **Pull requests: read**, and **Commit statuses: read/write**.
Read-only PR inspection uses the existing GitHub CLI login instead of the
signoff token.

This status attestation is not cryptographic commit signing, self-approval,
merge approval, ruleset setup, release, publication, deployment, production
verification, or live-provider evidence. See
[the pull-request verification skill](.agents/skills/verifying-pull-requests/SKILL.md)
for the exact approval and failure boundaries.
