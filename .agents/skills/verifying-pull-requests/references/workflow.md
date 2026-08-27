# Workflow invariants

`composer pr:check` captures the full lowercase `HEAD`, requires a clean
worktree, and runs this exact ordered plan:

1. `composer validate --strict`
2. `composer check-platform-reqs`
3. `composer test`
4. `composer analyse`
5. `composer format`
6. `git diff --exit-code`

Every step receives only `PATH`, a disposable `HOME`, `APP_ENV=testing`,
`CI=1`, and `COMPOSER_NO_INTERACTION=1`. It cannot receive ambient GitHub,
signoff, Firecrawl, other provider, or Composer credentials/configuration. The
command never installs dependencies, runs `test:live`, calls providers, or uses
application services.

Only after every step succeeds and the clean SHA is unchanged does the command
atomically rename a versioned mode-`0600` receipt beneath the path returned by
`git rev-parse --git-path`. Receipts are keyed by full SHA and remain outside
the worktree.

`composer pr:signoff` fails closed on a malformed or partial approval SHA,
dirty tree, local HEAD mismatch or drift, absent/link/malformed/oversized or
stale receipt, wrong receipt version/SHA/plan/success/runtime, missing
`basecamp/gh-signoff`, invalid/closed PR, or remote-head mismatch. Read-only
GitHub inspection pins `jkudish/laravel-ai-librarium-firecrawl`, uses the
existing CLI login, and excludes ambient tokens. Only the final exact status
call receives `GH_SIGNOFF_TOKEN` as `GH_TOKEN`; `GH_REPO` pins the same target:

```bash
gh signoff --commit <full-sha>
```

The command never adds a force flag. If any guard fails, fix the cause, commit
when necessary, rerun `composer pr:check`, and obtain fresh approval of the new
full SHA.
