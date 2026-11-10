# Prism program `tab_position` (tab order sync) — plan

**Issue:** #9 (Prism: Quantified-Athletics/prism#617). **Approved:** owner, 2026-11-09 ("Go").
**Path:** fix (one prompt). It needs a migration, so it is past the small-fix limits.

## Why

Prism lets coaches drag program tabs into order. Each program row stores its own opaque sort key, so one drag
rewrites one row and two devices dragging different tabs don't overwrite each other. This is the same
per-field last-writer-wins that `name`, `start_date` and `archived_at` already use.

## What (grounded 2026-11-09 against `main` @ 7e87f74b)

1. **Migration (new, additive):** `prism_programs.tab_position`, `string(64)`, nullable, after `archived_at`.
   `down()` drops the column. No data backfill. Mirror `2026_10_21_102125_add_archived_at_to_prism_programs_table.php`.
2. **Model** `app/Sync/Models/PrismProgram.php`: add `tab_position` to `$fillable`. No cast.
3. **Request** `app/Sync/Requests/StorePrismProgramRequest.php`: rule
   `'tab_position' => ['sometimes', 'nullable', 'string', 'max:64', 'ascii']`. The `metadata` 503 guard is unchanged.
4. **Action** `app/Sync/Actions/UpsertPrismProgramAction.php`: add `tab_position` to `WRITABLE_FIELDS`. The
   existing partial-write logic then gives "absent ⇒ untouched, null ⇒ clear", and the existing
   always-bump of `updated_at` makes the change visible to `/changes` paging. No other action change.
   Creating a program still requires `name` + `week_count`. A `tab_position`-only write to a program that
   doesn't exist yet stays a 422, as it is for every other field.
5. **Pull** `app/Sync/Controllers/PrismChangesController.php`: add `'tab_position' => $program->tab_position`
   to the program row map (null when never set). Return it verbatim. Don't sort by it or interpret it.

## Tests (extend `tests/Feature/Sync/Prism/PrismProgramStoreTest.php` and `PrismChangesTest.php`)

- A write with `tab_position` stores it and returns 200. The next pull returns it.
- A `tab_position`-only write to an existing program changes only that field: name, week_count, start_date
  and archived_at stay as they were, and `updated_at` moves forward.
- A write that omits `tab_position` leaves the stored value as it was.
- `null` clears it. The pull returns `null` for a program that never had one.
- Rejected with 422 on `tab_position`: 65 characters, a non-string (an integer or an array), a non-ASCII string.
  Exactly 64 characters is accepted.
- Case is kept exactly as sent (`"a0V"` comes back as `"a0V"`).
- The existing `metadata` 503 test still passes unchanged.

## Verification

The full suite runs (`php artisan test --parallel`). The migration is additive and nullable with a reversible
`down()`, so it is safe to auto-apply on deploy. Green tests only prove it on SQLite. Nothing here needs
checking in the running app: this is API-only.

## Cross-app

The pull response gains a field (additive). Prism will mirror it in its fake Logger and contract tests once
this lands. The owner checks the cross-app contract.
