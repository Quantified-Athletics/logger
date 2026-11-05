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

# Prism exercise limits: rows, write route and feed — Implementation Prompt

## Feature Classification

- [x] **Data Shape Change** — three new tables and three new keys in an API response.

## Before You Start

Read in order:
```
docs/antigravity-steering.md, .claude/rules/safe-operations.md, project-conventions.md, laravel-boost.md, sync-api-context.md
docs/plans/prism-exercise-limits-plan.md                       → findings and the MySQL check block
app/Sync/Controllers/PrismChangesController.php                → gains sections and cursor entries
routes/sync.php                                                → gains one route
tests/Feature/Sync/Prism/PrismChangesTest.php, PrismWriteThrottleTest.php → gain tests
```
Patterns to mirror, not to change:
```
app/Sync/Controllers/PrismInterferenceDismissalController.php  → controller shape
app/Sync/Requests/StorePrismInterferenceDismissalsRequest.php  → Form Request shape
app/Sync/Actions/UpsertPrismExercisesAction.php                → upsert and tombstone
app/Sync/Actions/UpsertPrismCommentsAction.php                 → DB::transaction
app/Sync/Models/PrismExerciseOverlayRecord.php                 → model shape
database/migrations/2026_10_20_203704_create_prism_exercise_overlay_table.php → migration shape
```

## What You're Building

Three gym-scoped tables for exercise families, family membership and exercise caps; one write route that applies all three in a single database transaction; three new keys in the Prism changes feed. Logger only stores rows: it never evaluates a cap or resolves inheritance. This is additive, so nothing is removed and no existing table, route or response key changes.

## Traces

### Consumer Impact Trace (closed list)

| Shape | Reader | Disposition |
|---|---|---|
| `/changes`, three new keys | Prism `src/services/syncService.js` (other repo) reads named keys | None: ignored until Prism connects |
| `/changes` in tests | `PrismChangesTest`, `InterferenceDismissalChangesTest`: `assertJson`/`assertJsonStructure` allow extra keys, and `tests/Feature/Sync` has no `assertExactJson` | Only the query limit in 3.3 changes |
| New route | `LogSyncRequest`, `ReplayFailedRequests` are path-generic; `config/cors.php` covers `api/*` | None |
| New tables | Nothing in `app/`, `config/` or `docs/sync` lists Prism tables | None |

### Entity Defaults

Every table also has `id()`, `gym_id` string NOT NULL, `device_id` `string(36)` nullable, `deleted_at` `timestamp(6)` nullable, `timestamps(6)`. No column has a database default.

```text
prism_exercise_families        family_id string NOT NULL utf8mb4_bin | name string NOT NULL
                               cap_count unsignedInteger nullable | cap_period string nullable
prism_exercise_family_members  exercise_id string NOT NULL | family_id string NOT NULL utf8mb4_bin, no FK
                               is_member boolean NOT NULL
prism_exercise_caps            exercise_id string NOT NULL
                               cap_count unsignedInteger nullable | cap_period string nullable

Live row                       values as sent; deleted_at null
Tombstone over an existing row stored values kept; only device_id, deleted_at, updated_at change
Tombstone with no prior row    families: name '', cap_count null, cap_period null
                               members:  is_member false
                               caps:     cap_count null, cap_period null
```

### Migration safety

These run on production the moment the PR merges. They are three `CREATE TABLE`s: no existing table or row is touched, and `down()` drops only the new table. Each table's one foreign key is `gym_id`, matching `prism_exercise_overlay`, with cascade on delete. No `SoftDeletes`: tombstones must stay in the feed. SQLite proves none of this, hence Milestone 4.

## Execution Plan

### Milestone 1: Tables and models

Write these files directly, not with `make:`: the paths and timestamps are fixed.

**1.1 Migrations in `database/migrations/`.** Shape each like the overlay migration, with columns per Entity Defaults, the `gym_id` foreign key, `$table->index(['gym_id', 'updated_at'])`, and `Schema::dropIfExists` in `down()`.

