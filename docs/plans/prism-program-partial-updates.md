# Prism Programs — First-Class Fields, Partial Updates, Publish Destinations (Multi-User Safe)

**Status:** proposed (not yet applied). **Repo:** `logger` (this change). Prism follow-ups are listed in §9;
they live in the Prism repo and are out of scope here.

**Driver:** several coaches in the same gym can have the same Prism program open at once. Program-level data
(name, start date, archive state, and the Google Sheet a program publishes to) must survive concurrent edits
from different users and devices. Today every program write replaces the whole row, including an opaque
`metadata` JSON blob, so any stale client silently reverts other users' changes.

**Decisions taken (owner, 2026-10-31):**
- Program data the server or other users rely on becomes **first-class**: real columns or tables, no JSON blob.
- `metadata` is **removed**.
- **No backward compatibility and no fallbacks.** There is one new contract. Old data is migrated once, on
  the server at deploy and in each browser on next load.
- Google Sheets access across coaches uses the **Picker grant** (keep the `drive.file` scope). See §8.

> Verified against the source on 2026-10-31:
> - Logger: `UpsertPrismProgramAction`, `StorePrismProgramRequest`, `PrismProgramController`,
>   `PrismChangesController`, `routes/sync.php`, the `prism_programs` migrations, and the
>   interference-dismissal resource (the pattern for new resources).
> - Prism: `profileService.js`, `syncQueue.js`, `syncApi.js`, `syncService.js`, `remoteRepositoryHttp.js`,
>   `useUpdateCheck.js`, `publishDestinationRegistry.js`, `googleSheetsExport.js`,
>   `docs/export-google-sheets.md`.

---

## 0. TL;DR

1. **`start_date`** becomes a nullable `date` column on `prism_programs`. **`metadata` is dropped.** Its only
   keys are `program` (a copy of `name`), `startDate`, and `imported_at`. (§2)
2. **Program writes are partial.** Only the fields present in the request are written, and an explicit `null`
   clears. With no blob there is nothing to merge: each field is last-write-wins on its own. (§3)
3. **New resource `prism_program_destinations`** holds a program's publish link (Google Sheets first; the
   `type` values match Prism's destination registry ids). It has its own endpoint, its own feed section,
   tombstones on unlink, and a server-recorded `linked_by_user_id`. (§4)
4. **Cutover without compatibility code.**
   - A one-time data migration converts the server.
   - Prism converts its local data, including queued edits, when it first loads the new build.
   - Program writes in the old format get a **503**, so tabs still on the old build keep those edits queued
     until they reload. They are never dropped. (§6)
5. **Deploy order:** Logger first, then Prism immediately after. (§7)

---

## 1. The problem

### 1.1 What the backend does today

```php
// UpsertPrismProgramAction::execute
PrismProgram::updateOrCreate(
    ['gym_id' => $gymId, 'program_uuid' => $programUuid],
    [
        'name'        => $attributes['name'],                 // required by the request
        'week_count'  => $attributes['week_count'],           // required by the request
        'metadata'    => $attributes['metadata'] ?? null,     // whole blob, absent ⇒ null
        'archived_at' => $attributes['archived_at'] ?? null,  // absent ⇒ un-archive
        'device_id'   => $attributes['device_id'] ?? null,
    ]
);
```

### 1.2 Failure sequences with several users

| # | Sequence | Result |
|---|----------|--------|
| F1 | A renames. B, whose copy has the old name, changes the start date, so B's push carries the old `name`. | A's rename is reverted. |
| F2 | A sets a start date. B, whose copy has none, archives, so B's push carries `metadata` without `startDate`. | A's start date is erased. |
| F3 | A links a Google Sheet. B changes anything program-level. | The link is erased. |
| F4 | A archives. B (stale) renames, so B's push carries `archived_at: null`. | The program is un-archived. |

Two Prism-side bugs partly hide these today (see §9): renames are never pushed, and pulled fields are not
applied to programs a device already has. Fixing Prism alone would make F1–F4 *more* frequent. So the backend
contract has to change first.

### 1.3 The right unit of last-write-wins

Whole-row last-write-wins is only correct if every writer has the latest copy. That is false for an
offline-capable, queue-based client used by several people. The unit must be the **field**, and the publish
link must be its **own row**.

