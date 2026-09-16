# Contributing

Keep Firecrawl-specific SDK and browser behavior in this adapter. Changes to
Librarium's core contracts belong in the core package.

## Setup and checks

```bash
composer install
composer test
composer analyse
composer format
composer validate --strict
```

In this repository, `composer format` is a nonmutating Pint check. The live
scripts are paid canaries and require separate authorization.

Before requesting review, commit the intended changes, require a clean
worktree, and run:

```bash
composer pr:check
```

Report the behavior changed, checks run, and any provider claim that remains
live-unverified. This local check does not push, sign off, merge, publish,
release, deploy, or call Firecrawl.
