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

# Prism Exercise Families — Implementation Prompt

## Feature Classification

- [ ] **Pure Refactor**
- [ ] **Isolated Logic**
- [ ] **Integrated Feature**
- [x] **Data Shape Change** — a new table and a new key in the `/changes` response. Consumer Impact Trace and Entity Defaults are below; migration safety is stated under Traces.

## Before You Start

Read these files in order.

### 1. Steering (project rules — always follow)
```
docs/antigravity-steering.md            → executor rules: worktree, testing, handoff, data integrity
.claude/rules/safe-operations.md        → files to never touch, command safety, artisan safety
.claude/rules/project-conventions.md    → architectural principles for this project
.claude/rules/laravel-boost.md          → Laravel framework conventions (PHP style, testing)
.claude/rules/sync-api-context.md       → sync API layout
```

### 2. Feature Spec (what to build)
```
docs/plans/prism-exercise-families-plan.md   → requirements and decisions; this prompt is what you execute
```

### 3. Existing Code to Understand (read before modifying)
```
app/Sync/Controllers/PrismChangesController.php, routes/sync.php
tests/Feature/Sync/Prism/PrismChangesTest.php, tests/Feature/Sync/Prism/PrismWriteThrottleTest.php
```

### 4. Reference (the pattern to mirror, do not change these)
```
app/Sync/Actions/UpsertPrismExercisesAction.php, app/Sync/Controllers/PrismExerciseController.php
app/Sync/Requests/StorePrismExercisesRequest.php, app/Sync/Models/PrismExerciseOverlayRecord.php
tests/Feature/Sync/Prism/PrismExercisesStoreTest.php  → style model for the new test file
```

---

## What You're Building

A gym-scoped store for Prism exercise families: a new table, a write route, and an `exercise_families` list in the Prism changes feed. Logger stores `record` opaquely and never reads its fields.

End state: a family written by one device reaches another through `/changes`, a removal reaches it as a tombstone, and a re-upsert restores it.

This is purely additive. Nothing is removed or consolidated: the plan decided to mirror the per-table pattern rather than abstract it, because sharing it would touch seven working feeds for no behavior change. Every existing route, key and table behaves as it does today.

---

## Traces

### Consumer Impact Trace (closed list)

| Site | Disposition |
|---|---|
| `app/Sync/Controllers/PrismChangesController.php` | **Change**, the only reader of the new table (Milestone 2) |
| `app/Sync/Actions/UpsertPrismExerciseFamiliesAction.php` | **New**, the only writer of the new table (Milestone 1) |
| `tests/Feature/Sync/Prism/PrismChangesTest.php::test_changes_query_count_is_flat_under_growing_data` | **Change**: the feed gains 3 constant queries, so the cap goes from 22 to 25 (Milestone 2) |
| Other feed tests (the rest of `PrismChangesTest`, `InterferenceDismissalChangesTest`, `PrismCommentsTest`) | **Leave**: they use `assertJson` and `assertJsonStructure`, which tolerate a new key. No Prism test uses `assertExactJson` |
| Prism client `src/services/syncService.js` (other repository) | **Leave**: reads named keys, so the extra key is ignored until Prism connects. The Athlete app does not use the Prism routes |
| `app/Sync/Models/Gym.php`, `app/Console/Commands/ImportPrismExerciseOverlay.php`, `docs/sync/sync-api-operations-and-monitoring.md` | **Leave**: no relations to Prism child tables, overlay-only, and no Prism route list |
| `app/Providers/AppServiceProvider.php`, `config/rate_limits.php`, `bootstrap/app.php` | **Leave**: the `prism-write` limiter and the JSON error rendering already apply to the new route |
| `database/factories/` | **Leave**: no Prism model has a factory; tests use `::create()` |

Display-time interpreters of `record`: none in Logger.

### Entity Defaults

```
id          bigint, auto
gym_id      string, required, from the route
family_id   string, required, unique together with gym_id
record      json, array cast; the sent object on an upsert, null only on a tombstone
device_id   string(36), from the X-Device-Id header, null when no header was sent
deleted_at  timestamp(6), datetime cast, null when live, set on a tombstone
created_at  timestamp(6)
updated_at  timestamp(6)
```

### Migration safety

This migration runs on production automatically the moment the PR merges. It is safe to auto-apply: it creates one new table and touches no existing table, row or column. `down()` drops only that table. Its index width matches `prism_exercise_overlay` (255 + 255), which already runs on MySQL. The `gym_id` foreign key cascades on delete, like its siblings. `deleted_at` is a plain column, not `SoftDeletes`, so tombstones stay in the feed.

