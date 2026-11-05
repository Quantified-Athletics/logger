# Architect Workflow (Logger)

The general workflow (planning in milestones, writing execution prompts, reviewing executions, the commit
conventions) is **tq's**, in `mauzeh/agentic` `automation/rules/` (on this machine
`~/Herd/agentic/automation/rules/`): `planning.md`, `prompt-authoring.md`, `execution-review.md`,
`conventions.md`. Automated turns get those rules in their turn prompt; a hand session reads them there.

This file keeps only what is Logger's: where things live here, what every Logger prompt adds, and what a Logger
review checks.

## The three layers (Logger paths)

| Layer | Location | Read by |
|-------|----------|---------|
| PLAN | `docs/plans/{topic}-plan.md` (format: `antigravity-steering.md` §9) | planner, author, executor (context) |
| PROMPT | `docs/plans/{topic}-p{N}-{slug}-prompt.md`, from `docs/plans/template-prompt.md` | executor |
| RULES | `docs/antigravity-steering.md` (executor), `CLAUDE.md` and `.claude/rules/*.md` | every turn |

The executor reads `docs/antigravity-steering.md`, the plan and its prompt. It never needs to know a reviewer
exists: executor-facing files only tell it to leave the retro untouched.

## Logger additions to every prompt

- **Test checkpoints are `php artisan test --parallel`** in every milestone. If a data-shape milestone lands,
  its checkpoint asserts the new shape.
- **Name destinations the Laravel way:** the exact path, the class and method signature (promoted constructor
  parameters, the `execute()` shape, the return type), and the domain folder, Action or Form Request it belongs
  in (`project-conventions.md`).
- **Consumer Impact Trace for every data-shape change** (`antigravity-steering.md` §12): what reads it (models,
  controllers, actions, `ExerciseType` strategies, Blade, API resources, tests), what interprets it at display
  time, and which tests assert the old shape. A column-semantics change with several readers is this app's
  highest-risk change.
- **Specify migrations exactly:** new columns nullable, added to `$fillable` and `casts()`, no repurposed columns,
  no modified historical migrations. A migration that re-shapes data states its exact selection query and its
  down-migration behavior. Never leave "migrate the old data" to the executor.
- **State why a migration is safe to auto-apply.** It runs on production the moment the PR merges. Say so in the
  prompt, and name the SoftDeletes and foreign-key behavior it relies on.
- **Entity defaults for new stored records** as a code block: every field, default, nullability and cast
  (`antigravity-steering.md` §14).
- **Decompose at 4+ consumers** (`antigravity-steering.md` §14): logic and unit tests, then one integration point,
  then the sweep.

## Logger review checklist

On top of `execution-review.md`, a Logger review checks:

- **Verify:** `php artisan test --parallel` passes. Confirm no Pint run.
- **Migrations, every time one is in the diff:**
  - Safe to auto-apply: additive and nullable, a reversible `down()`, no destructive data operation without
    explicit intent, no edit to a migration that has already run.
  - SQLite can't prove it. A green `RefreshDatabase` test doesn't catch MySQL-only failures such as
    self-referential foreign keys, and soft-deleted rows can survive a delete (`project-conventions.md`).
  - Say in the review, in plain words, that the change contains a migration and what it does to production data,
    so the owner sees it before merging.
- **Conventions:** domain-folder placement, Action pattern, Form Requests, Eloquent over `DB::`, explicit return
  types, shared-model safety.
- **Dead code:** no unused code, no commented-out code, no backward-compat shims, no leftover TODO/FIXME.
- **Web UI:** a change a person can see gets click-by-click steps in the handoff. The tests alone don't prove it.

## Keep the rules current

If an execution reveals a new failure mode, add it to `docs/antigravity-steering.md` or the relevant
`.claude/rules/` file in the same issue, so the next run inherits the lesson.

## References

- Executor rules: `docs/antigravity-steering.md` · prompt template: `docs/plans/template-prompt.md`
- Logger's rules: `CLAUDE.md` and `.claude/rules/`
- Completed archive: `docs/plans/completed/`
