<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismProgramComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrismCommentsTest extends TestCase
{
    use RefreshDatabase;

    private const COMMENT = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f';

    private const WORKOUT = '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f';

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
    }

    private function postComments(array $payload)
    {
        return $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/comments', $payload);
    }

    private function add(string $body = 'Too much volume'): void
    {
        $this->postComments(['upserts' => [['uuid' => self::COMMENT, 'workout_uuid' => self::WORKOUT, 'body' => $body]]])
            ->assertStatus(200);
    }

    public function test_a_comment_is_created_with_the_signed_in_user_as_author(): void
    {
        $user = User::factory()->create(['name' => 'Maurits Dekkers']);
        Sanctum::actingAs($user);

        $this->add();

        $comment = PrismProgramComment::first();
        $this->assertEquals('prog-123', $comment->program_uuid);
        $this->assertEquals(self::WORKOUT, $comment->workout_uuid);
        $this->assertEquals('Too much volume', $comment->body);
        $this->assertEquals($user->id, $comment->author_user_id);
        $this->assertNull($comment->deleted_at);
    }

    public function test_the_author_can_edit_delete_and_restore_their_comment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->add();

        $this->add('People loved this one');
        $this->assertEquals('People loved this one', PrismProgramComment::first()->body);

        $this->postComments(['removals' => [['uuid' => self::COMMENT]]])->assertStatus(200);
        $this->assertNotNull(PrismProgramComment::first()->deleted_at);

        $this->add('People loved this one');
        $this->assertNull(PrismProgramComment::first()->deleted_at);
        $this->assertDatabaseCount('prism_program_comments', 1);
    }

    public function test_someone_else_cannot_edit_or_delete_a_comment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->add();

        Sanctum::actingAs(User::factory()->create());
        $this->postComments(['upserts' => [['uuid' => self::COMMENT, 'workout_uuid' => self::WORKOUT, 'body' => 'Hijacked']]])
            ->assertStatus(403);
        $this->postComments(['removals' => [['uuid' => self::COMMENT]]])->assertStatus(403);

        $comment = PrismProgramComment::first();
        $this->assertEquals('Too much volume', $comment->body);
        $this->assertNull($comment->deleted_at);
    }

    public function test_a_refused_batch_writes_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->add();

        Sanctum::actingAs(User::factory()->create());
        $this->postComments(['upserts' => [
            ['uuid' => '0b1c2d3e-4f5a-4b6c-8d7e-9f0a1b2c3d4e', 'workout_uuid' => self::WORKOUT, 'body' => 'Mine'],
            ['uuid' => self::COMMENT, 'workout_uuid' => self::WORKOUT, 'body' => 'Not mine'],
        ]])->assertStatus(403);

        $this->assertDatabaseCount('prism_program_comments', 1);
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postComments(['upserts' => [], 'removals' => []])->assertStatus(422);
        $this->postComments(['upserts' => [['uuid' => 'nope', 'workout_uuid' => self::WORKOUT, 'body' => 'x']]])->assertStatus(422);
        $this->postComments(['upserts' => [['uuid' => self::COMMENT, 'workout_uuid' => self::WORKOUT, 'body' => str_repeat('a', 5001)]]])
            ->assertStatus(422);
        $this->assertDatabaseCount('prism_program_comments', 0);
    }

    public function test_unauthenticated_and_unknown_gym_are_refused(): void
    {
        $this->postComments(['upserts' => [['uuid' => self::COMMENT, 'workout_uuid' => self::WORKOUT, 'body' => 'x']]])->assertStatus(401);

        Sanctum::actingAs(User::factory()->create());
        $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson('/api/sync/prism/nowhere/programs/prog-123/comments', ['upserts' => [['uuid' => self::COMMENT, 'workout_uuid' => self::WORKOUT, 'body' => 'x']]])
            ->assertStatus(404);
    }

    public function test_changes_return_comments_with_the_authors_full_name_and_tombstones(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Maurits Dekkers', 'email' => 'coach@example.com']));
        $this->add();

        $comments = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes')
            ->assertStatus(200)
            ->json('comments');

        $this->assertCount(1, $comments);
        $this->assertEquals(self::COMMENT, $comments[0]['uuid']);
        $this->assertEquals('prog-123', $comments[0]['program_uuid']);
        $this->assertEquals(self::WORKOUT, $comments[0]['workout_uuid']);
        $this->assertEquals('Too much volume', $comments[0]['body']);
        $this->assertEquals('Maurits Dekkers', $comments[0]['author_name']);
        $this->assertEquals('coach@example.com', $comments[0]['author_email']);
        $this->assertNull($comments[0]['deleted_at']);

        $this->postComments(['removals' => [['uuid' => self::COMMENT]]])->assertStatus(200);
        $after = $this->withHeaders(['X-Device-Id' => 'dev-1'])->getJson('/api/sync/prism/the-cove/changes')->json('comments');
        $this->assertNotNull($after[0]['deleted_at']);
    }
}