### Invariants carried over from the exercises path

The new Action keeps all of these from `UpsertPrismExercisesAction`:

1. Both lists empty: return `Carbon::now()` without touching the database.
2. Upsert rows `json_encode` the record, because `Model::upsert` bypasses casts.
3. Both writes use `Model::upsert` with unique key `['gym_id', 'family_id']` and update columns `['record', 'device_id', 'deleted_at']`, so `updated_at` is refreshed on every write.
4. An upsert sets `deleted_at` to null, which restores a tombstoned family.
5. A removal writes `record` null and `deleted_at` as `$now->format('Y-m-d H:i:s.u')`. It creates a tombstone even for a family the server has never seen.
6. Upserts run before removals, so a `family_id` in both lists ends up tombstoned.
7. The return value is the latest of `max('updated_at')` and `max('deleted_at')` over the touched `family_id`s in that gym, falling back to `$now` when both are null.

---

## Execution Plan

Create every new file directly at the exact path given. Do not use `php artisan make:`, because the migration needs this exact filename.

### Milestone 1 — Table, model, write route

**1.1 Migration.** New file `database/migrations/2026_11_05_000000_create_prism_exercise_families_table.php`: the overlay migration (`2026_10_20_203704_create_prism_exercise_overlay_table.php`) line for line, with exactly two substitutions: table `prism_exercise_overlay` becomes `prism_exercise_families`, and column `exercise_id` becomes `family_id`, including in the `unique(['gym_id', 'family_id'])` key. `down()` is `Schema::dropIfExists('prism_exercise_families');`.

**1.2 Model.** New file `app/Sync/Models/PrismExerciseFamily.php`, class `App\Sync\Models\PrismExerciseFamily extends Model`, the same structure as `PrismExerciseOverlayRecord`:
`$table = 'prism_exercise_families'`, `family_id` in place of `exercise_id` in `$fillable`, and the same `casts()` and `gym()`. No `SoftDeletes`, no factory.

**1.3 Form Request.** New file `app/Sync/Requests/StorePrismExerciseFamiliesRequest.php`, `StorePrismExercisesRequest` with its two `exercise_id` rule keys renamed to `upserts.*.family_id` and `removals.*.family_id`. All other rules stay as they are, and there are no rules below `record`.

**1.4 Action.** New file `app/Sync/Actions/UpsertPrismExerciseFamiliesAction.php`, class `UpsertPrismExerciseFamiliesAction`:

```php
public function execute(string $gymId, array $upserts, array $removals, ?string $deviceId): Carbon
```

`Carbon` is `Illuminate\Support\Carbon`. It has the same structure as `UpsertPrismExercisesAction`, with `family_id` in place of `exercise_id` and `PrismExerciseFamily` in place of the overlay model, and it keeps invariants 1–7 above. PHPDoc shapes: `array<int, array{family_id: string, record: array<string, mixed>}>` for `$upserts`, `array<int, array{family_id: string}>` for `$removals`. Name the tracking variable `$touchedFamilyIds`.

**1.5 Controller.** New file `app/Sync/Controllers/PrismExerciseFamilyController.php`, class `PrismExerciseFamilyController`, using `ValidatesGym`, with a promoted constructor parameter `private readonly UpsertPrismExerciseFamiliesAction $upsertExerciseFamiliesAction`:

```php
public function store(StorePrismExerciseFamiliesRequest $request, string $gym): JsonResponse
```

The body matches `PrismExerciseController::store`: `findGymOrFail($gym)`, device id from `$request->attributes->get('device_id')`, `validated('upserts', [])` and `validated('removals', [])`, the Action, then `{ "status": "ok", "updated_at": <toIso8601String> }`.

**1.6 Route.** In `routes/sync.php`, add the import `use App\Sync\Controllers\PrismExerciseFamilyController;` directly before the `PrismInterferenceDismissalController` import. Inside the existing `['auth:sanctum', 'throttle:prism-write', 'device-id', 'log-sync-request']` group, directly after the `/prism/{gym}/exercises` line, add:

```php
Route::post('/prism/{gym}/exercise-families', [PrismExerciseFamilyController::class, 'store']);
```