---

## 2. Data model

### 2.1 What `metadata` holds today

Key counts in the local DB (re-run on production before migrating; see §7):

| Key | Rows | What it is | Destination |
|-----|------|------------|-------------|
| `program` | 18 | Copy of the program name. Prism's `profileService` writes `metadata: { program: profile.name }`. | **Dropped** (already in `name`). |
| `startDate` | 5 | ISO `YYYY-MM-DD` calendar anchor. | **`start_date` column.** |
| `imported_at` | 4 | Timestamp set when a program is imported (`profileService.js:58`). | **Dropped.** Nothing outside Prism reads it, and it is device-local provenance. If Prism needs it, it stays in local storage. |

Production query:

```sql
SELECT jk.k AS `key`, COUNT(*) AS n
FROM prism_programs p,
     JSON_TABLE(JSON_KEYS(p.metadata), '$[*]' COLUMNS (k VARCHAR(255) PATH '$')) jk
WHERE p.metadata IS NOT NULL
GROUP BY jk.k;
```

If any other key shows up, stop and decide whether it deserves a column before running the migration.

### 2.2 `prism_programs` after the change

| Column | Change |
|--------|--------|
| `name`, `week_count`, `archived_at`, `device_id`, timestamps | unchanged |
| `start_date` | **new**, `date`, nullable |
| `metadata` | **dropped** |

### 2.3 New table `prism_program_destinations`

One row per program per destination type.

```php
Schema::create('prism_program_destinations', function (Blueprint $table) {
    $table->id();
    $table->string('gym_id');
    $table->string('program_uuid');
    $table->string('type', 32);                    // 'google_sheets' | 'wodify' | 'momence' (Prism registry ids)
    $table->string('spreadsheet_id')->nullable();  // google_sheets
    $table->unsignedBigInteger('sheet_id')->nullable(); // google_sheets tab id (0 is valid)
    $table->string('title')->nullable();           // display label (spreadsheet or tab title)
    $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('linked_at', 6)->nullable();
    $table->string('device_id', 36)->nullable();
    $table->timestamp('deleted_at', 6)->nullable(); // tombstone on unlink
    $table->timestamps(6);

    $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
    $table->unique(['gym_id', 'program_uuid', 'type']);
    $table->index(['gym_id', 'updated_at']);
});
```

Notes:
- **Several programs can share one spreadsheet.** Prism's export puts one tab per program in a single
  spreadsheet (`docs/export-google-sheets.md` §2). Each program's row carries the same `spreadsheet_id` and its
  own `sheet_id`.
- **The Google columns are typed and nullable.** When Wodify or Momence ship they get their own nullable
  columns. Don't use a generic `config` JSON column; that would bring back the problem this plan removes.
- **The table is keyed on `program_uuid`, with no foreign key to `prism_programs`.** This matches the cells
  table: a destination can arrive before its program row.

---

## 3. Program write — partial update

### 3.1 Request — `StorePrismProgramRequest`

```php
return [
    'name'        => ['sometimes', 'required', 'string', 'max:255'],
    'week_count'  => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
    'start_date'  => ['sometimes', 'nullable', 'date_format:Y-m-d'],
    'archived_at' => ['sometimes', 'nullable', 'date'],
    'device_id'   => ['nullable', 'string', 'max:36'],
];
```

- Keys that weren't sent are absent from `validated()`. That is the "don't touch" signal. Use
  `array_key_exists`, never `??`, so an explicit `null` still clears the field.
- The request does not check whether the row exists. That check lives in the action (§3.2), so it can't race
  with a create.
- The old-format check (§6.3) runs **before** validation.

### 3.2 Action — `UpsertPrismProgramAction`