| File | Unique |
|---|---|
| `2026_11_05_100000_create_prism_exercise_families_table.php` | `['gym_id', 'family_id']` |
| `2026_11_05_100100_create_prism_exercise_family_members_table.php` | `['gym_id', 'exercise_id', 'family_id']`, named `prism_family_members_gym_exercise_family_unique` |
| `2026_11_05_100200_create_prism_exercise_caps_table.php` | `['gym_id', 'exercise_id']` |

`family_id` is exactly this, on the column only (a table-level collation breaks the `gym_id` foreign key):

```php
$table->string('family_id')->collation(Schema::getConnection()->getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'binary');
```

The explicit index name is required: the default is 65 characters, MySQL's limit is 64, and only the deploy would fail. Keep every string at the default 255: the members key is 3060 of InnoDB's 3072 bytes.

**1.2 Models in `app/Sync/Models/`.** Shape each like `PrismExerciseOverlayRecord`: `$table`, `$fillable` (every column except `id` and timestamps), `casts()`, `gym(): BelongsTo`. No factories, no `SoftDeletes`.

| Class | Table | `casts()` |
|---|---|---|
| `PrismExerciseFamily` | `prism_exercise_families` | `cap_count` integer, `deleted_at` datetime |
| `PrismExerciseFamilyMember` | `prism_exercise_family_members` | `is_member` boolean, `deleted_at` datetime |
| `PrismExerciseCap` | `prism_exercise_caps` | `cap_count` integer, `deleted_at` datetime |

**Checkpoint:** `php artisan test --parallel`.

### Milestone 2: Write route

**2.1 `app/Sync/Requests/StorePrismExerciseLimitsRequest.php`.** Dismissal-request shape, with these array-style rules:

- `present`, `array`: `families`, `members`, `caps`, and `.upserts`, `.removals` under each.
- `required`, `string`, `max:255`: `family_id` on `families.upserts.*`, `families.removals.*`, `members.upserts.*`, `members.removals.*`; `exercise_id` on `members.upserts.*`, `members.removals.*`, `caps.upserts.*`, `caps.removals.*`; and `families.upserts.*.name`.
- `required`, `boolean:strict`: `members.upserts.*.is_member`.
- `nullable`, `string`, `max:36`: `device_id`.
- Cap rules through one private method, `capRules(string $prefix): array`, spread in for `families.upserts.*` and `caps.upserts.*`. It returns exactly:

```php
"{$prefix}.cap_count" => ['present', 'nullable', 'integer:strict', 'min:1', 'max:4294967295', "required_with:{$prefix}.cap_period"],
"{$prefix}.cap_period" => ['present', 'nullable', 'in:week,program', "required_with:{$prefix}.cap_count"],
```

**2.2 `app/Sync/Actions/UpsertPrismExerciseLimitsAction.php`.** With PHPDoc array shapes for each group:

```php
public function execute(string $gymId, array $families, array $members, array $caps, ?string $deviceId): Carbon
```

- Take `$now = Carbon::now()` (`Illuminate\Support\Carbon`) once, format it once as `Y-m-d H:i:s.u`, and return `$now`. Every touched row gets that `updated_at`, and removals get it as `deleted_at` too, so no re-query is needed.
- One `DB::transaction` closure calls `writeFamilies`, `writeMembers`, `writeCaps` in that order. Each is private: `(string $gymId, array $group, ?string $deviceId, string $timestamp): void`.
- Each makes two `Model::upsert` calls, upserts then removals, so a key in both lists ends up removed. Add no emptiness guard or early return: `upsert` returns at once on an empty list.
- Every row carries `gym_id`, `device_id`, `updated_at`, and `deleted_at` (null on upserts, the timestamp on removals). Do not set `created_at`: `upsert` fills it on insert, and your `updated_at` wins over the one it adds.
- Removal rows carry the Entity Defaults placeholders, because the insert path needs them.
- Cast ids with `(string)`, as `UpsertPrismExercisesAction` does. Pass `is_member`, `cap_count`, `cap_period` through as validated: the strict rules guarantee their types, so this replaces the plan's cast line.
- Removing a family does not touch its member rows.

