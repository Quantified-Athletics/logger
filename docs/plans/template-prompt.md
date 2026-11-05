---
# Global Execution Rules

1. Execute this plan sequentially, pausing to test ONLY at the designated Milestone checkpoints.

2. CRITICAL SELF-CORRECTION LOOP: When you reach a testing step, run `php artisan test --parallel`. If the tests fail, immediately read the error output, fix the code, and re-run them.

3. Do not finish your execution turn or yield control back to the shell until the tests for the current milestone pass completely.

4. When ALL milestones are complete and all success criteria are met AND the End-of-Run Cleanup Sweep is clean, write `.execute-handoff.md`, then print exactly this line as your final output. Do NOT commit or push: the automation commits what you leave in the worktree as the snapshot (`docs/antigravity-steering.md` §3):
   ```
   AGY_COMPLETE: All milestones passed.
   ```
   (Do NOT open/promote/merge a PR or touch `main`. Do NOT touch the Post-Execution Retro section: leave its `{placeholder}` values as-is; it is filled in later from the version-control history.)

5. CONTEXT BUDGET: Your context window is finite. If a milestone applies the same mechanical pattern to more than 5 files, write a temporary script, run it once, and delete it (`docs/antigravity-steering.md` §11).

---

# {Feature Name} — Implementation Prompt

## Feature Classification

> Pick ONE. This determines which traces below are required.

- [ ] **Pure Refactor** — zero behavioral change. No traces required.
- [ ] **Isolated Logic** — a new Action or service with one consumer. Entity Defaults required if creating stored records.
- [ ] **Integrated Feature** — new logic consumed by 2–3 existing components. Entity Defaults required if creating stored records.
- [ ] **Data Shape Change** — a column, cast, API response or event payload changes. Consumer Impact Trace required; if it has a migration, state why it's safe to auto-apply.

## Before You Start

Read these files in order. They contain everything you need to implement this feature correctly.

### 1. Steering (project rules — always follow)
```
docs/antigravity-steering.md            → executor rules: worktree, testing, handoff, data integrity
.claude/rules/safe-operations.md        → files to never touch, command safety, artisan safety
.claude/rules/project-conventions.md    → architectural principles for this project
.claude/rules/laravel-boost.md          → Laravel framework conventions (PHP style, testing)
```

### 2. Feature Spec (what to build)
```
{spec or plan doc path}                 → requirements, architecture, implementation plan
```

### 3. Existing Code to Understand (read before modifying)
```
{list files with brief annotations}
```

### 4. Reference (already implemented — don't rebuild, just understand)
```
{optional: related existing implementations to study for patterns}
```

---

## What You're Building

{2-3 paragraph description: what the feature does, why, end state.}

---

## Traces

{Consumer Impact Trace, Entity Defaults: whichever the classification requires. Delete this section for a Pure Refactor.}

---

## Execution Plan

Follow the tasks in `{spec or plan path}` in order. Each numbered section is a phase.

### Phase order:
1. **{Phase name}** (tasks N.N–N.N) — {brief description}
2. **{Phase name}** — {brief description}
3. **Checkpoint** — run `php artisan test --parallel`
4. **{Phase name}** — {brief description}
5. **Final checkpoint** — run `php artisan test --parallel`

---

## HARD RULES — NEVER VIOLATE THESE:

- **NEVER commit or push.** The automation commits what you leave in the worktree.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.

---

## Implementation Rules

- **All new code goes in `{target namespace}`** — e.g., `app/Sync/` for sync features.
- **Routes go in `{route file}`** — e.g., `routes/sync.php`.
- **Always use `php artisan test --parallel` when running tests.** This project has 2000+ tests; parallel execution takes well under a minute.
- **To run a specific test file:** `php artisan test --parallel tests/Feature/Path/SomeTest.php`
- **To filter by name:** `php artisan test --parallel --filter=testName`
- **Never use `DB::` facade** — use Eloquent models and relationships.
- **Use PHP 8 constructor promotion** in all new classes.
- **Add explicit return types** to all methods.
- {Feature-specific rules}

---

## Success Criteria

- [ ] {Concrete, verifiable criterion}
- [ ] {Another criterion}
- [ ] All tests pass (`php artisan test --parallel`)
- [ ] `.execute-handoff.md` written
- [ ] No new composer dependencies (unless specified)

---

## Do Not

- Do NOT commit or push.
- Do NOT run Pint.
- Do NOT run destructive database commands.
- Do NOT put code outside the designated namespace.
- {Feature-specific prohibitions}

---

## Post-Execution Retro (authored by the REVIEWER, not the executor)

> The executor must NOT fill this in — leave the `{placeholder}` values untouched. After the run, the human reviewer reconstructs this section from the commit trail at archive time (the `snapshot:` commit, the `cleanup:`/`fix:` follow-ups, any phased slices) and moves both plan + prompt to `completed/`. See `docs/architect-workflow.md` → Post-Execution Review Protocol.

- **Attempts:** {1 (clean) / N (root cause of failures)}
- **Tests added:** {count}
- **Prompt improvements for next time:** {what to add/change}
- **Steering updates needed:** {yes/no, what}
