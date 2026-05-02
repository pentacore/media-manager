# Repository Rulesets

Configured via the GitHub Rulesets API. Three active rulesets gate the repo;
all let repository admins bypass when needed (e.g. fixing a misconfig).

## main protection

**Target:** `refs/heads/main`. **Enforcement:** active.

**Rules:**

- `deletion` — branch cannot be deleted.
- `non_fast_forward` — no force-pushes.

**No PR rule on main.** semantic-release pushes the `chore(release): X.Y.Z`
commit and the `vX.Y.Z` tag directly to main, and the GitHub-Actions internal
token (id 15368) cannot be added as a bypass actor on personal repos. The
gate happens upstream — features flow through PRs into `develop`, and only
`develop → main` merges (done by an admin) reach `main`.

## develop protection

**Target:** `refs/heads/develop`. **Enforcement:** active.

**Rules:**

- `deletion` — branch cannot be deleted.
- `non_fast_forward` — no force-pushes.
- `pull_request` — required to merge. `required_approving_review_count: 0`
  for solo work; bump to `1+` once others contribute. Allowed merge methods:
  `merge`, `squash`, `rebase` (sync-develop pushes a merge commit, so `merge`
  must stay in the list).
- `required_status_checks` — these CI jobs must pass before merge:
  - `PHP lint (Pint)`
  - `JS lint, format & types`
  - `Pest tests`

  Add `Octane smoke test` once it has had a clean run and you trust it as a
  blocker.

## release tag protection

**Target:** `refs/tags/v*`. **Enforcement:** active.

**Rules:**

- `deletion` — release tags cannot be deleted.
- `update` — `git tag -f vX.Y.Z` blocked; tags are immutable once pushed.
- `non_fast_forward` — no force-pushes.

This freezes the artifact-version mapping. A `vX.Y.Z` tag always points at
the commit semantic-release stamped — `docker.yml` builds the matching
`ghcr.io/...:X.Y.Z` image from that commit, and nothing can rewrite either.

## Bypass

All rulesets list one bypass actor: `RepositoryRole` `5` (Admin), mode
`always`. So an admin can force-push to recover from breakage without first
disabling the ruleset. Non-admin pushes / PRs follow the rules normally.

## Workflow

```
feature branch  ─PR─►  develop  ─merge (admin)─►  main
                                                    │
                       semantic-release fires ──────┘
                                  │
                ┌─────────────────┼─────────────────┐
                ▼                                   ▼
      git tag v1.2.3 (stable)            git tag v1.3.0-develop.N (prerelease)
                │                                   │
                ▼                                   ▼
    docker.yml builds + pushes:        docker.yml builds + pushes:
      ghcr.io/.../media-manager        ghcr.io/.../media-manager
        :1.2.3 :1.2 :1 :latest           :1.3.0-develop.N :next
```

## Managing

```sh
# List active rulesets
gh api /repos/pentacore/media-manager/rulesets \
  | jq -r '.[] | "\(.id)\t\(.target)\t\(.name)\t\(.enforcement)"'

# Inspect one
gh api /repos/pentacore/media-manager/rulesets/<id>

# Update (PUT a full body — partial PATCH is not supported here)
gh api /repos/pentacore/media-manager/rulesets/<id> -X PUT --input updated.json

# Delete
gh api /repos/pentacore/media-manager/rulesets/<id> -X DELETE

# Pause without deleting
gh api /repos/pentacore/media-manager/rulesets/<id> -X PUT \
  -F enforcement=disabled --input current.json
```

## Current ruleset IDs

| ID | Target | Name |
|---|---|---|
| 15856850 | branch | main protection |
| 15856857 | branch | develop protection |
| 15856859 | tag    | release tag protection |