| Model | `uniqueBy` | Upsert update columns |
|---|---|---|
| `PrismExerciseFamily` | `gym_id, family_id` | `name, cap_count, cap_period, device_id, deleted_at, updated_at` |
| `PrismExerciseFamilyMember` | `gym_id, exercise_id, family_id` | `is_member, device_id, deleted_at, updated_at` |
| `PrismExerciseCap` | `gym_id, exercise_id` | `cap_count, cap_period, device_id, deleted_at, updated_at` |

Removals on all three update only a private constant, `REMOVAL_UPDATE_COLUMNS = ['device_id', 'deleted_at', 'updated_at']`.

**2.3 `app/Sync/Controllers/PrismExerciseLimitsController.php`.** Mirror the dismissal controller: `use ValidatesGym`, promoted `private readonly UpsertPrismExerciseLimitsAction $upsertLimitsAction`, and `store(StorePrismExerciseLimitsRequest $request, string $gym): JsonResponse`. Call `findGymOrFail`, take `device_id` from `$request->attributes` (the header, not the body field), pass `validated('families')`, `validated('members')`, `validated('caps')`, and return `status` `ok` and `updated_at` as `toIso8601String()`.

**2.4 `routes/sync.php`.** Add the `use` after `PrismExerciseController`, and add this after the `interference-dismissals` line:

```php
Route::post('/prism/{gym}/exercise-limits', [PrismExerciseLimitsController::class, 'store']);
```

**2.5 `tests/Feature/Sync/Prism/PrismExerciseLimitsStoreTest.php`.** Create with `php artisan make:test --phpunit --no-interaction Sync/Prism/PrismExerciseLimitsStoreTest`. Use `RefreshDatabase`; `setUp` creates gym `the-cove`. Helpers:

- `private static function limitsPayload(array $overrides = []): array`: all three groups with empty lists, through `array_replace_recursive` with `$overrides`.
- `private function postLimits(array $payload, string $gym = 'the-cove'): TestResponse`: `Sanctum::actingAs(User::factory()->create())`, then post with header `X-Device-Id: dev-1`.

Read rows back by key, never by position: MySQL doesn't guarantee row order. "Per table" means one row or key in each of the three tables. Sample rows: family `burpees`/`Burpees`/2/`week`, member `clean-and-jerk`/`clean`/true, cap `burpee-box-jump`/1/`program`.

