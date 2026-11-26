<?php

namespace Tests\Feature;

use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class GeminiApiKeyConfigTest extends TestCase
{
    use RefreshDatabase;

    private const ANALYZE_MISSING_KEY = 'Gemini API key is not configured. Set the GEMINI_API_KEY environment variable.';

    private const GENERATE_MISSING_KEY = 'Gemini API key is required. Set the GEMINI_API_KEY environment variable or pass the --api-key option.';

    private string|false $originalEnvValue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvValue = getenv('GEMINI_API_KEY');
        putenv('GEMINI_API_KEY');
        unset($_ENV['GEMINI_API_KEY'], $_SERVER['GEMINI_API_KEY']);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvValue !== false) {
            putenv('GEMINI_API_KEY=' . $this->originalEnvValue);
        }

        parent::tearDown();
    }

    /** @test */
    public function no_code_outside_config_calls_env(): void
    {
        $offenders = [];

        $files = Finder::create()
            ->files()
            ->name('*.php')
            ->in(array_map(fn (string $directory): string => base_path($directory), ['app', 'routes', 'database', 'bootstrap']))
            ->exclude('cache');

        foreach ($files as $file) {
            if (preg_match('/(?<![\w$>:])env\s*\(/', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Files outside config/ that call env(); read the value through config() instead');
    }

    /** @test */
    public function analyze_recent_workouts_reads_the_key_from_config(): void
    {
        config(['services.gemini.api_key' => 'config-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Looking strong.']]]]],
        ])]);
        $user = $this->userWithRecentLiftLog();

        $this->artisan('workouts:analyze-recent', ['user_id' => $user->id])
            ->expectsQuestion('Ask a follow-up question (or type "exit" to quit)', 'exit')
            ->doesntExpectOutput(self::ANALYZE_MISSING_KEY)
            ->expectsOutput('Looking strong.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=config-key'));
    }

    /** @test */
    public function analyze_recent_workouts_fails_when_the_config_key_is_missing(): void
    {
        config(['services.gemini.api_key' => null]);
        $user = $this->userWithRecentLiftLog();

        $this->artisan('workouts:analyze-recent', ['user_id' => $user->id])
            ->expectsOutput(self::ANALYZE_MISSING_KEY)
            ->assertFailed();

        Http::assertNothingSent();
    }

    /** @test */
    public function generate_exercise_intelligence_reads_the_key_from_config(): void
    {
        config(['services.gemini.api_key' => 'config-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->artisan('exercises:generate-intelligence')
            ->doesntExpectOutput(self::GENERATE_MISSING_KEY)
            ->expectsOutput('Failed to detect available models. Please specify --model manually.')
            ->assertFailed();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=config-key'));
    }

    /** @test */
    public function generate_exercise_intelligence_fails_when_the_config_key_is_missing(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->artisan('exercises:generate-intelligence')
            ->expectsOutput(self::GENERATE_MISSING_KEY)
            ->assertFailed();

        Http::assertNothingSent();
    }

    /** @test */
    public function generate_exercise_intelligence_prefers_the_api_key_option_over_config(): void
    {
        config(['services.gemini.api_key' => 'config-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->artisan('exercises:generate-intelligence', ['--api-key' => 'option-key'])
            ->expectsOutput('Failed to detect available models. Please specify --model manually.')
            ->assertFailed();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=option-key'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'key=config-key'));
    }

    private function userWithRecentLiftLog(): User
    {
        $user = User::factory()->create();
        LiftLog::factory()->create(['user_id' => $user->id, 'logged_at' => now()->subDay()]);

        return $user;
    }
}
