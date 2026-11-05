# Prism exercise limits: families, membership and frequency caps as real rows

**Issue:** #6 (replaces #4; PR #5 is closed and nothing from it shipped, so do not build on that branch).
**Pairing:** Prism ↔ Logger, this is the Logger slice (Prism side: mauzeh/prism#476).
**Path:** fix, one prompt. It is past the small-fix limits (three tables, migrations) and the issue settles every
behavior, so no separate spec. **Spec:** the issue body, read together with the owner's multi-family comment of
2026-11-05. Where this plan is silent, the issue decides.

## Before You Start

Read `docs/antigravity-steering.md`, `.claude/rules/project-conventions.md`, `.claude/rules/sync-api-context.md`.

## What You're Building

Three gym-scoped tables, one write route that applies all three in a single transaction, and three new keys in the
Prism changes feed. Logger stores rows and range-scans them. It never evaluates a cap or resolves inheritance.
Additive only: no existing table, route or response key changes.

**Complexity:** additive by nature, there is nothing to remove. It mirrors the existing Prism write and feed
patterns and introduces no new mechanism, apart from one timestamp per request (below), which is simpler than the
re-query the sibling actions do.

## Existing Code to Understand

| File | Why |
|---|---|
| `app/Sync/Actions/UpsertPrismExercisesAction.php` | Upsert and tombstone pattern |
| `app/Sync/Actions/UpsertPrismCommentsAction.php` | `DB::transaction` precedent, the one accepted `DB::` use here |
| `app/Sync/Controllers/PrismInterferenceDismissalController.php` | Controller shape; `device_id` comes from `$request->attributes` (the `X-Device-Id` header) |
| `app/Sync/Requests/StorePrismInterferenceDismissalsRequest.php` | Form Request shape, array-style rules |
| `app/Sync/Models/PrismExerciseOverlayRecord.php` | Model shape |
| `app/Sync/Controllers/PrismChangesController.php` | Feed sections and both cursor high-water-marks |
| `database/migrations/2026_10_20_203704_create_prism_exercise_overlay_table.php` | Migration shape |

## Findings that shape the design

Each was proven on 2026-11-04 on a scratch MySQL 9.7 instance or against the framework source. A green SQLite run
hides all of the MySQL ones.

| # | Finding | Consequence |
|---|---|---|
| 1 | The default unique index name on the members table is 65 characters. MySQL's limit is 64 (error 1059). SQLite accepts it, so tests pass and the **production deploy fails** | Name that index explicitly |
| 2 | `->collation('utf8mb4_bin')` breaks SQLite (`no such collation sequence`), so every test would fail | Choose the collation by driver |
| 3 | The members unique key is 3060 bytes (three `varchar(255)` at 4 bytes), under InnoDB's 3072 | Keep all three at the default 255. Lengthening any one breaks the migration on MySQL |
| 4 | `burpees`, `Burpees` and `burpées` are three rows under `utf8mb4_bin`. It is a pad-space collation, but `TrimStrings` removes trailing spaces first | `utf8mb4_bin` is right |
| 5 | A removal needs a row even when the server has none, and bundled families make that normal. `name` and `is_member` are NOT NULL, so a tombstone insert without them is a 500 (error 1364) | Removals insert placeholder values (below) |
| 6 | `cap_count` above 4294967295 is a MySQL out-of-range error, so a 500. An over-long `name` is the same in strict mode | Validate upper bounds, so these are 422s |
| 7 | Plain `integer` and `boolean` rules accept `true`, `"2"` and `"1"`. The `:strict` forms exist in Laravel 12.68 | Use `integer:strict` and `boolean:strict` |
| 8 | The collation test passes on SQLite regardless (`BINARY` is its default) | It only proves something in the MySQL run |

## Schema

Three migrations, one per table, because MySQL DDL is not transactional and a half-run multi-table migration would
be stuck. Timestamps `2026_11_05_100000`, `100100`, `100200`, named `create_<table>_table`. `down()` is
`Schema::dropIfExists`. Every table has `id()`, a `gym_id` string with a foreign key to `gyms.id` and
`cascadeOnDelete`, `device_id` `string(36)` nullable, `deleted_at` `timestamp(6)` nullable as a plain column,
`timestamps(6)`, and an index on `gym_id, updated_at`. Set collation per column only: a table-level collation
would break the `gym_id` foreign key.

```php
$table->string('family_id')->collation(Schema::getConnection()->getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'binary');
```

| Table | Columns | Unique |
|---|---|---|
| `prism_exercise_families` | `family_id` (binary), `name` string NOT NULL, `cap_count` unsigned int nullable, `cap_period` string nullable | `gym_id, family_id` |
| `prism_exercise_family_members` | `exercise_id` string, `family_id` (binary) NOT NULL and **no foreign key**, `is_member` boolean NOT NULL | `gym_id, exercise_id, family_id`, named `prism_family_members_gym_exercise_family_unique` |
| `prism_exercise_caps` | `exercise_id` string, `cap_count` unsigned int nullable, `cap_period` string nullable | `gym_id, exercise_id` |

`exercise_id` keeps the default collation, as the issue specifies and matching `prism_exercise_overlay`.

**Safe to auto-apply:** three `CREATE TABLE`s, no existing table or row touched, `down()` drops only the new tables.
The only foreign key is `gym_id`, with the same type and collation as the overlay's.

### Models (`app/Sync/Models/`)

`PrismExerciseFamily`, `PrismExerciseFamilyMember`, `PrismExerciseCap`, shaped like `PrismExerciseOverlayRecord`:
`$table`, `$fillable` (all columns but `id` and timestamps), a `gym()` relation, no `SoftDeletes`.
Casts: `deleted_at` `datetime`, `cap_count` `integer`, `is_member` `boolean`. The `is_member` cast is what makes the
feed emit a JSON boolean. No factories: sibling Prism models have none, tests use `::create`.

### Entity defaults

```text
Live row        values as sent; deleted_at null
Tombstone over an existing row   stored values kept; only device_id, deleted_at, updated_at change
Tombstone with no prior row      families: name '', cap_count null, cap_period null
                                 members:  is_member false
                                 caps:     cap_count null, cap_period null
```

Stored values stay on a tombstone, rather than being cleared as the overlay's `record` is, because `name` and
`is_member` cannot be null and one rule for all three tables is simpler. Readers go by `deleted_at` first: on a
tombstone the other fields mean nothing.

## Write route

`POST /api/sync/prism/{gym}/exercise-limits`, in the existing `throttle:prism-write` group in `routes/sync.php`.

- **Controller:** `App\Sync\Controllers\PrismExerciseLimitsController::store`, shaped like the dismissal controller.
- **Form Request:** `App\Sync\Requests\StorePrismExerciseLimitsRequest`.
- **Action:** `App\Sync\Actions\UpsertPrismExerciseLimitsAction::execute(string $gymId, array $families, array $members, array $caps, ?string $deviceId): Carbon`,
  each group an `array{upserts: array, removals: array}` with PHPDoc shapes.

### Validation

| Field | Rules |
|---|---|
| `families`, `members`, `caps` and each `.upserts`, `.removals` | `present`, `array` |
| `*.family_id`, `*.exercise_id` (upserts and removals) | `required`, `string`, `max:255` |
| `families.upserts.*.name` | `required`, `string`, `max:255` |
| `members.upserts.*.is_member` | `required`, `boolean:strict` |
| `cap_count` (families and caps upserts) | `present`, `nullable`, `integer:strict`, `min:1`, `max:4294967295`, `required_with:<sibling cap_period>` |
| `cap_period` (families and caps upserts) | `present`, `nullable`, `in:week,program`, `required_with:<sibling cap_count>` |
| `device_id` | `nullable`, `string`, `max:36` |

`present` on both cap fields means an omitted field is a 422, never a silent "no cap": the issue exists because
missing data wiped real settings.

### Action behavior

- One `DB::transaction` around all writes.
- **One timestamp per request.** Take `$now = Carbon::now()` once. Put `updated_at` (and `deleted_at` on removals),
  formatted `Y-m-d H:i:s.u`, in every row, list `updated_at` in the update columns, and return `$now`. The response's
  `updated_at` therefore matches every touched row, with no re-query. An all-empty request returns `Carbon::now()`
  and writes nothing.
- **Order:** families, members, caps; in each, upserts then removals. A key in both lists of one request ends up
  removed.
- **Upsert update columns:** every value column plus `device_id`, `deleted_at` (null), `updated_at`.
- **Removal update columns:** `device_id`, `deleted_at`, `updated_at` only. Insert values per Entity defaults.
- **Removing a family does not touch member rows.** There is no foreign key and no cascade.
- Cast `cap_count` and `is_member` as `UpsertPrismExercisesAction` casts ids, and pass nulls through unchanged.

## Changes feed

In `PrismChangesController`, add sections for the three models after comments, each using the same
`where('gym_id')` and `since` on `updated_at`, with the exact row shapes in the issue. Add all three to the
`max(updated_at)` list and all three to the `max(deleted_at)` list. Response keys: `exercise_families`,
`exercise_family_members`, `exercise_caps`. No eager loads, so nine queries are added in total.

## Test layering

No behavior here is a pure-core candidate: all of it is persistence and wire shape, so everything is a feature test.
No behavior needs the running app, because there is no UI.

| Behavior | Test layer |
|---|---|
| Write route, validation, all-or-nothing, tombstones, no foreign key, several families | `tests/Feature/Sync/Prism/PrismExerciseLimitsStoreTest.php` (new) |
| Real 429 | `PrismWriteThrottleTest`: set `rate_limits.prism_write.per_minute` to 2, third request is 429 |
| Feed keys, shapes, `since`, tombstones, cursor on update and removal, other gym's rows | `PrismChangesTest` |
| Collation, index name, foreign keys, transaction | The MySQL run below, proved by the same tests |

Every test listed in the issue's Tests section is required. In addition:

- Validation: count above 4294967295; `name` over 255; a string count (`"2"`); a non-boolean `is_member` (`"1"`); a
  period without a count; a missing `cap_count` key; a missing group or list.
- A removal with no prior row succeeds, for each table, and `name` is `''` on the family tombstone.
- A tombstone over an existing row keeps its stored values.
- A key in both lists ends up removed.
- Removing a family leaves its member rows live.
- Response `updated_at` equals the feed's `updated_at` for a touched row.
- `burpées` joins `burpees` and `Burpees` in the collation test.

**All-or-nothing test.** Seed a row in each table. Register `DB::listen` so it throws on the first query containing
`prism_exercise_caps` after the request starts. Post a payload that changes all three tables, assert a 500, and
assert all three tables still hold exactly the seeded values. The listener needs no application seam, works on both
drivers, and fails if the transaction is missing.

**`test_changes_query_count_is_flat_under_growing_data`:** seed the three tables in both the small and large phases,
otherwise flatness proves nothing, and raise the limit from 22 to 31 (nine queries, three tables). This is the only
existing test assertion that changes.

## MySQL check (required, the issue asks for it)

Run after the full suite is green, using exactly this. It starts a private MySQL for about a minute and removes it.
It is not the long-running server the rules forbid.

> **Danger.** `RefreshDatabase` drops every table in the database it connects to. A MySQL server on this machine
> listens on port 3306 and accepts passwordless root, and `config/database.php` falls back to it and to the database
> `laravel`. Run the block as written and never drop `DB_PORT`. Note that the `mysql` connection reads
> `MYSQL_DB_DATABASE`, not `DB_DATABASE`.

```bash
bash <<'CHECK'
set -euo pipefail
B=/opt/homebrew/opt/mysql@9.7/bin
PORT=33996
S=$(mktemp -d /tmp/logger-mysql.XXXXXX)
Q="$B/mysql --no-defaults -uroot -S $S/m.sock"
cleanup() { "$B/mysqladmin" --no-defaults -uroot -S "$S/m.sock" shutdown 2>/dev/null || true; sleep 2; rm -rf "$S"; }
trap cleanup EXIT
"$B/mysqld" --no-defaults --initialize-insecure --datadir="$S/d" --log-error="$S/init.log"
"$B/mysqld" --no-defaults --datadir="$S/d" --port=$PORT --bind-address=127.0.0.1 --socket="$S/m.sock" \
  --mysqlx=OFF --pid-file="$S/m.pid" --log-error="$S/err.log" --daemonize
sleep 3
$Q -e "create database logger_check character set utf8mb4 collate utf8mb4_unicode_ci"
E="env DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=$PORT MYSQL_DB_DATABASE=logger_check MYSQL_DB_USERNAME=root MYSQL_DB_PASSWORD="
$E php artisan test tests/Feature/Sync/Prism/PrismExerciseLimitsStoreTest.php
$E php artisan test tests/Feature/Sync/Prism/PrismChangesTest.php
$E php artisan test tests/Feature/Sync/Prism/PrismWriteThrottleTest.php
$Q logger_check -e "select count(*) as migrations_ran from migrations; show create table prism_exercise_families; show create table prism_exercise_family_members; show create table prism_exercise_caps"
CHECK
```

`migrations_ran` above zero proves the tests really ran on MySQL. Without it a pass could be SQLite. Put that count
and the three `show create table` outputs in the handoff's Migrations section. They must show `utf8mb4_bin` on
`family_id`, the named unique index, and no foreign key on `family_id`. Do not pass `--no-interaction` to
`artisan test`: it is forwarded to PHPUnit, which rejects it.

## Consumer Impact Trace

| Shape | Readers | Effect |
|---|---|---|
| `/changes` response, three new keys | Prism `src/services/syncService.js` reads named keys (checked on #4) | Ignored until Prism connects |
| `/changes` response in tests | `PrismChangesTest`, `InterferenceDismissalChangesTest` use `assertJsonStructure`, which allows extra keys | Only the query limit changes |
| New tables | No enumerations elsewhere: nothing in `app/`, `config/`, or `docs/sync` lists Prism tables | None |
| `LogSyncRequest`, `ReplayFailedRequests` | Path-generic | None |

**Contract note for the handoff:** the new route and the three feed keys are shapes Prism depends on. The fixtures in
`contracts/prism-logger` in the parent `squirby` repo are added by hand by the owner.

## Hard Rules

No commits, no Pint, no destructive database command outside the scratch block above, no new dependency, never read
`.env.local` or a dump, no connection to any MySQL server but the scratch one.

## Success Criteria

- [ ] `php artisan test --parallel` passes.
- [ ] The MySQL block passes, with its evidence in the handoff.
- [ ] Both high-water-marks list all three tables.
- [ ] The query-count test seeds all three tables, is flat, and its limit is 31.
- [ ] The handoff states that the change has three migrations that create tables only and touch no existing data.

## Do Not

- Change `prism_exercise_overlay`, `POST /prism/{gym}/exercises`, or any existing feed key.
- Add a revision column, a 409 response or any concurrent-edit handling. Writes stay last-write-wins.
- Add a foreign key on `family_id`, or use `SoftDeletes`.
- Evaluate caps or resolve inheritance in Logger.
- Widen `exercise_id` or `family_id` (finding 3).

## Revision note for the author (2026-11-05)

The first execution never started. The launcher passes the prompt as `claude -p <prompt>`, and the CLI rejects text
beginning with `---` as an unknown option. Change one thing in
`docs/plans/prism-exercise-limits-p1-rows-route-feed-prompt.md`: delete line 1 (the leading `---`), so the file opens
with `# Global Execution Rules`. The template has had the same line removed, so the rules still match it verbatim.
Nothing else in the prompt changes. A prompt must never start with `-`.

## Post-Execution Retro

Left empty on handoff.