| Test | Input | Expected |
|---|---|---|
| `test_upserts_store_a_row_in_each_table` | The sample rows | 200, `status` `ok`, `updated_at` present; values stored, `device_id` `dev-1`, `deleted_at` null |
| `test_empty_lists_are_a_no_op` | `limitsPayload()` | 200, `updated_at` present, tables empty |
| `test_removal_writes_a_tombstone_and_keeps_stored_values` | Seed sample rows with `device_id` `dev-old`; remove per table | `deleted_at` set, `device_id` `dev-1`, other values unchanged |
| `test_removal_with_no_prior_row_writes_a_placeholder_tombstone` | Remove per table, nothing seeded | 200; values per Entity Defaults; `deleted_at` set |
| `test_upsert_clears_a_tombstone` | Remove per table, then upsert the same keys with new values | One row each, `deleted_at` null, new values |
| `test_key_in_both_lists_ends_up_removed` | Same key in `upserts` and `removals`, per table | `deleted_at` set |
| `test_live_null_cap_is_distinct_from_a_tombstone` | Upsert a cap and a family with null/null | Rows exist, caps null, `deleted_at` null |
| `test_live_non_member_is_distinct_from_a_tombstone` | Member upsert, `is_member` false | Row exists, `is_member` false, `deleted_at` null |
| `test_removing_a_family_leaves_its_member_rows_live` | Seed family `burpees`, member `air-squat`/`burpees`/true; remove the family | Family tombstoned, member live |
| `test_family_ids_are_case_and_accent_sensitive` | Upsert `burpees`, `Burpees`, `burpées`, names `one`, `two`, `three` | 3 rows, each with its own name |
| `test_member_can_name_a_family_with_no_row` | Member `air-squat`/`ghost-family`/true | 200, row exists, families table empty |
| `test_one_exercise_can_belong_to_two_families` | Members `air-squat`/`squats` and `air-squat`/`legs`, then remove `air-squat`/`squats` | 2 rows: `squats` tombstoned, `legs` live |
| `test_failure_part_way_leaves_all_three_tables_unchanged` | See below | 500, tables hold exactly the seeded values |
| `test_response_updated_at_matches_the_feed_row` | Family upsert, then `GET /api/sync/prism/the-cove/changes` | Post's `updated_at` equals `exercise_families.0.updated_at` |
| `test_unknown_gym_returns_404` | `postLimits(self::limitsPayload(), 'no-such-gym')` | 404 |
| `test_unauthenticated_returns_401` | Plain `postJson` of `limitsPayload()`, not `postLimits` | 401 |
| `test_invalid_payload_returns_422` | `#[DataProvider('invalidPayloads')]` | 422, `assertJsonValidationErrors([$errorKey])`, tables empty |

**All-or-nothing test.** Seed family `seeded`/`Seeded`/1/`week`, member `air-squat`/`seeded`/true, cap `air-squat`/1/`week`. Register `DB::listen` with a `$thrown` flag captured by reference: on the first query whose SQL contains `prism_exercise_caps`, set the flag and throw `RuntimeException`. The flag stops it firing on the assertions' reads. Post: rename the family, add a second family, set the member's `is_member` false, set the cap's count to 5.

**`invalidPayloads`.** A `public static` provider; each named case yields a full body built with `self::limitsPayload()` and an error key. A family row has `family_id` `f`, `name` `F`; a cap row has `exercise_id` `e`; a member row has `exercise_id` `e`, `family_id` `f`. `n/p` is `cap_count`/`cap_period`.

| Row | Error key |
|---|---|
| Family 0/`week`; family `"2"`/`week`; family with `cap_period` null and no `cap_count` key | `families.upserts.0.cap_count` |
| Cap -1/`week`; cap 4294967296/`week`; cap null/`week` | `caps.upserts.0.cap_count` |
| Family 2/null | `families.upserts.0.cap_period` |
| Cap 2/`month`; cap 2/null; cap with `cap_count` null and no `cap_period` key | `caps.upserts.0.cap_period` |
| Family null/null without `name`; with a 256-character `name` | `families.upserts.0.name` |
| Member with `is_member` `"1"`; without `is_member` | `members.upserts.0.is_member` |
| Cap null/null with a 256-character `exercise_id` | `caps.upserts.0.exercise_id` |
| Member removal with `exercise_id` only | `members.removals.0.family_id` |
| Body with `caps` unset | `caps` |
| Body with `members.removals` unset | `members.removals` |

**2.6 `PrismWriteThrottleTest`.** Add `test_exercise_limits_writes_are_throttled`: `config(['rate_limits.prism_write.per_minute' => 2])`, act as one user, post an all-empty payload three times. Expect 200, 200, 429.

**Checkpoint:** `php artisan test --parallel`.

### Milestone 3: Changes feed

**3.1 `PrismChangesController`.**

