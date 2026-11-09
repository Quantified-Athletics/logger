<?php

namespace App\Sync\Controllers;

use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Models\PrismExerciseCap;
use App\Sync\Models\PrismExerciseFamily;
use App\Sync\Models\PrismExerciseFamilyMember;
use App\Sync\Models\PrismExerciseOverlayRecord;
use App\Sync\Models\PrismInterferenceDismissal;
use App\Sync\Models\PrismPreference;
use App\Sync\Models\PrismProgram;
use App\Sync\Models\PrismProgramCell;
use App\Sync\Models\PrismProgramComment;
use App\Sync\Models\PrismProgramDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PrismChangesController
{
    use ValidatesGym;

    /**
     * Return gym-scoped changes and cursor for Prism sync.
     *
     * GET /api/sync/prism/{gym}/changes?since=<cursor>
     */
    public function index(Request $request, string $gym): JsonResponse
    {
        $this->findGymOrFail($gym);

        $since = null;
        if ($request->filled('since')) {
            $request->validate([
                'since' => 'date',
            ]);
            $since = Carbon::parse($request->query('since'));
        }

        // 1. Programs
        $programsQuery = PrismProgram::where('gym_id', $gym);
        if ($since !== null) {
            $programsQuery->where('updated_at', '>=', $since);
        }
        $programs = $programsQuery->get()->map(function (PrismProgram $program) {
            return [
                'program_uuid' => $program->program_uuid,
                'name' => $program->name,
                'week_count' => (int) $program->week_count,
                'start_date' => $program->start_date === null ? null : substr((string) $program->start_date, 0, 10),
                'archived_at' => $program->archived_at?->toIso8601String(),
                'tab_position' => $program->tab_position,
                'updated_at' => $program->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 2. Cells
        $cellsQuery = PrismProgramCell::where('gym_id', $gym);
        if ($since !== null) {
            $cellsQuery->where('updated_at', '>=', $since);
        }
        $cells = $cellsQuery->get()->map(function (PrismProgramCell $cell) {
            return [
                'program_uuid' => $cell->program_uuid,
                'cell_id' => $cell->cell_id,
                'workout_uuid' => $cell->workout_uuid,
                'text' => $cell->text,
                'deleted_at' => $cell->deleted_at?->toIso8601String(),
                'updated_at' => $cell->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 3. Exercises (overlays + tombstones)
        $exercisesQuery = PrismExerciseOverlayRecord::where('gym_id', $gym);
        if ($since !== null) {
            $exercisesQuery->where('updated_at', '>=', $since);
        }
        $exercises = $exercisesQuery->get()->map(function (PrismExerciseOverlayRecord $record) {
            return [
                'exercise_id' => $record->exercise_id,
                'record' => $record->record,
                'deleted_at' => $record->deleted_at?->toIso8601String(),
                'updated_at' => $record->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 4. Preferences
        $preferencesQuery = PrismPreference::where('gym_id', $gym);
        if ($since !== null) {
            $preferencesQuery->where('updated_at', '>=', $since);
        }
        $pref = $preferencesQuery->first();
        $preferences = $pref ? [
            'data' => $pref->data,
            'updated_at' => $pref->updated_at->toIso8601String(),
        ] : null;

        // 5. Dismissals (interference dismissals + tombstones)
        $dismissalsQuery = PrismInterferenceDismissal::where('gym_id', $gym);
        if ($since !== null) {
            $dismissalsQuery->where('updated_at', '>=', $since);
        }
        $dismissals = $dismissalsQuery->get()->map(function (PrismInterferenceDismissal $dismissal) {
            return [
                'resolution_key' => $dismissal->resolution_key,
                'deleted_at' => $dismissal->deleted_at?->toIso8601String(),
                'updated_at' => $dismissal->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 6. Program destinations (publish links + tombstones)
        $destinationsQuery = PrismProgramDestination::where('gym_id', $gym)->with('linkedBy:id,name');
        if ($since !== null) {
            $destinationsQuery->where('updated_at', '>=', $since);
        }
        $destinations = $destinationsQuery->get()->map(function (PrismProgramDestination $destination) {
            return [
                'program_uuid' => $destination->program_uuid,
                'type' => $destination->type,
                'spreadsheet_id' => $destination->spreadsheet_id,
                'sheet_id' => $destination->sheet_id,
                'title' => $destination->title,
                'linked_by' => $destination->linkedBy === null ? null : [
                    'id' => $destination->linkedBy->id,
                    'name' => $destination->linkedBy->name,
                ],
                'linked_at' => $destination->linked_at?->toIso8601String(),
                'deleted_at' => $destination->deleted_at?->toIso8601String(),
                'updated_at' => $destination->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 7. Comments (coach comments on workouts + tombstones), with the author's full name
        $commentsQuery = PrismProgramComment::where('gym_id', $gym)->with('author:id,name,email');
        if ($since !== null) {
            $commentsQuery->where('updated_at', '>=', $since);
        }
        $comments = $commentsQuery->get()->map(function (PrismProgramComment $comment) {
            return [
                'uuid' => $comment->uuid,
                'program_uuid' => $comment->program_uuid,
                'workout_uuid' => $comment->workout_uuid,
                'body' => $comment->body,
                'author_name' => $comment->author?->name,
                'author_email' => $comment->author?->email,
                'created_at' => $comment->created_at->toIso8601String(),
                'deleted_at' => $comment->deleted_at?->toIso8601String(),
                'updated_at' => $comment->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 8. Exercise families (family names, caps and tombstones)
        $exerciseFamiliesQuery = PrismExerciseFamily::where('gym_id', $gym);
        if ($since !== null) {
            $exerciseFamiliesQuery->where('updated_at', '>=', $since);
        }
        $exerciseFamilies = $exerciseFamiliesQuery->get()->map(function (PrismExerciseFamily $family) {
            return [
                'family_id' => $family->family_id,
                'name' => $family->name,
                'cap_count' => $family->cap_count,
                'cap_period' => $family->cap_period,
                'deleted_at' => $family->deleted_at?->toIso8601String(),
                'updated_at' => $family->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 9. Exercise family members (membership and tombstones)
        $exerciseFamilyMembersQuery = PrismExerciseFamilyMember::where('gym_id', $gym);
        if ($since !== null) {
            $exerciseFamilyMembersQuery->where('updated_at', '>=', $since);
        }
        $exerciseFamilyMembers = $exerciseFamilyMembersQuery->get()->map(function (PrismExerciseFamilyMember $member) {
            return [
                'exercise_id' => $member->exercise_id,
                'family_id' => $member->family_id,
                'is_member' => $member->is_member,
                'deleted_at' => $member->deleted_at?->toIso8601String(),
                'updated_at' => $member->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 10. Exercise caps (frequency caps and tombstones)
        $exerciseCapsQuery = PrismExerciseCap::where('gym_id', $gym);
        if ($since !== null) {
            $exerciseCapsQuery->where('updated_at', '>=', $since);
        }
        $exerciseCaps = $exerciseCapsQuery->get()->map(function (PrismExerciseCap $cap) {
            return [
                'exercise_id' => $cap->exercise_id,
                'cap_count' => $cap->cap_count,
                'cap_period' => $cap->cap_period,
                'deleted_at' => $cap->deleted_at?->toIso8601String(),
                'updated_at' => $cap->updated_at->toIso8601String(),
            ];
        })->values()->all();

        // 11. Compute cursor high-water-mark across all gym rows
        $maxProgramUpdated = PrismProgram::where('gym_id', $gym)->max('updated_at');
        $maxCellUpdated = PrismProgramCell::where('gym_id', $gym)->max('updated_at');
        $maxExerciseUpdated = PrismExerciseOverlayRecord::where('gym_id', $gym)->max('updated_at');
        $maxPreferenceUpdated = PrismPreference::where('gym_id', $gym)->max('updated_at');
        $maxDismissalUpdated = PrismInterferenceDismissal::where('gym_id', $gym)->max('updated_at');
        $maxDestinationUpdated = PrismProgramDestination::where('gym_id', $gym)->max('updated_at');
        $maxCommentUpdated = PrismProgramComment::where('gym_id', $gym)->max('updated_at');
        $maxFamilyUpdated = PrismExerciseFamily::where('gym_id', $gym)->max('updated_at');
        $maxFamilyMemberUpdated = PrismExerciseFamilyMember::where('gym_id', $gym)->max('updated_at');
        $maxExerciseCapUpdated = PrismExerciseCap::where('gym_id', $gym)->max('updated_at');

        $updatedInstants = array_filter([
            $maxProgramUpdated ? Carbon::parse($maxProgramUpdated) : null,
            $maxCellUpdated ? Carbon::parse($maxCellUpdated) : null,
            $maxExerciseUpdated ? Carbon::parse($maxExerciseUpdated) : null,
            $maxPreferenceUpdated ? Carbon::parse($maxPreferenceUpdated) : null,
            $maxDismissalUpdated ? Carbon::parse($maxDismissalUpdated) : null,
            $maxDestinationUpdated ? Carbon::parse($maxDestinationUpdated) : null,
            $maxCommentUpdated ? Carbon::parse($maxCommentUpdated) : null,
            $maxFamilyUpdated ? Carbon::parse($maxFamilyUpdated) : null,
            $maxFamilyMemberUpdated ? Carbon::parse($maxFamilyMemberUpdated) : null,
            $maxExerciseCapUpdated ? Carbon::parse($maxExerciseCapUpdated) : null,
        ]);

        $maxUpdated = null;
        foreach ($updatedInstants as $instant) {
            if ($maxUpdated === null || $instant->gt($maxUpdated)) {
                $maxUpdated = $instant;
            }
        }

        $rawMaxExerciseDeleted = PrismExerciseOverlayRecord::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');
        $rawMaxCellDeleted = PrismProgramCell::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');
        $rawMaxDismissalDeleted = PrismInterferenceDismissal::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');

        $rawMaxDestinationDeleted = PrismProgramDestination::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');

        $rawMaxFamilyDeleted = PrismExerciseFamily::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');
        $rawMaxFamilyMemberDeleted = PrismExerciseFamilyMember::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');
        $rawMaxExerciseCapDeleted = PrismExerciseCap::where('gym_id', $gym)
            ->whereNotNull('deleted_at')
            ->max('deleted_at');

        $deletedInstants = array_filter([
            $rawMaxExerciseDeleted ? Carbon::parse($rawMaxExerciseDeleted) : null,
            $rawMaxCellDeleted ? Carbon::parse($rawMaxCellDeleted) : null,
            $rawMaxDismissalDeleted ? Carbon::parse($rawMaxDismissalDeleted) : null,
            $rawMaxDestinationDeleted ? Carbon::parse($rawMaxDestinationDeleted) : null,
            $rawMaxFamilyDeleted ? Carbon::parse($rawMaxFamilyDeleted) : null,
            $rawMaxFamilyMemberDeleted ? Carbon::parse($rawMaxFamilyMemberDeleted) : null,
            $rawMaxExerciseCapDeleted ? Carbon::parse($rawMaxExerciseCapDeleted) : null,
        ]);

        $maxDeleted = null;
        foreach ($deletedInstants as $instant) {
            if ($maxDeleted === null || $instant->gt($maxDeleted)) {
                $maxDeleted = $instant;
            }
        }

        $maxInstant = null;
        if ($maxUpdated && $maxDeleted) {
            $maxInstant = $maxUpdated->gte($maxDeleted) ? $maxUpdated : $maxDeleted;
        } elseif ($maxUpdated) {
            $maxInstant = $maxUpdated;
        } elseif ($maxDeleted) {
            $maxInstant = $maxDeleted;
        }

        if ($since !== null && ($maxInstant === null || $since->gt($maxInstant))) {
            $cursorInstant = $since;
        } else {
            $cursorInstant = $maxInstant ?? now();
        }

        $cursor = $cursorInstant->toIso8601String();

        return response()->json([
            'status' => 'ok',
            'cursor' => $cursor,
            'programs' => $programs,
            'cells' => $cells,
            'exercises' => $exercises,
            'preferences' => $preferences,
            'dismissals' => $dismissals,
            'destinations' => $destinations,
            'comments' => $comments,
            'exercise_families' => $exerciseFamilies,
            'exercise_family_members' => $exerciseFamilyMembers,
            'exercise_caps' => $exerciseCaps,
        ]);
    }
}
