<?php

namespace Tests\Feature\Sync;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhoAmITest extends TestCase
{
    use RefreshDatabase;

    private function bearerHeadersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_admin_user_reads_admin_true(): void
    {
        $user = User::factory()->create(['name' => 'Owner']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        $this->withHeaders($this->bearerHeadersFor($user))
            ->getJson('/api/sync/me')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => 'Owner',
                'admin' => true,
            ]);
    }

    public function test_normal_user_reads_admin_false(): void
    {
        $user = User::factory()->create(['name' => 'Athlete One']);

        $this->withHeaders($this->bearerHeadersFor($user))
            ->getJson('/api/sync/me')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => 'Athlete One',
                'admin' => false,
            ]);
    }

    public function test_user_with_other_roles_only_is_not_admin(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Coach']));

        $this->withHeaders($this->bearerHeadersFor($user))
            ->getJson('/api/sync/me')
            ->assertOk()
            ->assertJsonPath('admin', false);
    }

    public function test_response_has_only_id_name_and_admin(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        $response = $this->withHeaders($this->bearerHeadersFor($user))
            ->getJson('/api/sync/me')
            ->assertOk();

        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['admin', 'id', 'name'], $keys);
        $this->assertIsInt($response->json('id'));
        $this->assertIsString($response->json('name'));
        $this->assertIsBool($response->json('admin'));
        $this->assertStringNotContainsString($user->email, $response->getContent());
    }

    public function test_request_without_token_is_rejected(): void
    {
        $this->getJson('/api/sync/me')->assertUnauthorized();
    }

    public function test_request_with_invalid_token_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/sync/me')
            ->assertUnauthorized();
    }
}