- Add sections 8, 9, 10 after Comments, and renumber the cursor comment to 11. Each mirrors the dismissals section, with no eager loads. Locals: `$exerciseFamilies`, `$exerciseFamilyMembers`, `$exerciseCaps`.
- Row keys, in order, with `deleted_at` as `?->toIso8601String()` and `updated_at` as `->toIso8601String()`:
  - `exercise_families`: `family_id, name, cap_count, cap_period, deleted_at, updated_at`
  - `exercise_family_members`: `exercise_id, family_id, is_member, deleted_at, updated_at`
  - `exercise_caps`: `exercise_id, cap_count, cap_period, deleted_at, updated_at`
- Add `$maxFamilyUpdated`, `$maxFamilyMemberUpdated`, `$maxExerciseCapUpdated` to the `max('updated_at')` lines and to `$updatedInstants`.
- Add `$rawMaxFamilyDeleted`, `$rawMaxFamilyMemberDeleted`, `$rawMaxExerciseCapDeleted` to the `max('deleted_at')` queries and to `$deletedInstants`.
- Add the three keys after `comments` in the response.

**3.2 `PrismChangesTest`.** Import the models and `PHPUnit\Framework\Attributes\DataProvider`. Set fixed times as `test_cursor_advances_on_cell_removal` does. Times are on `2026-09-01`. `exerciseLimitRows` is a `public static` provider of three cases: model class and create attributes, using the sample rows with `gym_id` `the-cove`.

| Test | Setup | Expected |
|---|---|---|
| `test_changes_carry_exercise_limit_rows_with_exact_shapes` | The sample rows | `assertSame` on `exercise_families.0`, `exercise_family_members.0`, `exercise_caps.0` against the full 3.1 shapes: int `cap_count`, bool `is_member`, null `deleted_at` |
| `test_empty_gym_returns_empty_exercise_limit_lists` | No rows | All three keys `[]` |
| `test_since_filters_exercise_limit_rows` | Per table, rows at 10:00 and 12:00; `since` is `urlencode('2026-09-01T11:00:00+00:00')` | Each key holds only the 12:00 row |
| `test_exercise_limit_tombstones_are_delivered_in_delta` | Per table, a row with `updated_at` and `deleted_at` 12:00; same `since` | Each key holds it, `deleted_at` non-null |
| `test_cursor_advances_on_exercise_limit_update` | Provider: the gym's only row, `updated_at` 15:00 | `cursor` is 15:00 |
| `test_cursor_advances_on_exercise_limit_removal` | Provider: `updated_at` 10:00, `deleted_at` 15:00 | `cursor` is 15:00 |
| `test_other_gyms_exercise_limit_rows_never_appear` | Gym `other-gym` with a row per table at 15:00; `the-cove` with a row per table at 10:00 | Keys hold only `the-cove`'s rows; `cursor` is 10:00 |

**3.3 `test_changes_query_count_is_flat_under_growing_data`.** Seed the new tables in both phases, or flatness proves nothing: 2 rows each in the small phase, 30 each in the large one, tombstoned when `$e % 5 === 0`. Raise the limit from 22 to 31, and update the comment above it: the three tables add 9 constant queries (rows, max `updated_at`, max `deleted_at`). `assertSame($smallCount, $largeCount)` stays.

**Checkpoint:** `php artisan test --parallel`. Then both of these must print 6 on `app/Sync/Controllers/PrismChangesController.php` (each variable sits on two lines):
`grep -c "maxFamilyUpdated\|maxFamilyMemberUpdated\|maxExerciseCapUpdated"` and `grep -c "rawMaxFamilyDeleted\|rawMaxFamilyMemberDeleted\|rawMaxExerciseCapDeleted"`.

### Milestone 4: MySQL check, sweep, handoff

**4.1** With the suite green, run the bash block under `## MySQL check` in the plan, copied exactly. It runs a private MySQL on port 33996 for about a minute and removes it. It is not the long-running server the rules forbid, it is the one permitted use of `/tmp`, and `RefreshDatabase` wipes only its scratch database. Do not add `--no-interaction` to its `artisan test` calls. If a test fails, fix the code, run the suite, and re-run the whole block.