**1.7 Tests.** New file `tests/Feature/Sync/Prism/PrismExerciseFamiliesStoreTest.php`, class `PrismExerciseFamiliesStoreTest`, using `RefreshDatabase`. `setUp()` creates `Gym` `the-cove`. Unless a case says otherwise, each test uses `Sanctum::actingAs` on a factory `User`, sends `X-Device-Id`, and POSTs to `/api/sync/prism/the-cove/exercise-families`.

| Test | Input | Expected |
|---|---|---|
| `test_upsert_stores_family_and_keeps_unknown_record_fields` | Upsert `burpees`, record `['id' => 'burpees', 'name' => 'Burpees', 'frequencyCap' => ['count' => 2, 'period' => 'week'], 'futureField' => ['nested' => true]]`, device `dev-uuid-1` | 200, `status` ok, `updated_at` present; one row with that exact record, `gym_id` `the-cove`, `device_id` `dev-uuid-1`, `deleted_at` null |
| `test_removal_writes_tombstone` | Existing row `burpees` from device `dev-uuid-old`; removal from `dev-uuid-2` | 200; one row, `record` null, `deleted_at` not null, `device_id` `dev-uuid-2` |
| `test_re_upsert_clears_tombstone` | Removal of `burpees` with no prior row, then upsert with record `['restored' => true]` from `dev-uuid-2` | One row, `deleted_at` null, that record, `device_id` `dev-uuid-2` |
| `test_upserts_and_removals_apply_in_one_request` | Existing row `old-family`; upsert `burpees` and removal `old-family` | Two rows: `burpees` live, `old-family` tombstoned |
| `test_family_in_both_lists_ends_tombstoned` | Upsert and removal of `burpees` in one request | One row, `record` null, `deleted_at` not null |
| `test_empty_lists_return_ok_and_write_nothing` | `upserts: []`, `removals: []` | 200, `status` ok, `updated_at` present; 0 rows |
| `test_missing_device_header_stores_null_device_id` | Upsert with no `X-Device-Id` header | 200; `device_id` null |
| `test_same_family_id_is_separate_per_gym` | Also create `Gym` `flagship`; upsert `burpees` to each gym with different records | Two rows, each with its gym's record |
| `test_unknown_gym_returns_404` | POST to `/api/sync/prism/nonexistent-gym/exercise-families`, empty lists | 404, `status` error, `message` `Gym not found.` |
| `test_unauthenticated_request_returns_401` | No `actingAs`, empty lists | 401, `status` error, `message` `Unauthenticated.` |
| `test_missing_upserts_returns_422` | Payload `['removals' => []]` | 422, `status` error |
| `test_missing_removals_returns_422` | Payload `['upserts' => []]` | 422, `status` error |
| `test_non_array_record_returns_422` | Upsert `burpees` with `record` `'not-an-array'` | 422; 0 rows |
| `test_missing_family_id_returns_422` | Upsert with only `record` | 422; 0 rows |
| `test_family_id_over_255_characters_returns_422` | Upsert with `family_id` `str_repeat('a', 256)` | 422; 0 rows |

Add to `tests/Feature/Sync/Prism/PrismWriteThrottleTest.php`:

`test_exercise_family_writes_are_throttled`: `config(['rate_limits.prism_write.per_minute' => 2])`, `Sanctum::actingAs` a factory `User`, then three POSTs to `/api/sync/prism/the-cove/exercise-families` with empty lists and no `travel()`. The first two return 200 and the third returns 429 with `status` error.

**Checkpoint:** `php artisan test --parallel`.

### Milestone 2 — Changes feed

**2.1 `app/Sync/Controllers/PrismChangesController.php`.** Make exactly these five edits and change nothing else except the one comment renumber in (b):

a. Import `App\Sync\Models\PrismExerciseFamily` directly before the `PrismExerciseOverlayRecord` import.

b. After the `// 7. Comments` block, add `// 8. Exercise families (records + tombstones)`, in the same form as the `// 3. Exercises` block: filter on `gym_id`, apply `updated_at >= $since` when `$since` is not null, and assign to `$exerciseFamilies`. Each row:

```php
'family_id' => $family->family_id,
'record' => $family->record,
'deleted_at' => $family->deleted_at?->toIso8601String(),
'updated_at' => $family->updated_at->toIso8601String(),
```

Renumber the cursor comment from `// 8.` to `// 9.`.