```php
public function execute(string $gymId, string $programUuid, array $attributes): Carbon
{
    $fields = array_intersect_key($attributes, array_flip(['name', 'week_count', 'start_date', 'archived_at']));

    $program = PrismProgram::where('gym_id', $gymId)->where('program_uuid', $programUuid)->first();

    if ($program === null) {
        if (! array_key_exists('name', $fields) || ! array_key_exists('week_count', $fields)) {
            throw ValidationException::withMessages([
                'name' => 'name and week_count are required when creating a program.',
            ]);
        }
        try {
            $program = PrismProgram::create(['gym_id' => $gymId, 'program_uuid' => $programUuid] + $fields
                + ['device_id' => $attributes['device_id'] ?? null]);
            return $program->updated_at;
        } catch (UniqueConstraintViolationException) {
            // Another coach created it at the same moment: fall through and apply as an update.
            $program = PrismProgram::where('gym_id', $gymId)->where('program_uuid', $programUuid)->firstOrFail();
        }
    }

    $program->fill($fields);
    $program->device_id = $attributes['device_id'] ?? null;
    $program->updated_at = now(); // always bump, so every accepted write appears in /changes
    $program->save();             // UPDATE writes only the dirty columns

    return $program->updated_at;
}
```

Design points:
- **No lock and no transaction needed.** Each field is a plain column write. Two coaches changing *different*
  fields both win. Two coaches changing the *same* field: the later one wins.
- **Simultaneous creates.** The unique index on `(gym_id, program_uuid)` makes the second insert fail. It is
  caught and retried as an update instead of becoming a 500.
- **`updated_at` always bumps.** Today `updateOrCreate` skips the save when nothing changed, so a repeated
  identical write does not appear in `/changes`. Always bumping is a small, intended change.
- `PrismProgram` model: add `start_date` to `$fillable` and cast it as `'date:Y-m-d'`. Remove `metadata` from
  both.

### 3.3 Controller — `PrismProgramController::storeProgram`

No logic change. It passes `validated()` plus the device id through, as today. The response
(`{ status: 'ok', updated_at }`) is unchanged.

---

## 4. Destinations resource

### 4.1 Endpoint

`POST /api/sync/prism/{gym}/programs/{program_uuid}/destinations`, in the `prism-write` route group.

```jsonc
// link (or replace the link for this type)
{ "upserts": [ {
    "type": "google_sheets",
    "spreadsheet_id": "1AbC…",
    "sheet_id": 0,
    "title": "Spring Block 2026"
} ], "removals": [] }

// unlink
{ "upserts": [], "removals": [ { "type": "google_sheets" } ] }
```

This uses the same `upserts` / `removals` shape as `interference-dismissals`, so Prism's sync queue handles
it the same way.

### 4.2 Request — `StorePrismProgramDestinationsRequest`

```php
return [
    'upserts'                  => ['present', 'array'],
    'upserts.*.type'           => ['required', 'string', Rule::in(['google_sheets'])],
    'upserts.*.spreadsheet_id' => ['required_if:upserts.*.type,google_sheets', 'string', 'max:255'],
    'upserts.*.sheet_id'       => ['required_if:upserts.*.type,google_sheets', 'integer', 'min:0'],
    'upserts.*.title'          => ['nullable', 'string', 'max:255'],
    'removals'                 => ['present', 'array'],
    'removals.*.type'          => ['required', 'string', 'max:32'],
    'device_id'                => ['nullable', 'string', 'max:36'],
];
```

`type` accepts only destinations that actually exist. Add `wodify` and `momence` when their adapters ship.

### 4.3 Action — `UpsertPrismProgramDestinationsAction`

Modelled on `UpsertPrismInterferenceDismissalsAction`:
- **Upsert** on `(gym_id, program_uuid, type)`. It sets the Google columns, `title`, `device_id`,
  `deleted_at = null`, `linked_by_user_id = $request->user()->id`, and `linked_at = now()`.
- **Removal** writes a tombstone: `deleted_at = now()`. The row is kept so other devices learn about the unlink.
- Returns the max of `updated_at` and `deleted_at` across the touched rows, the same way the dismissal action
  does.

### 4.4 Changes feed

`PrismChangesController` gains a `destinations` section and adds the table to both cursor high-water marks
(`updated_at`, and `deleted_at` for tombstones):

```jsonc
"destinations": [ {
  "program_uuid": "…",
  "type": "google_sheets",
  "spreadsheet_id": "1AbC…",
  "sheet_id": 0,
  "title": "Spring Block 2026",
  "linked_by": { "id": 12, "name": "Coach A" },   // null if the user was deleted
  "linked_at": "2026-10-31T15:04:00+00:00",
  "deleted_at": null,
  "updated_at": "2026-10-31T15:04:00+00:00"
} ]
```

Eager-load `linkedBy:id,name` to avoid N+1 queries.

