<?php

namespace Tests\Feature\Cleanup;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArchiveProfileImagesCommandTest extends TestCase
{
    public function test_command_runs_successfully_with_no_duplicates(): void
    {
        $this->artisan('cleanup:archive-profile-images')
            ->assertExitCode(0);
    }

    public function test_command_outputs_deleted_count(): void
    {
        $this->artisan('cleanup:archive-profile-images')
            ->expectsOutputToContain('Deleted 0 duplicate profile images')
            ->assertExitCode(0);
    }

    public function test_deletes_older_images_keeping_latest_per_user(): void
    {
        $user = $this->createTestUser();

        // Insert 3 images for user — IDs are auto-incremented so later inserts = higher IDs
        $id1 = DB::table('users_images')->insertGetId(['userid' => $user->id, 'contenttype' => 'image/jpeg']);
        $id2 = DB::table('users_images')->insertGetId(['userid' => $user->id, 'contenttype' => 'image/jpeg']);
        $id3 = DB::table('users_images')->insertGetId(['userid' => $user->id, 'contenttype' => 'image/jpeg']);

        $this->artisan('cleanup:archive-profile-images')
            ->expectsOutputToContain('Deleted 2 duplicate profile images')
            ->assertExitCode(0);

        // Only the latest (highest id) should remain
        $this->assertDatabaseMissing('users_images', ['id' => $id1]);
        $this->assertDatabaseMissing('users_images', ['id' => $id2]);
        $this->assertDatabaseHas('users_images', ['id' => $id3]);
    }

    public function test_handles_multiple_users_independently(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $u1id1 = DB::table('users_images')->insertGetId(['userid' => $user1->id, 'contenttype' => 'image/jpeg']);
        $u1id2 = DB::table('users_images')->insertGetId(['userid' => $user1->id, 'contenttype' => 'image/jpeg']);

        $u2id1 = DB::table('users_images')->insertGetId(['userid' => $user2->id, 'contenttype' => 'image/jpeg']);
        $u2id2 = DB::table('users_images')->insertGetId(['userid' => $user2->id, 'contenttype' => 'image/jpeg']);
        $u2id3 = DB::table('users_images')->insertGetId(['userid' => $user2->id, 'contenttype' => 'image/jpeg']);

        $this->artisan('cleanup:archive-profile-images')
            ->expectsOutputToContain('Deleted 3 duplicate profile images')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_images', ['id' => $u1id1]);
        $this->assertDatabaseHas('users_images', ['id' => $u1id2]);

        $this->assertDatabaseMissing('users_images', ['id' => $u2id1]);
        $this->assertDatabaseMissing('users_images', ['id' => $u2id2]);
        $this->assertDatabaseHas('users_images', ['id' => $u2id3]);
    }

    public function test_user_with_single_image_is_not_affected(): void
    {
        $user = $this->createTestUser();
        $id = DB::table('users_images')->insertGetId(['userid' => $user->id, 'contenttype' => 'image/jpeg']);

        $this->artisan('cleanup:archive-profile-images')
            ->expectsOutputToContain('Deleted 0 duplicate profile images')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_images', ['id' => $id]);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $user = $this->createTestUser();

        $id1 = DB::table('users_images')->insertGetId(['userid' => $user->id, 'contenttype' => 'image/jpeg']);
        $id2 = DB::table('users_images')->insertGetId(['userid' => $user->id, 'contenttype' => 'image/jpeg']);

        $this->artisan('cleanup:archive-profile-images', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would delete 1 duplicate profile images')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_images', ['id' => $id1]);
        $this->assertDatabaseHas('users_images', ['id' => $id2]);
    }
}
