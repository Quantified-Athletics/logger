# Git Workflow

## The one hard rule

**Everything reaches `main` through a pull request.** Never push to `main`, never commit on it, and never
merge into it. A push to `main` deploys to production (see Deployment).

Pushing any other branch is fine. Merging `origin/main` into your own branch is fine when that's the job.
Only the owner merges a pull request.

## Branches

- **Issues driven by tq:** one issue, one branch, `issue/<n>-<slug>`, created and owned by the automation. In
  an automated turn the branch is already checked out: never create, rename or switch one.
- **Work by hand:** branch from `main` as `fix/<topic>`, `chore/<topic>` or `feature/<topic>`, lowercase
  kebab-case.

## Commits

Closed prefix set, shared with tq and the other Squirby repositories:

| Prefix | Use for |
|--------|---------|
| `plan(<area>):` | A plan document |
| `prompt(<area>):` | Writing or revising an execution prompt |
| `snapshot:` | Verbatim executor output, written by the automation; never amended |
| `fix(<area>):` | A bug fix, or a correction after review |
| `feat(<area>):` | A feature built by hand |
| `docs(<area>):` | Docs, retros, close-outs |
| `steering(<area>):` | A change to a rule (`CLAUDE.md`, `.claude/rules/`, `docs/antigravity-steering.md`) |
| `chore:` | Tooling, dependencies, cleanup |

`<area>` is the feature slug. Keep the subject under about 70 characters, in the imperative, and put detail in
the body.

## Pull requests

One issue, one branch, one pull request, opened when the work is finished and reviewed. On an issue tq owns,
the automation opens it. The owner merges.

## Rewriting history

- **Never amend or force-push a commit that has already been pushed.** Make a new commit on top instead.
- Never rebase onto `main`. Merge `origin/main` in instead.

## Deployment

- Pushes to `main` trigger automatic production deploy via Laravel Forge, and migrations run as part of it.
  There is no staging gate: a broken merge breaks production immediately.
- Production domain: `squirby.ai`
- Athlete app domain: `flagship.squirby.ai`
- After config changes, the owner may need to run `php artisan config:clear` on the server if config caching
  is active.