c. **`max(updated_at)`:** add `$maxFamilyUpdated = PrismExerciseFamily::where('gym_id', $gym)->max('updated_at');` after `$maxCommentUpdated`, and add `$maxFamilyUpdated ? Carbon::parse($maxFamilyUpdated) : null,` as the last entry of `$updatedInstants`.

d. **`max(deleted_at)`:** add `$rawMaxFamilyDeleted`, in the same form as `$rawMaxDestinationDeleted` (`whereNotNull('deleted_at')`, then `max('deleted_at')`), directly after it, and add `$rawMaxFamilyDeleted ? Carbon::parse($rawMaxFamilyDeleted) : null,` as the last entry of `$deletedInstants`.

e. Add `'exercise_families' => $exerciseFamilies,` as the last key of the response, after `'comments'`.

**2.2 New tests in `tests/Feature/Sync/Prism/PrismChangesTest.php`.** Import `App\Sync\Models\PrismExerciseFamily`. Set timestamps the way the existing tests do: `create()`, then `PrismExerciseFamily::where('id', $row->id)->update([...])`. All instants are `Carbon::parse('2026-09-01 HH:00:00', config('app.timezone'))`, and the feed is read at `/api/sync/prism/the-cove/changes`.

| Test | Setup | Expected |
|---|---|---|
| `test_changes_carry_exercise_families_with_exact_row_shape` | Live `burpees`, record `['id' => 'burpees', 'name' => 'Burpees']`, `updated_at` 12:00 | `exercise_families` has exactly one row, `assertEquals` to `family_id`, `record`, `deleted_at` null, `updated_at` as ISO 8601. No `device_id` key |
| `test_changes_since_filters_exercise_families` | `before` at 10:00, `boundary` at 12:00, `after` at 14:00; `since` 12:00 | Returned `family_id`s are exactly `boundary` and `after` |
| `test_exercise_family_tombstone_is_delivered_in_delta` | `removed` with `record` null, `updated_at` and `deleted_at` 14:00; `since` 12:00 | One row, `record` null, `deleted_at` and `updated_at` both 14:00 as ISO 8601 |
| `test_cursor_advances_on_exercise_family_update` | A cell with `updated_at` 10:00 and a family with `updated_at` 11:00; read the feed; set the family's `updated_at` to 12:00; read again | Cursor is 11:00, then 12:00 |
| `test_cursor_advances_on_exercise_family_removal` | A cell with `updated_at` 10:00; a family with `updated_at` 10:00 and `deleted_at` 15:00 | Cursor is 15:00 |
| `test_changes_exclude_other_gyms_exercise_families` | Create `Gym` `flagship`; family `other` in `flagship`, family `mine` in `the-cove` | The `the-cove` feed returns only `mine` |
| `test_empty_gym_returns_empty_exercise_families` | No rows | `assertSame([], $response->json('exercise_families'))` |
| `test_exercise_family_round_trips_from_write_route_to_feed` | POST upsert of `burpees`, record `['id' => 'burpees', 'futureField' => 'kept']`; read the feed; POST removal of `burpees`; read the feed | First read: that exact record, `deleted_at` null. Second read: one row, `record` null, `deleted_at` not null |

**2.3 Change `test_changes_query_count_is_flat_under_growing_data`.** This is the only existing test that changes. The plan names it and the owner was told.
- In the small dataset, after the overlay loop, create 2 `PrismExerciseFamily` rows (`family-small-{$i}`, record `['name' => "Family {$i}"]`, device `dev-1`).
- In the large dataset, after the overlay loop, create 30 (`family-large-{$f}`, record `['name' => "Family {$f}"]`, device `dev-1`, `deleted_at` `($f % 5 === 0) ? now() : null`).
- Change `assertLessThanOrEqual(22, $smallCount)` to `assertLessThanOrEqual(25, $smallCount)`.
- In the comment above it, change `<= 22` to `<= 25` and add that exercise families add 3 constant queries: rows, max updated_at, max deleted_at.
- Keep `assertSame($smallCount, $largeCount)` unchanged.

**Final checkpoint:** `php artisan test --parallel`. `artisan test` rejects `--no-interaction`, so leave it off that command.

### Milestone 3 — Sweep and handoff

Run the End-of-Run Cleanup Sweep (`docs/antigravity-steering.md` §4), then these two greps:
- `grep -n "PrismExerciseFamily::" app/Sync/Controllers/PrismChangesController.php` shows exactly 3 lines: the feed rows, `max('updated_at')`, and the start of the `deleted_at` query.
- `grep -rn "exercise_id" app/Sync/Actions/UpsertPrismExerciseFamiliesAction.php app/Sync/Controllers/PrismExerciseFamilyController.php app/Sync/Requests/StorePrismExerciseFamiliesRequest.php app/Sync/Models/PrismExerciseFamily.php` shows nothing.

