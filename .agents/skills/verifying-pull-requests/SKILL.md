---
name: verifying-pull-requests
description: Verifies Firecrawl adapter pull requests in an Amp orb and posts trusted exact-SHA signoff. Use before creating, updating, or signing off a pull request.
---

# Verifying Pull Requests

Use the repository-owned Composer commands; do not reconstruct or expand the
plan manually.

## Workflow

1. Ensure dependencies were installed by orb setup. Candidate verification does
   not run `composer install` or resolve dependencies.
2. Finalize and commit every intended change. Verification requires a clean
   worktree.
3. Run `composer pr:check` in the current Amp orb.
4. Inspect the printed full SHA, plan ID, and local Git-admin receipt.
5. Push that exact commit and open or update its pull request without posting
   signoff.
6. Confirm the open remote PR head equals the receipt SHA, then ask a human to
   explicitly approve that full 40-character lowercase SHA.
7. Only after that exact-SHA approval, run:

   ```bash
   composer pr:signoff -- --approved-sha <full-sha>
   ```

8. Read back the GitHub `signoff` commit status.

Any commit, worktree drift, verification-plan change, runtime-policy change, or
runtime-evidence change requires a new check and new exact-SHA approval. Never
copy receipts between commits or orbs, and never force signoff.

Read [references/workflow.md](references/workflow.md) for guards and failure
handling, [references/coverage.md](references/coverage.md) before describing
what the receipt proves, and [references/setup.md](references/setup.md) only for
separately authorized one-time setup.

## Authority boundary

Verification and signoff attest only that the repository-owned offline package
plan passed in the recorded orb runtime for the approved SHA. They do not grant
cryptographic signing, PR approval, merge, tag, ruleset, publication, release,
deployment, production-verification, provider-call, or provider-spend authority.
