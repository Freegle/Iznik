<?php

namespace Tests\Feature\Cleanup;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeduplicateUserImagesCommandTest extends TestCase
{
    private function insertUserImage(int $userId): int
    {
        return DB::table('users_images')->insertGetId([
            'userid' => $userId,
            'url' => 'https://images.example.com/img/' . uniqid() . '.jpg',
            'ouruid' => uniqid(),
        ]);
    }

    public function test_smoke_no_duplicates(): void
    {
        $this->artisan('cleanup:user-images')
            ->expectsOutputToContain('Removed 0 duplicate')
            ->assertExitCode(0);
    }

    public function test_removes_duplicate_images_keeping_latest(): void
    {
        $user = $this->createTestUser();

        $id1 = $this->insertUserImage($user->id);
        $id2 = $this->insertUserImage($user->id);
        $id3 = $this->insertUserImage($user->id);

        $this->artisan('cleanup:user-images')
            ->expectsOutputToContain('Removed 2 duplicate')
            ->assertExitCode(0);

        $remaining = DB::table('users_images')->where('userid', $user->id)->pluck('id')->toArray();
        $this->assertCount(1, $remaining);
        $this->assertContains($id3, $remaining);
        $this->assertNotContains($id1, $remaining);
        $this->assertNotContains($id2, $remaining);
    }

    public function test_skips_user_with_single_image(): void
    {
        $user = $this->createTestUser();
        $id = $this->insertUserImage($user->id);

        $this->artisan('cleanup:user-images')
            ->expectsOutputToContain('Removed 0 duplicate')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_images', ['id' => $id]);
    }

    public function test_handles_multiple_users_independently(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->insertUserImage($user1->id);
        $this->insertUserImage($user1->id);
        $keep1 = $this->insertUserImage($user1->id);

        $this->insertUserImage($user2->id);
        $keep2 = $this->insertUserImage($user2->id);

        $this->artisan('cleanup:user-images')
            ->expectsOutputToContain('Removed 3 duplicate')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_images', ['id' => $keep1]);
        $this->assertDatabaseHas('users_images', ['id' => $keep2]);
        $this->assertEquals(2, DB::table('users_images')->whereIn('userid', [$user1->id, $user2->id])->count());
    }

    public function test_dry_run_does_not_delete(): void
    {
        $user = $this->createTestUser();
        $id1 = $this->insertUserImage($user->id);
        $id2 = $this->insertUserImage($user->id);

        $this->artisan('cleanup:user-images', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would remove 1 duplicate')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_images', ['id' => $id1]);
        $this->assertDatabaseHas('users_images', ['id' => $id2]);
    }
}