Write `.execute-handoff.md`. Under **What changed**, say that the Prism changes feed gains an `exercise_families` key and that a new write route exists, because both are shapes another app will depend on. Also say that the cross-app `exercise_families` contract fixture in the parent `squirby` repository is left for the owner. Under **Migrations**, say it creates one new empty table and touches no existing data.

---

## HARD RULES — NEVER VIOLATE THESE:

- **NEVER commit or push.** The automation commits what you leave in the worktree.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.

---

## Implementation Rules

- **All new code goes in `app/Sync/`**, in the folders named above.
- **Routes go in `routes/sync.php`.**
- **Always use `php artisan test --parallel` when running tests.** This project has 2000+ tests; parallel execution takes well under a minute.
- **To run a specific test file:** `php artisan test --parallel tests/Feature/Path/SomeTest.php`
- **To filter by name:** `php artisan test --parallel --filter=testName`
- **Never use `DB::` facade** — use Eloquent models and relationships. The existing `DB::` query-log calls in the query-count test stay.
- **Use PHP 8 constructor promotion** in all new classes.
- **Add explicit return types** to all methods.
- **`record` is opaque:** read it nowhere and validate nothing inside it.

---

## Success Criteria

- [ ] `prism_exercise_families` exists, matching the Entity Defaults and 1.1
- [ ] `POST /api/sync/prism/{gym}/exercise-families` is in the `throttle:prism-write` group
- [ ] `/changes` returns `exercise_families`, and the table is in both high-water-marks (Milestone 3 grep)
- [ ] All 15 tests in `PrismExerciseFamiliesStoreTest`, the throttle test and the 8 new `PrismChangesTest` tests exist under the names above and pass
- [ ] The query-count test seeds families, its cap is 25, and its `assertSame` flatness check is unchanged
- [ ] All tests pass (`php artisan test --parallel`)
- [ ] `git status` shows only: the 5 new `app/` and migration files plus the new test file, `routes/sync.php`, `PrismChangesController.php`, `PrismChangesTest.php` and `PrismWriteThrottleTest.php`
- [ ] `.execute-handoff.md` written
- [ ] No new composer dependencies

---

## Do Not

- Do NOT commit or push.
- Do NOT run Pint.
- Do NOT run destructive database commands.
- Do NOT put code outside the designated namespace.
- Do NOT validate anything inside `record`, including the cap values.
- Do NOT add family membership to anything; it stays on the exercise record.
- Do NOT use `SoftDeletes` on the model.
- Do NOT change any existing feed list, key or route, or refactor the repeated blocks in `PrismChangesController`.
- Do NOT extract a shared base class or trait from the exercises Action.
- Do NOT edit `bootstrap/app.php`, `config/`, or any Prism client code.
- Do NOT add a test that pins request trimming, empty-string-to-null, or `{}` becoming `[]`. The plan leaves that behavior as it is.
- Do NOT attempt the `contracts/prism-logger` fixture. It lives in the parent `squirby` repository, and the owner adds it.

---

## Post-Execution Retro (authored by the REVIEWER, not the executor)

> The executor must NOT fill this in — leave the `{placeholder}` values untouched. After the run, the human reviewer reconstructs this section from the commit trail at archive time (the `snapshot:` commit, the `cleanup:`/`fix:` follow-ups, any phased slices) and moves both plan + prompt to `completed/`. See `docs/architect-workflow.md` → Post-Execution Review Protocol.

- **Attempts:** {1 (clean) / N (root cause of failures)}
- **Tests added:** {count}
- **Prompt improvements for next time:** {what to add/change}
- **Steering updates needed:** {yes/no, what}

---

## Hard Gate

- **Decisions gate:** No decision, option or "if appropriate" is left to the executor. File paths, signatures, rules, test cases and edit positions are all fixed above.
- **Verification gate:** No behavior here is only provable in the app, because there is no web UI change. Every behavior has a wire test in Milestone 1 or 2, and nothing is listed for the UI Verifier. MySQL behavior can't be proven on SQLite; the table copies the shape of `prism_exercise_overlay`, which already runs on MySQL. After the deploy, `php artisan migrate:status` should show the migration as Ran.
