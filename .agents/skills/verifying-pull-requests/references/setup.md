# One-time setup

Install `basecamp/gh-signoff` for the user running the guarded command:

```bash
gh extension install basecamp/gh-signoff
```

Provide `GH_SIGNOFF_TOKEN` only at signoff time. Use a fine-grained token scoped
to this repository with:

- Contents: read
- Pull requests: read
- Commit statuses: read/write

The workflow maps that value to `GH_TOKEN` only for the final
`gh signoff --commit <sha>` process. `gh pr view` uses the existing GitHub CLI
login with ambient token variables removed.

Installing an extension, provisioning a token, changing repository rulesets or
required checks, and posting the first real status are separate authorized
actions. Never store the token in the repository, Composer configuration,
receipt, logs, or review report.
