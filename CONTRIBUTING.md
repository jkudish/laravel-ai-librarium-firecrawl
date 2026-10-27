# Contributing

Keep Firecrawl-specific SDK and browser behavior in this adapter. Changes to
Librarium's core contracts belong in
[laravel-ai-librarium](https://github.com/jkudish/laravel-ai-librarium), and
shared engine names and Run schemas belong in
[librarium-spec](https://github.com/jkudish/librarium-spec).

## Setup and checks

```bash
composer install
composer test
composer analyse
composer format
composer validate --strict
```

In this repository, `composer format` and `composer format:check` are the same
nonmutating Pint check. Offline tests use Laravel HTTP fakes and a Guzzle
MockHandler for the official SDK. Test PHP 8.4 with the lowest dependencies
and PHP 8.5 with the highest dependencies. Keep development dependency
overrides, such as an unreleased core checkout, in an isolated Composer
harness, never in this package's `repositories`.

`composer test:live` is a paid browser canary. It requires separate
authorization, credentials, the exact opt-in and spend acknowledgement. The
acknowledgement is not provider-enforced spending control.

## Pull requests

Before requesting review, commit the intended changes, require a clean
worktree, and run:

```bash
composer pr:check
```

It writes only a local exact-SHA receipt. It does not push, sign off, merge,
publish, release, deploy, or call Firecrawl.

Report the behavior changed, checks run, and any provider claim that remains
live-unverified.
