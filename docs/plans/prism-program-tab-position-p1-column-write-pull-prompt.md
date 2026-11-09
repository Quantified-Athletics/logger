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

# Prism program `tab_position` (tab order sync) — Implementation Prompt

## Feature Classification

- [x] **Data Shape Change**: a new column on `prism_programs`, a new optional field on the program write
  request, and a new key on every `programs[]` row of the `/changes` pull. The Consumer Impact Trace is below.
  The migration adds one nullable column, does nothing to existing rows and has a `down()` that drops the
  column, so it is safe to auto-apply on deploy.

## Before You Start

### 1. Steering (project rules: always follow)
```
docs/antigravity-steering.md            → executor rules: worktree, testing, handoff, data integrity
.claude/rules/safe-operations.md        → files to never touch, command safety, artisan safety
.claude/rules/project-conventions.md    → architectural principles (migrations auto-run on deploy)
.claude/rules/laravel-boost.md          → Laravel framework conventions (PHP style, testing)
.claude/rules/sync-api-context.md       → all sync code stays in app/Sync/
```

### 2. Feature Spec
```
docs/plans/prism-program-tab-position-plan.md   → the approved plan (issue #9)
docs/plans/prism-program-partial-updates.md §5  → program wire contract (absent ⇒ untouched, null ⇒ clear)
```

### 3. Existing Code to Understand (read before modifying)
```
database/migrations/2026_10_21_102125_add_archived_at_to_prism_programs_table.php → the migration to mirror
app/Sync/Models/PrismProgram.php                   → $fillable gains tab_position
app/Sync/Requests/StorePrismProgramRequest.php     → rules() gains tab_position; metadata 503 guard untouched
app/Sync/Actions/UpsertPrismProgramAction.php      → WRITABLE_FIELDS gains tab_position
app/Sync/Controllers/PrismChangesController.php    → program row map gains tab_position
tests/Feature/Sync/Prism/PrismProgramStoreTest.php → write tests go here (helpers pushProgram(), seedProgram())
tests/Feature/Sync/Prism/PrismChangesTest.php      → pull tests go here; one existing exact-shape assert changes
```

### 4. Reference (don't change)
```
app/Sync/Controllers/PrismProgramController.php::storeProgram → passes $request->validated() (+ device_id) to the action; no change needed
```

---

## What You're Building

Prism lets coaches drag program tabs into their own order. Each program row gets one opaque sort key,
`tab_position` (a short ASCII string such as `"a0"`, `"a0V"`, `"b"`, computed by Prism). Logger stores it
exactly as sent and returns it exactly as stored. It never sorts by it, interprets it or changes its case.

End state: `POST /api/sync/prism/{gym}/programs/{program_uuid}` accepts an optional `tab_position` (string of at
most 64 ASCII characters, or `null`). Present ⇒ overwrite. `null` ⇒ clear. Absent ⇒ untouched. It follows the
existing per-field last-writer-wins, and every accepted write bumps `updated_at` as it already does.
`GET /api/sync/prism/{gym}/changes` returns `tab_position` on every `programs[]` row, `null` when never set.
Nothing else changes. Creating a program still needs `name` and `week_count`, so a `tab_position`-only write to
an unknown program stays a 422. The `metadata` 503 guard stays as it is.

---

## Traces

### Consumer Impact Trace (closed list, from `grep -rln "PrismProgram\b\|prism_programs"` over `app routes tests database`)