---

## 5. Program wire contract

`POST /api/sync/prism/{gym}/programs/{program_uuid}`

| Field | Create | Update | Semantics |
|-------|--------|--------|-----------|
| `name` | required | optional | Present ⇒ overwrite. Absent ⇒ untouched. |
| `week_count` | required | optional | Same. |
| `start_date` | optional | optional | `YYYY-MM-DD` or `null`. Present ⇒ overwrite (`null` clears). Absent ⇒ untouched. |
| `archived_at` | optional | optional | Present (including `null` = un-archive) ⇒ overwrite. Absent ⇒ untouched. |
| `device_id` | optional | optional | Unchanged. |
| `metadata` | — | — | **Removed.** Old-format write ⇒ 503 (§6.3). |

Feed `programs[]` entries: `program_uuid`, `name`, `week_count`, `start_date`, `archived_at`, `updated_at`.
There is no `metadata`.

---

## 6. Cutover — converting old data automatically

### 6.1 Server data: once, at deploy

One migration, in this order:
1. Add `start_date`.
2. Backfill it: `UPDATE prism_programs SET start_date = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.startDate'))
   WHERE JSON_EXTRACT(metadata, '$.startDate') IS NOT NULL`. Add a guard so only valid `YYYY-MM-DD` values are
   copied.
3. Drop `metadata`.

`down()` re-adds `metadata` and rebuilds `{ "startDate": … }` from `start_date`. The `program` and
`imported_at` keys can't be restored; that is acceptable.

Do **not** bump `updated_at` in the backfill. Every client re-reads programs after its local conversion
(§9.1), so there is no need to re-send every row through the feed.

### 6.2 Data in each browser: Prism converts it on first load of the new build

Prism already has a load-time conversion step in `profileService.initialize()` (the uuid backfill,
storage-schema rule 8). The Prism follow-up adds a step there that:
- moves `data.metadata.startDate` to the new profile field and drops `data.metadata` entirely;
- rewrites persisted `KEYS.SYNC_QUEUE` items with `op: 'program'` from `{ name, weekCount, metadata, archivedAt }`
  to the new shape (`start_date` taken from `metadata.startDate`), so edits made offline before the cutover
  still go through.

### 6.3 Tabs still on the old build: hold their edits, don't drop them

Constraint found in Prism: `syncQueue.flushQueue` **drops** an item on 422 or any other 4xx. It **keeps and
retries** on 429/5xx, with capped backoff and without blocking other queued items.

So a program write containing a `metadata` key is answered with **`503` and a `Retry-After` header**, not 422:
- An old tab keeps retrying and loses nothing.
- When the coach reloads, §6.2 rewrites the queued item and it succeeds.
- Prism's update prompt (`useUpdateCheck`, which checks `version.json` on focus and on an interval) is already
  asking them to reload. They can dismiss it, but nothing is lost.

This is not compatibility code: the server never accepts the old format. Implement it as a few lines at the
top of `StorePrismProgramRequest` (`prepareForValidation` throws an `HttpException(503)` when
`$this->has('metadata')`), with a comment pointing to this section. Log each hit, and delete the check once
those log lines have stopped for a couple of weeks.

Old tabs also stop seeing new start dates and links until they reload. The feed no longer carries
`metadata`, and old builds ignore unknown sections. Accepted.

### 6.4 Accepted side effects

- **Queued old-format edits are full copies of the program.** When converted and replayed, they can overwrite
  newer changes from other coaches, exactly as today. This is a one-time effect limited to edits that were
  queued at cutover.
- **During the cutover window, writes from old tabs are held** (503) rather than applied.

---

## 7. Rollout

1. **Production check:** run the §2.1 key query. Continue only if the keys are `program`, `startDate`,
   `imported_at`.
2. **Ship Logger:** the §2–§6 migrations, endpoints, the 503 check, and the §10 tests.
3. **Ship Prism right after** (§9). Prism must not go first: the old Logger requires `name` and `week_count`
   and would answer partial writes with 422, so those edits would be dropped.
4. **Monitor** the 503 log line. Remove the check once it stops appearing.

Rollback: revert Logger, which runs the migration `down()` and restores `startDate` into `metadata`. Prism
must be rolled back with it, because the new Prism build only speaks the new contract.

