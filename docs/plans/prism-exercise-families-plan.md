# Prism Exercise Families (Logger slice)

**Status:** planned. **Issue:** #4. **Pairing:** mauzeh/prism#476 (Prism connects later, in its own change).
**Path:** fix, one prompt. It is past the small-fix limits (new table, migration), has no UI, and the issue
fixes every behavior, so no separate spec is needed: the issue is the spec.

## Before You Start

Read `docs/antigravity-steering.md`, `.claude/rules/sync-api-context.md`, `.claude/rules/project-conventions.md`.

## What You're Building

A gym-scoped store for Prism exercise families: a table, a write route, and an `exercise_families` list in the
Prism changes feed. Logger stores `record` opaquely and never reads its fields. End state: a family written by
one device reaches another through `/changes`, a removal reaches it as a tombstone, and a re-upsert restores it.

## Existing Code to Understand

The pattern to mirror one-to-one: `UpsertPrismExercisesAction`, `PrismExerciseController`,
`StorePrismExercisesRequest`, `PrismExerciseOverlayRecord`, and migration
`2026_10_20_203704_create_prism_exercise_overlay_table.php`. The dismissal resource is the most recent copy of
the same pattern, and its tests (`InterferenceDismissalSyncTest`, `InterferenceDismissalChangesTest`) are the
model for the new tests.

## Execution Plan (one prompt)

| Piece | Path | Change |
|---|---|---|
| Migration | `database/migrations/2026_11_05_000000_create_prism_exercise_families_table.php` | New table, see below |
| Model | `app/Sync/Models/PrismExerciseFamily.php` | `$table = 'prism_exercise_families'`, `$fillable` of `gym_id, family_id, record, device_id, deleted_at`, casts `record => array`, `deleted_at => datetime`, `gym()` BelongsTo. No `SoftDeletes` |
| Request | `app/Sync/Requests/StorePrismExerciseFamiliesRequest.php` | Rules below |
| Action | `app/Sync/Actions/UpsertPrismExerciseFamiliesAction.php` | `execute(string $gymId, array $upserts, array $removals, ?string $deviceId): Carbon`, same body as the exercises action with `family_id` |
| Controller | `app/Sync/Controllers/PrismExerciseFamilyController.php` | `store()`, same as `PrismExerciseController`, `ValidatesGym` |
| Route | `routes/sync.php` | `POST /prism/{gym}/exercise-families` in the existing `throttle:prism-write` group |
| Feed | `app/Sync/Controllers/PrismChangesController.php` | New list, top-level key `exercise_families`, and the table in BOTH high-water-marks |

**Table `prism_exercise_families`** (same as `prism_exercise_overlay`, with `family_id` for `exercise_id`):

```php
$table->id();
$table->string('gym_id');
$table->string('family_id');
$table->json('record')->nullable();
$table->string('device_id', 36)->nullable();
$table->timestamp('deleted_at', 6)->nullable();
$table->timestamps(6);
$table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
$table->unique(['gym_id', 'family_id']);
$table->index(['gym_id', 'updated_at']);
```

`down()` is `Schema::dropIfExists('prism_exercise_families')`.

**Validation:** `upserts` and `removals` are `present`, `array`. `upserts.*.family_id` and `removals.*.family_id`
are `required`, `string`, `max:255`. `upserts.*.record` is `required`, `array`, with no rules below it.
`device_id` is `nullable`, `string`, `max:36`, as on the exercises route. The stored `device_id` comes from the
`X-Device-Id` header through the middleware, as it does there.

**Feed row:** `family_id`, `record`, `deleted_at`, `updated_at`; both dates through `?->toIso8601String()`, filtered
by `updated_at >= since` as the other lists are. Response of the write route: `{ "status": "ok", "updated_at": … }`.

## Entity defaults

```
gym_id      string, required, from the route
family_id   string, required
record      array cast, null only on a tombstone
device_id   string(36), null when no header was sent
deleted_at  datetime cast, null when live
```

## Migration safety

New table only: no existing table, row or column is touched, so it is safe to auto-apply, and `down()` removes
it. The index width is the same as `prism_exercise_overlay` (255 + 255), which already runs on MySQL. The `gym_id`
foreign key cascades on delete, like its siblings. `deleted_at` is a plain column so tombstones stay in the feed.

## Consumer Impact Trace

- **Readers of the feed response:** Prism's `syncService.js` reads named keys, so an extra key is ignored until
  Prism connects (checked 2026-11-05). The Athlete app does not use the Prism routes.
- **Interpreters:** none. Nothing in Logger reads `record`.
- **Tests on the old shape:** the feed tests use `assertJson`/`assertJsonStructure`, which tolerate a new key.
  The exception is `PrismChangesTest::test_changes_query_count_is_flat_under_growing_data`: see below.

## Tests (all wire tests; there is no pure logic and no UI)

- New `tests/Feature/Sync/Prism/PrismExerciseFamiliesStoreTest.php`: upsert keeps unknown record fields; removal
  writes a tombstone with a null record; re-upsert clears the tombstone; unknown gym is 404; unauthenticated is
  401; missing `upserts` or `removals` is 422; non-array `record` is 422; a mixed upsert and removal request.
- Throttle, added to `PrismWriteThrottleTest`: set `rate_limits.prism_write.per_minute` to 2 in the test and
  assert the third POST to the new route is 429. The existing test there proves only headroom, not a limit.
- Added to `PrismChangesTest`: families appear with the exact row shape; `since` filters; tombstones are included;
  the cursor moves on an update; the cursor moves on a removal when `deleted_at` is the latest instant (mirror
  `test_cursor_advances_on_cell_removal`); another gym's families never appear.
- **Existing test that must change:** the query-count test caps the feed at 22 queries. It runs about 21 today
  (20 measured with no destination rows, plus the `linked_by` load). Families add 3 constant queries (rows,
  `max(updated_at)`, `max(deleted_at)`), so raise the cap to 25 and extend its comment. Seed family rows in both
  the small and large datasets so an N+1 would show. Keep the `assertSame` flatness check.

Checkpoint: `php artisan test --parallel`. `artisan test` rejects `--no-interaction`, so leave it off that command.

## Decided (not left to the executor)

- **Mirror, don't abstract.** The feed controller repeats a block per table. Sharing it would touch seven working
  feeds for no behavior change, so this slice adds one more block. The arc is honestly additive.
- **`record` handling matches exercises exactly.** Measured on the exercises route: Laravel's global request
  middleware trims strings, turns `""` into `null`, and `{}` comes back as `[]`. So "stored as sent" holds for fields and
  values, with those three exceptions. Family records (`id`, `name`, `frequencyCap`) are not affected in practice.
  Changing it means editing `bootstrap/app.php` and would differ from exercises; it is out of scope. Don't add a
  test that pins this behavior.

## Hard Rules

Never commit, never run Pint, never run a destructive database command.

## Success Criteria

All tests above pass, `php artisan test --parallel` is green, and the diff touches only the files named here.

## Do Not

- Validate anything inside `record`, including the cap values.
- Add family membership to anything; it stays on the exercise record.
- Use `SoftDeletes` on the model, or change any existing feed list, key or route.
- Edit `bootstrap/app.php`, or any Prism code.
- Attempt the `contracts/prism-logger` fixture. It lives in the parent `squirby` repository, outside an automated
  turn (`CLAUDE.md`). The owner adds it.

## In-app checks

None: no web UI changes. After the deploy, `php artisan migrate:status` should show the migration as Ran.

## Post-Execution Retro

(Empty on handoff.)