| Site | Disposition |
|---|---|
| `database/migrations/` (new file) | **Add** the column (Milestone 1). |
| `app/Sync/Models/PrismProgram.php` | **Change**: `$fillable` gains `'tab_position'` after `'archived_at'`. No cast: it stays a plain string. |
| `app/Sync/Requests/StorePrismProgramRequest.php` | **Change**: add one rule (below). `prepareForValidation()` stays unchanged. |
| `app/Sync/Actions/UpsertPrismProgramAction.php` | **Change**: `WRITABLE_FIELDS = ['name', 'week_count', 'start_date', 'archived_at', 'tab_position']`. No other line changes. |
| `app/Sync/Controllers/PrismChangesController.php` | **Change**: the program row map gains `'tab_position' => $program->tab_position,` between `'archived_at'` and `'updated_at'`. |
| `app/Sync/Controllers/PrismProgramController.php` | **Unchanged**: it forwards `validated()`, so the new key flows through. The response stays `{status, updated_at}`. |
| `tests/Feature/Sync/Prism/PrismChangesTest.php::test_cold_start_returns_full_gym_state` | **Change, one line**: its `assertEquals([...], $programs[0])` lists the exact row, so add `'tab_position' => null,` after `'archived_at' => null,`. This is the only existing test that changes. It now proves "null when never set". |
| `tests/Feature/Sync/Prism/PrismChangesTest.php` (other program tests) | **Unchanged**: they read individual keys. |
| `tests/Feature/Sync/Prism/PrismProgramStoreTest.php` (existing tests) | **Unchanged**, including both `metadata` 503 tests. |
| `tests/Unit/Sync/Prism/PrismProgramTest.php`, `PrismProgramDestinationsStoreTest.php`, `PrismProgramStartDateMigrationTest.php`, `PrismProgramCellTest.php` | **Unchanged**: they don't touch the field. |
| Earlier `prism_programs` migrations | **Never edit.** |
| `docs/plans/prism-program-partial-updates.md` §5 | **Unchanged**: it is the historical design record. The plan for this issue is the record for the new field. |
| Cross-app: Prism's fake Logger and contract tests | Outside this repository. Say in the handoff that the pull row gained `tab_position` (additive). |

**Producer audit (P11):** `prism_programs.tab_position` has exactly one writer: `UpsertPrismProgramAction`,
through `fill()`/`create()` with fields filtered by `WRITABLE_FIELDS` from the validated request. No other code
writes `prism_programs`. `updated_at` is already bumped on every accepted write (`$program->updated_at =
$program->freshTimestamp()`) and on create, so `/changes` paging (`updated_at >= since`) sees a tab move with no
further change.

### Entity Defaults
Existing rows and new programs created without the field get `tab_position = NULL`. No backfill.

---

## Execution Plan

### Milestone 1: Column, model, request, action, pull

1. **Create** `database/migrations/2026_11_09_120000_add_tab_position_to_prism_programs_table.php`, with the
   same structure as `2026_10_21_102125_add_archived_at_to_prism_programs_table.php`:
   - `up()`: `Schema::table('prism_programs', ...)` with `$table->string('tab_position', 64)->nullable()->after('archived_at');`
   - `down()`: `$table->dropColumn('tab_position');`
   - Nothing else: no data statements, no index.
2. **Model** `app/Sync/Models/PrismProgram.php`: add `'tab_position'` to `$fillable` after `'archived_at'`.
   Leave `casts()` unchanged.
3. **Request** `app/Sync/Requests/StorePrismProgramRequest.php` `rules()`: add after the `archived_at` line:
   ```php
   'tab_position' => ['bail', 'sometimes', 'nullable', 'string', 'max:64', 'ascii'],
   ```
   `bail` is deliberate and is the one change from the plan's rule. Without it, an array value fails `string`
   and still reaches `ascii`, which casts the array to a string, raises a PHP "Array to string conversion"
   warning and returns a 500 instead of a 422. Don't change the other rules or `prepareForValidation()`.
4. **Action** `app/Sync/Actions/UpsertPrismProgramAction.php`: append `'tab_position'` to `WRITABLE_FIELDS`.
   Nothing else in the action changes: the create guard (name + week_count), the unique-violation retry and
   the always-bump all stay.
5. **Pull** `app/Sync/Controllers/PrismChangesController.php`, in the `// 1. Programs` map: add
   `'tab_position' => $program->tab_position,` between `archived_at` and `updated_at`. Return it as is: no
   cast, no trim, no `orderBy`.
6. **Update** `test_cold_start_returns_full_gym_state` in `PrismChangesTest.php` as the trace says (one
   added array entry).

**Checkpoint:** `php artisan test --parallel tests/Feature/Sync/Prism` passes.

### Milestone 2: Tests

