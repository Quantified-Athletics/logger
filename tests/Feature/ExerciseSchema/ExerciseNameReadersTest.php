<?php

namespace Tests\Feature\ExerciseSchema;

use App\Models\Exercise;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use App\Services\ExerciseMergeService;
use App\Sync\Services\ExerciseReconciler;
use App\Sync\Services\ExerciseResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * P4b step 2 (#35): every reader and writer of an exercise's display name uses `name`. The sync
 * API's wire format stays as it is: `POST /exercises` still takes `title`, the reconciler's op keys
 * stay, and the changes and restore payloads send the same `name` and `exerciseName` values.
 */
class ExerciseNameReadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Patterns that read or write the exercise's old column. Each is checked on every line of every
     * PHP and Blade file under app/ and resources/.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_PATTERNS = [
        'a title property read or written' => '/\$\w+->title\b|>exercise->title\b/',
        'a query or collection call on title' => '/(where|orderBy|select|pluck|sortBy|groupBy|keyBy|latest|oldest|value|get)\w*\(\s*\[?\s*(\'[\w.]+\'\s*,\s*)*\'title\'/i',
        'an exercise array read by title' => '/\$\w*exercise\w*\[\'title\'\]/i',
        'an eager load of the title column' => '/exercises?:[\w,]*\btitle\b/',
        'a raw query on the title column' => '/LOWER\(title\)/',
        'a dotted exercise title' => '/exercises?\.title\b/',
        'a title sort key' => '/\[\s*\'title\'\s*,\s*\'(asc|desc)\'\s*\]/',
    ];

    /**
     * Lines that match a pattern above but are not the exercise's column: the Prism destination's
     * own title and the component builders' page titles.
     *
     * @var array<int, string>
     */
    private const ALLOWED_LINES = [
        '$destination->title',
        '$this->title',
    ];

    /**
     * Files that create or update exercises. In these a `'title'` key is the exercise's column unless
     * it is one of the allowed input keys below.
     *
     * @var array<int, string>
     */
    private const EXERCISE_WRITERS = [
        'app/Actions/Exercises/CreateExerciseAction.php',
        'app/Actions/Exercises/UpdateExerciseAction.php',
        'app/Console/Commands/CreateDemoUser.php',
        'app/Console/Commands/ImportJsonLiftLog.php',
        'app/Console/Commands/ImportWodifyLiftLog.php',
        'app/Console/Commands/PersistGlobalExercises.php',
        'app/Http/Controllers/ExerciseMatchingAliasController.php',
        'app/Http/Controllers/SimpleWorkoutController.php',
        'app/Services/ExerciseFormService.php',
        'app/Services/ExerciseMergeService.php',
        'app/Services/MobileEntry/ExerciseCreationService.php',
        'app/Sync/Controllers/ExerciseController.php',
        'app/Sync/Services/ExerciseReconciler.php',
        'app/Sync/Services/ExerciseResolverService.php',
    ];

    /**
     * Input keys named `title` that stay: the sync endpoint's wire key, the reconciler's op keys, the
     * merge map's target key, the global exercises CSV's own column, and the web form's validation
     * label, which keeps its messages' words ("The title field is required.").
     *
     * @var array<int, string>
     */
    private const ALLOWED_TITLE_INPUTS = [
        '/\$request->(input|filled|has)\(\'title\'/',
        '/\'title\' => \[?\'required/',
        '/\$op\[\'(title|new_title|old_title)\'\]/',
        '/\$merge\[\'title\'\]/',
        '/\[\'name\' => \'title\'\]/',
        '/\$(rowData|csvData|csvEntry)\[\'title\'\]/',
        '/\$(header|requiredColumns) = \[\'title\'/',
        '/\'title\' => \$exercise->name\b/',
        '/\$differences\[\'title\'\]/',
    ];

    private User $user;

    /** @var array<string, string> */
    private array $syncHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $token = $this->user->createToken('device-a')->plainTextToken;
        $this->syncHeaders = ['Authorization' => 'Bearer '.$token, 'X-Device-Id' => 'device-a'];
    }

    #[Test]
    public function no_code_in_app_or_resources_reads_or_writes_the_exercise_title(): void
    {
        $offences = [];

        foreach ([app_path(), resource_path()] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname()) as $index => $line) {
                    if ($this->isAllowedLine($line)) {
                        continue;
                    }

                    foreach (self::FORBIDDEN_PATTERNS as $label => $pattern) {
                        if (preg_match($pattern, $line)) {
                            $offences[] = $this->relative($file->getPathname()).':'.($index + 1)." ({$label}): ".trim($line);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offences, "Exercise title still used:\n".implode("\n", $offences));
    }

    #[Test]
    public function no_exercise_writer_keys_the_exercise_by_title(): void
    {
        $offences = [];

        foreach (self::EXERCISE_WRITERS as $path) {
            foreach (file(base_path($path)) as $index => $line) {
                if (! str_contains($line, "'title'")) {
                    continue;
                }

                foreach (self::ALLOWED_TITLE_INPUTS as $allowed) {
                    if (preg_match($allowed, $line)) {
                        continue 2;
                    }
                }

                $offences[] = $path.':'.($index + 1).': '.trim($line);
            }
        }

        $this->assertSame([], $offences, "Exercise writer still keys 'title':\n".implode("\n", $offences));
    }

    #[Test]
    public function the_sync_endpoint_given_only_a_title_creates_an_exercise_with_that_name(): void
    {
        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Zercher Squat',
            'canonical_name' => 'zercher_squat',
            'log_type' => 'barbell',
        ])->assertStatus(201)->assertJson(['status' => 'ok', 'canonical_name' => 'zercher_squat']);

        $exercise = Exercise::where('canonical_name', 'zercher_squat')->sole();
        $this->assertSame('Zercher Squat', $exercise->name);
        $this->assertSame($this->user->id, $exercise->user_id);
    }

    #[Test]
    public function the_sync_endpoint_still_requires_a_title(): void
    {
        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'name' => 'Zercher Squat',
            'canonical_name' => 'zercher_squat',
        ])->assertStatus(422);

        $this->assertSame(0, Exercise::where('canonical_name', 'zercher_squat')->count());
    }

    #[Test]
    public function the_sync_endpoint_prefers_name_when_both_are_sent(): void
    {
        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Old Label',
            'name' => 'Zercher Squat',
            'canonical_name' => 'zercher_squat',
        ])->assertStatus(201);

        $this->assertSame('Zercher Squat', Exercise::where('canonical_name', 'zercher_squat')->sole()->name);
    }

    #[Test]
    public function the_sync_endpoint_update_renames_the_exercise_from_its_title(): void
    {
        $exercise = Exercise::factory()->create([
            'name' => 'Zercher',
            'canonical_name' => 'zercher_squat',
            'user_id' => $this->user->id,
        ]);

        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Zercher Squat',
            'canonical_name' => 'zercher_squat',
        ])->assertStatus(200)->assertJson(['exercise_id' => $exercise->id]);

        $this->assertSame('Zercher Squat', $exercise->refresh()->name);
    }

    #[Test]
    public function the_changes_response_sends_the_exercise_name_as_name_and_exercise_name(): void
    {
        $this->logAgainst($this->userExercise());

        $response = $this->withHeaders($this->syncHeaders)->getJson('/api/sync/changes')->assertOk();

        $this->assertSame('Zercher Squat', $response->json('logs.0.exerciseName'));
        $this->assertSame([['id' => 'zercher_squat', 'name' => 'Zercher Squat']], $this->idAndName($response->json('userExercises')));
    }

    #[Test]
    public function the_restore_response_sends_the_exercise_name_as_name_and_exercise_name(): void
    {
        $this->logAgainst($this->userExercise());

        $response = $this->withHeaders($this->syncHeaders)->getJson('/api/sync/restore')->assertOk();

        $this->assertSame('Zercher Squat', $response->json('logs.0.exerciseName'));
        $this->assertSame([['id' => 'zercher_squat', 'name' => 'Zercher Squat']], $this->idAndName($response->json('userExercises')));
    }

    #[Test]
    public function the_resolver_matches_a_global_exercise_by_name_case_insensitively(): void
    {
        $global = Exercise::factory()->create(['name' => 'Back Squat', 'canonical_name' => 'back_squat_global', 'user_id' => null]);

        $resolved = app(ExerciseResolverService::class)->resolve('back squat', $this->user);

        $this->assertSame($global->id, $resolved->id);
        $this->assertSame('back squat', $resolved->refresh()->name);
    }

    #[Test]
    public function the_reconciler_keeps_its_title_op_keys_and_writes_name(): void
    {
        $exercise = Exercise::factory()->create(['name' => 'Test Exercise', 'canonical_name' => 'test_ex', 'user_id' => null]);
        $file = 'exercise-name-readers-test.json';
        $path = database_path("changesets/exercises/{$file}");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['operations' => [[
            'action' => 'rename',
            'canonical_name' => 'test_ex',
            'old_title' => 'Test Exercise',
            'new_title' => 'Test Exercise Renamed',
        ], [
            'action' => 'add_exercise',
            'canonical_name' => 'new_ex',
            'title' => 'New Exercise',
        ]]]));

        try {
            ExerciseReconciler::apply($file);

            $this->assertSame('Test Exercise Renamed', $exercise->refresh()->name);
            $this->assertSame('New Exercise', Exercise::where('canonical_name', 'new_ex')->sole()->name);
        } finally {
            File::delete($path);
        }
    }

    #[Test]
    public function a_new_merge_log_snapshot_records_name(): void
    {
        $target = Exercise::factory()->create(['name' => 'Pull Up', 'canonical_name' => 'pull_up', 'user_id' => null]);
        $source = Exercise::factory()->create(['name' => 'Pullup', 'canonical_name' => 'pullup', 'user_id' => null]);

        app(ExerciseMergeService::class)->mergeByMap(['target' => 'pull_up', 'title' => 'Pull-Up', 'sources' => ['pullup']]);

        $log = ExerciseMergeLog::where('source_exercise_id', $source->id)->sole();
        $this->assertSame('Pullup', $log->source_exercise_title);
        $this->assertSame('Pullup', $log->snapshot['source']['name']);
        $this->assertSame('Pull Up', $log->snapshot['target_original']['name']);
        $this->assertArrayNotHasKey('title', $log->snapshot['source']);
        $this->assertSame('Pull-Up', $target->refresh()->name);
    }

    #[Test]
    public function an_activity_entry_written_before_the_change_stays_as_it_was_and_new_ones_record_name(): void
    {
        $exercise = Exercise::factory()->create(['name' => 'Back Squat', 'user_id' => $this->user->id]);
        $old = Activity::create([
            'log_name' => 'default',
            'description' => 'updated',
            'subject_type' => Exercise::class,
            'subject_id' => $exercise->id,
            'properties' => ['attributes' => ['title' => 'Back Squat'], 'old' => ['title' => 'Squat']],
        ]);

        $exercise->update(['name' => 'Front Squat']);

        $this->assertSame(['title' => 'Back Squat'], $old->refresh()->properties['attributes']);
        $newest = Activity::where('subject_id', $exercise->id)->latest('id')->first();
        $this->assertSame('Front Squat', $newest->properties['attributes']['name']);
    }

    #[Test]
    public function the_web_create_form_stores_the_name(): void
    {
        $this->actingAs($this->user)->post(route('exercises.store'), [
            'name' => 'Zercher Squat',
            'log_type' => 'barbell',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Zercher Squat', Exercise::where('user_id', $this->user->id)->sole()->name);
    }

    #[Test]
    public function the_web_create_form_refuses_a_duplicate_name_with_the_same_message(): void
    {
        Exercise::factory()->create(['name' => 'Zercher Squat', 'user_id' => $this->user->id]);

        $this->actingAs($this->user)->post(route('exercises.store'), [
            'name' => 'Zercher Squat',
            'log_type' => 'barbell',
        ])->assertSessionHasErrors(['name' => 'An exercise with this name already exists.']);
    }

    #[Test]
    public function the_web_create_form_without_a_name_keeps_the_required_message_words(): void
    {
        $this->actingAs($this->user)->post(route('exercises.store'), [
            'log_type' => 'barbell',
        ])->assertSessionHasErrors(['name' => 'The title field is required.']);
    }

    private function userExercise(): Exercise
    {
        return Exercise::factory()->create([
            'name' => 'Zercher Squat',
            'canonical_name' => 'zercher_squat',
            'user_id' => $this->user->id,
            'log_type' => 'barbell',
        ]);
    }

    private function logAgainst(Exercise $exercise): void
    {
        $log = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        LiftSet::factory()->create(['lift_log_id' => $log->id, 'weight' => 135, 'reps' => 5, 'unit' => 'lbs']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $exercises
     * @return array<int, array{id: string, name: string}>
     */
    private function idAndName(array $exercises): array
    {
        return array_map(fn (array $exercise): array => ['id' => $exercise['id'], 'name' => $exercise['name']], $exercises);
    }

    private function isAllowedLine(string $line): bool
    {
        foreach (self::ALLOWED_LINES as $allowed) {
            if (str_contains($line, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