> **Danger.** `RefreshDatabase` drops every table in the database it connects to, and a passwordless MySQL listens on this machine's port 3306. Never run a test with `DB_CONNECTION=mysql` outside that block, and never remove `DB_PORT` from it.

It passes only if its output shows all of:

- `migrations_ran` above zero; otherwise a pass could be SQLite.
- `family_id` with `COLLATE utf8mb4_bin` in the families and members tables.
- The unique key `prism_family_members_gym_exercise_family_unique`.
- Only one `FOREIGN KEY` per table, on `gym_id`.

**4.2** Do the End-of-Run Cleanup Sweep (steering §4).

**4.3** Write `.execute-handoff.md` (steering §4). **What changed** says the route and feed keys are shapes Prism depends on, and that the owner adds the fixtures in `contracts/prism-logger` in the parent `squirby` repo by hand. **Migrations** says there are three, that they create tables only and touch no existing data, and gives `migrations_ran` and the three `show create table` outputs.

**Final checkpoint:** `php artisan test --parallel`.

## HARD RULES — NEVER VIOLATE THESE:

- **NEVER commit or push.** The automation commits what you leave in the worktree.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.
- **NEVER connect to any MySQL server but the scratch one in 4.1,** and never read `.env.local` or a `*.sql` dump.

## Implementation Rules

- All new code goes in `app/Sync/`, routes in `routes/sync.php`.
- Run tests with `php artisan test --parallel`, adding a path or `--filter=testName` while iterating.
- `DB::transaction` in the action is the one accepted `DB::` use (`DB::listen` is test-only). Use Eloquent otherwise.
- PHP 8 constructor promotion and explicit return types throughout.

## Success Criteria

- [ ] All files in Milestones 1 and 2 exist at their paths, and every named test exists and passes.
- [ ] The query-count test seeds all three tables in both phases, stays flat, and its limit is 31.
- [ ] Both Milestone 3 grep counts are 6.
- [ ] The MySQL block passes all four conditions, with the evidence in the handoff.
- [ ] All tests pass (`php artisan test --parallel`)
- [ ] `.execute-handoff.md` written
- [ ] No new composer dependencies

## Do Not

- Do NOT commit, push, run Pint, or run destructive database commands.
- Do NOT put code outside `app/Sync/`, apart from the migrations, route and tests.
- Do NOT change `prism_exercise_overlay`, `POST /prism/{gym}/exercises`, or any existing feed key.
- Do NOT add a revision column, a 409 or any concurrent-edit handling. Writes stay last-write-wins.
- Do NOT add a foreign key on `family_id`, use `SoftDeletes`, or cascade a family removal to members.
- Do NOT evaluate caps or resolve inheritance.
- Do NOT widen `exercise_id` or `family_id`, or set a table-level collation.

## Author Gates

- **Decisions gate:** Nothing is left to the executor: names, order, columns, rules, placeholders and test cases are decided.
- **Verification gate:** No behavior needs the running app, because there is no UI, so nothing goes to the UI Verifier. Every behavior has a wire test in Milestone 2 or 3. What SQLite can't prove (collation, index name, foreign keys, transaction) is proved by those tests on real MySQL in Milestone 4.

---

## Post-Execution Retro (authored by the REVIEWER, not the executor)

> The executor must NOT fill this in — leave the `{placeholder}` values untouched. After the run, the human reviewer reconstructs this section from the commit trail at archive time (the `snapshot:` commit, the `cleanup:`/`fix:` follow-ups, any phased slices) and moves both plan + prompt to `completed/`. See `docs/architect-workflow.md` → Post-Execution Review Protocol.

- **Attempts:** {1 (clean) / N (root cause of failures)}
- **Tests added:** {count}
- **Prompt improvements for next time:** {what to add/change}
- **Steering updates needed:** {yes/no, what}