Add to `tests/Feature/Sync/Prism/PrismProgramStoreTest.php`, using the existing `seedProgram()` (creates
`prog-uuid-123`: name `Spring Block`, week_count 6, start_date `2026-11-02`, archived_at `2026-10-21T10:00:00Z`)
and `pushProgram()` helpers:

| Test | Setup / call | Expected |
|---|---|---|
| `test_tab_position_is_stored_on_create_and_kept_verbatim` | `pushProgram(['name' => 'Tabbed', 'week_count' => 4, 'tab_position' => 'a0V'])` (with `Sanctum::actingAs`) | 200; `PrismProgram::first()->tab_position === 'a0V'` (case kept) |
| `test_tab_position_only_write_changes_only_that_field` | `seedProgram()`; `$before = $program->updated_at`, then `Carbon::setTestNow($before->copy()->addSeconds(5))` as in `test_every_accepted_write_bumps_updated_at_even_when_identical` (reset in `finally`); `pushProgram(['tab_position' => 'b'], 'dev-uuid-2')` | 200; `tab_position === 'b'`; name `Spring Block`, week_count 6, start_date `2026-11-02`, archived_at not null all unchanged; `updated_at` greater than before; device_id `dev-uuid-2` |
| `test_write_without_tab_position_leaves_it_untouched` | `seedProgram()`; push `['tab_position' => 'a0']`; then push `['name' => 'Renamed']` | both 200; name `Renamed`; `tab_position` still `'a0'` |
| `test_null_tab_position_clears_it` | `seedProgram()`; push `['tab_position' => 'a0']`; then push `['tab_position' => null]` | both 200; `tab_position` is null |
| `test_tab_position_of_64_ascii_characters_is_accepted` | `seedProgram()`; push `['tab_position' => str_repeat('z', 64)]` | 200; stored value equals the 64-char string |
| `test_invalid_tab_position_returns_422_and_writes_nothing` | `seedProgram()`; push `['tab_position' => 'a0']` (200); then push each of: `str_repeat('z', 65)`, `123` (int), `['a0']` (array), `'añ'` (non-ASCII) | each push `->assertStatus(422)` (the same form as `test_invalid_start_date_returns_422`); after all four, `PrismProgram::firstOrFail()->tab_position` is still `'a0'` |
| `test_tab_position_only_write_to_unknown_program_returns_422` | `Sanctum::actingAs(...)`; push `['tab_position' => 'a0']` with no seed | 422; `assertDatabaseCount('prism_programs', 0)` |

Add to `tests/Feature/Sync/Prism/PrismChangesTest.php`:

| Test | Setup / call | Expected |
|---|---|---|
| `test_changes_programs_carry_tab_position_and_a_move_appears_in_since_delta` | `Sanctum::actingAs`; create `prog-tabbed` (`tab_position` `'a0V'`) and `prog-untabbed` (no tab_position) with `PrismProgram::create`, both `updated_at` set to `$t1 = 2026-09-01 10:00:00` (app tz) via `PrismProgram::where('id', …)->update(['updated_at' => $t1])`; GET `/api/sync/prism/the-cove/changes` (header `X-Device-Id: dev-1`); then with `Carbon::setTestNow(2026-09-01 14:00:00)` (reset in `finally`) POST `/api/sync/prism/the-cove/programs/prog-untabbed` with `['tab_position' => 'b']`; then GET `changes?since=` urlencoded `2026-09-01 12:00:00` ISO-8601 | Cold pull: `prog-tabbed` has `'a0V'`, `prog-untabbed` has `null` (the key exists: `assertArrayHasKey`). Delta pull: exactly 1 program, `prog-untabbed`, `tab_position === 'b'`. |

**Checkpoint:** `php artisan test --parallel` (full suite; required by `CLAUDE.md` rule 3 and
`project-conventions.md` for a migration) passes.

### Milestone 3: Cleanup sweep and handoff