---

## 8. Google Sheets access across coaches (decision: Picker grant)

Prism's Google client uses the `drive.file` scope with an access token kept in memory
(`googleSheetsExport.js`). Under `drive.file`, Prism can only access files that the **same Google user**
created or opened with Prism. So when Coach A links a sheet and Coach B sees the link through sync:

- B can **open** the sheet in Google if A shared it with them in Drive.
- B's Prism **can't write** to it until B opens that file once through the **Google Picker**, which gives B's
  Prism access to that one file. The sheet must also be shared with B in Drive.

**Decision:** keep `drive.file` (no Google verification review). When B publishes to a linked sheet they
haven't granted yet, Prism shows the Picker once, pre-filtered to that spreadsheet. If Google reports that B
has no Drive access, Prism shows "Ask {linked_by.name} to share this sheet with you."

Effect on Logger: none beyond §4. The server stores the link and who made it, and never calls Google. Rejected
alternatives: full `drive`/`spreadsheets` scope (sensitive; needs Google verification), and server-side
publishing with a service account or refresh token (moves away from the browser-only design in
`docs/export-google-sheets.md`).

---

## 9. Prism follow-ups (separate repo; listed for order, not part of this change)

1. **Local conversion on load** (§6.2): profiles and the sync queue.
2. **Partial program pushes:**
   - rename ⇒ `{ name }`
   - start date ⇒ `{ start_date }`
   - archive ⇒ `{ archived_at }`
   - the full payload only on create.

   This also fixes the existing bug that renames are never pushed (`profileService.renameProfile` sets no
   commit hint, so `deriveChangeSignal` returns `scope: 'none'`).
3. **Apply incoming program fields on pull** for programs a device already has (name, week count, start date,
   archived), unless a push for that program is pending (the `hasPendingProgramsPush` guard). Today
   `resolveSyncPull` only applies archive status.
4. **Destinations:**
   - add `google_sheets` to `publishDestinationRegistry`;
   - persist the link after export through the destinations endpoint;
   - apply the `destinations` feed section;
   - publish into the linked sheet instead of creating a new one;
   - handle the §8 Picker grant and "no access" states.

---

## 10. Tests (Logger)

`tests/Feature/Sync/Prism/PrismProgramStoreTest.php`:
1. Create with `name` + `week_count` (+ optional `start_date`, `archived_at`) stores the row.
2. Create without `name` or `week_count` ⇒ 422; update without them ⇒ 200.
3. Update with only `name` leaves `week_count`, `start_date`, `archived_at` unchanged (F1).
4. Update with only `archived_at` leaves the other fields unchanged; an explicit `null` un-archives (F4).
5. `start_date` set, then cleared with `null`; omitting it leaves it untouched (F2).
6. An invalid `start_date` (`2026-13-01`, `02-11-2026`) ⇒ 422.
7. A request containing `metadata` ⇒ 503 with `Retry-After`, and nothing is written.
8. A create that hits a unique-constraint race is applied as an update (simulate by inserting the row between
   lookup and create, e.g. with a model `creating` hook in the test).
9. Every accepted write bumps `updated_at`, including an identical repeat.

`tests/Feature/Sync/Prism/PrismProgramDestinationsStoreTest.php` (new):
10. Link creates a row with `linked_by_user_id` = the authenticated user and `linked_at` set.
11. Re-linking the same type replaces the Google fields and clears `deleted_at`.
12. Unlink writes a tombstone; unlinking a missing type is a no-op (200).
13. Unknown `type` ⇒ 422; `google_sheets` without `spreadsheet_id` / `sheet_id` ⇒ 422.
14. A program's destination change doesn't touch the program row, and vice versa (F3).
15. Two programs can link the same `spreadsheet_id` with different `sheet_id`s.

`tests/Feature/Sync/Prism/PrismChangesTest.php`:
16. `programs[]` has `start_date` and no `metadata`.
17. `destinations[]` includes links and tombstones since the cursor, with `linked_by { id, name }`.
18. The cursor advances on destination `updated_at` and `deleted_at`.

Migration test:
19. A row with `metadata: { program, startDate, imported_at }` ends up with `start_date` set and no `metadata`
    column; a row with no or invalid `startDate` ends up with `start_date = null`.
