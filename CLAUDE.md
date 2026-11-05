# Logger

The durable sync backend and coaching web app for the Squirby platform, live at `squirby.ai`. Coaches and
athletes use its Blade web UI; Prism and the Athlete app sync through its API.

- **Stack:** Laravel 12, PHP 8.4, MySQL in production, Blade + Alpine.js + Tailwind, Breeze session auth for the
  web, Sanctum tokens for the API, PHPUnit (never Pest).
- **Commands:** `php artisan test --parallel` (full suite, under a minute),
  `php artisan test tests/Feature/Path/SomeTest.php` or `--filter=name` while iterating. Tests run on in-memory
  SQLite and need no database setup. Always pass `--no-interaction` to artisan.
- **Rules:** `.claude/rules/` holds the rules. `safe-operations.md`, `project-conventions.md`, `git-workflow.md`
  and `laravel-boost.md` always apply; `sync-api-context.md` and `telemetry-dashboard.md` load when you touch
  those areas.

## Where things live

| What | Where |
|---|---|
| Web UI controllers, models, services | `app/Http/Controllers/`, `app/Models/`, `app/Services/` |
| Isolated feature modules | Domain folders such as `app/Sync/`, `app/Telemetry/` |
| Business logic | Action classes with `execute()`, for example `app/Actions/LiftLogs/` |
| Sync API routes | `routes/sync.php` |
| Plans and execution prompts | `docs/plans/`; shipped work moves to `docs/plans/completed/` |
| How plans and prompts are written and reviewed | `docs/architect-workflow.md` |
| Executor rules | `docs/antigravity-steering.md` |

## Hard rules

1. **Everything reaches `main` through a pull request.** A push to `main` deploys to production with no staging
   gate, and migrations run as part of that deploy (`git-workflow.md`).
2. **A migration must be safe to auto-apply before it reaches `main`:** additive and nullable, a reversible
   `down()`, no destructive data operation without explicit intent. Never modify one that has already run.
   Green tests only prove it on SQLite (`project-conventions.md`).
3. **Existing web UI behavior is sacred.** A change to `LiftLog`, `LiftSet`, `User` or `Exercise` runs the full
   suite.
4. **Trace every consumer of a data shape** before changing it, including API clients
   (`docs/antigravity-steering.md` §12).
5. **Logic lives in Actions and services,** not controllers. Side effects are dispatched as events. Validation
   lives in Form Requests. Use Eloquent, not the `DB::` facade.
6. **Never run Pint, in any form, ever.** Never run a destructive database command either (`safe-operations.md`).
7. **The request path is scarce.** Logger shares a small Forge box with the production database, so reporting
   and analytics work must stay cheap on it (`project-conventions.md`).
8. **Production data stays out of reach.** Never read `.env`, `.env.local`, `.env.backup` or the `*.sql` dumps
   in the owner's checkout, and never run `pull_mysql_db.sh` or `pull_storage.sh`.

## Automation (tq)

Issues are driven by tq (`~/Herd/agentic`). One issue, one branch `issue/<n>-<slug>`, one PR, opened by the
automation only when the issue is finished and reviewed. tq delivers the turn contracts, role definitions and
general rules in each turn prompt; this file and `docs/architect-workflow.md` are Logger's ground rules for
every turn.

- In an automated turn: never create or switch branches, never open a PR, never start a long-running server.
  The owner serves and reviews.
- The worktree has its own `.env` from `.env.example`. It holds no production values, and tests don't need more.
- Cross-app contract tests live outside this repository and aren't part of an automated turn. If a change
  alters a shape other apps depend on, say so in your handoff or reply so the owner can check it.

## Git

Commit prefixes, branches and PR rules are in `.claude/rules/git-workflow.md`. Stage files by name. `snapshot:`
commits are verbatim executor output; never amend them.