1. Run the End-of-Run Cleanup Sweep (`docs/antigravity-steering.md` §4) on the files you touched.
2. Grep gate: `grep -rn "tab_position" app database/migrations` shows exactly these sites and no others: the
   new migration (up + down), `PrismProgram.php` `$fillable`, `StorePrismProgramRequest.php` rule,
   `UpsertPrismProgramAction.php` `WRITABLE_FIELDS`, `PrismChangesController.php` row map.
3. Write `.execute-handoff.md`. Under **What changed**, say the pull's program rows gained `tab_position` so
   Prism's contract tests should pick it up. Under **Migrations**, say it adds one nullable column, leaves
   existing rows untouched and is reversible.

---

## HARD RULES (never violate)

- **NEVER commit or push.** The automation commits what you leave in the worktree.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.

## Implementation Rules

- All code changes stay in `app/Sync/` plus the one new migration. No route changes (`routes/sync.php` is untouched).
- **Always use `php artisan test --parallel` when running tests.**
- **To run a specific test file:** `php artisan test --parallel tests/Feature/Path/SomeTest.php`
- **To filter by name:** `php artisan test --parallel --filter=testName`
- **Never use the `DB::` facade.** Use Eloquent models.
- **Add explicit return types** to all methods (new test methods return `void`).

## Success Criteria

- [ ] The new migration exists with `string('tab_position', 64)->nullable()->after('archived_at')` and a `down()` that drops it, and with no data statements.
- [ ] `tab_position` is in `PrismProgram::$fillable`, in `WRITABLE_FIELDS`, in the request rules (exactly `['bail', 'sometimes', 'nullable', 'string', 'max:64', 'ascii']`) and in the `/changes` program row.
- [ ] The 7 new store tests and 1 new changes test above exist with those names and pass. `test_cold_start_returns_full_gym_state` includes `'tab_position' => null`.
- [ ] Both `metadata` 503 tests pass unchanged.
- [ ] The Milestone 3 grep gate matches exactly the listed sites.
- [ ] All tests pass (`php artisan test --parallel`).
- [ ] `.execute-handoff.md` written.
- [ ] No new composer dependencies.

## Do Not

- Do NOT commit or push.
- Do NOT run Pint.
- Do NOT run destructive database commands, and do NOT run `php artisan migrate` by hand. Tests use in-memory SQLite.
- Do NOT edit any existing migration.
- Do NOT sort, order, trim, lowercase or otherwise interpret `tab_position` anywhere, and add no index for it.
- Do NOT relax the create guard: a `tab_position`-only write to an unknown program stays a 422.
- Do NOT touch the `metadata` 503 guard in `prepareForValidation()`.
- Do NOT add a new row type, a gym-level ordering list or a new endpoint.
- Do NOT change any existing test other than the one line in `test_cold_start_returns_full_gym_state`.

---

## Decisions gate

Did I leave any decision, option, "or", "if appropriate" or "preferred/acceptable" to the executor? No. The
migration name, column type and position, rule list (including `bail` and why), constant contents, row key
position, test names, inputs and expected outputs are all fixed. The 422 assertion form is fixed as
`assertStatus(422)` followed by a check that the stored value is unchanged.

## Verification gate

Nothing here can only be proven in the app: this is an API-only change with no UI. Every behavior (store,
untouched when absent, null clears, 64 accepted, 65/int/array/non-ASCII rejected, unknown program 422, pull
returns the value or null, a move appears in the `since` delta) has a feature test above. There is nothing
for the UI Verifier. The migration's MySQL behavior is proven only by the deploy (`migrate:status` shows it
**Ran**). It is a plain nullable `ADD COLUMN`.

---

## Post-Execution Retro (authored by the REVIEWER, not the executor)

> The executor must NOT fill this in — leave the `{placeholder}` values untouched. After the run, the human reviewer reconstructs this section from the commit trail at archive time (the `snapshot:` commit, the `cleanup:`/`fix:` follow-ups, any phased slices) and moves both plan + prompt to `completed/`. See `docs/architect-workflow.md` → Post-Execution Review Protocol.

- **Attempts:** {1 (clean) / N (root cause of failures)}
- **Tests added:** {count}
- **Prompt improvements for next time:** {what to add/change}
- **Steering updates needed:** {yes/no, what}
