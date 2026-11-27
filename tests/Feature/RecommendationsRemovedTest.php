<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RecommendationsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::factory()->create(['name' => 'Admin']));

        return $admin;
    }

    public function test_recommendations_page_is_not_found_for_an_admin(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/recommendations')
            ->assertNotFound();
    }

    public function test_recommendations_page_is_not_found_for_a_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/recommendations')
            ->assertNotFound();
    }

    public function test_recommendations_route_name_is_not_registered(): void
    {
        $this->assertFalse(Route::has('recommendations.index'));
    }

    public function test_recommendation_classes_are_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/RecommendationController.php'));
        $this->assertFileDoesNotExist(app_path('Services/RecommendationEngine.php'));
        $this->assertFileDoesNotExist(app_path('Services/ActivityAnalysisService.php'));
        $this->assertFileDoesNotExist(app_path('Services/UserActivityAnalysis.php'));
    }

    public function test_recommendations_view_is_gone(): void
    {
        $this->assertFalse(view()->exists('recommendations.index'));
    }

    public function test_no_remaining_class_references_the_removed_ones(): void
    {
        $needles = ['RecommendationController', 'RecommendationEngine', 'ActivityAnalysisService', 'UserActivityAnalysis', 'recommendations.'];
        $offenders = [];

        foreach (['app', 'config', 'routes', 'resources'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = $file->getPathname().' names '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_admin_settings_menu_renders_without_recommendations(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee(url('/recommendations'), false);
    }
}
