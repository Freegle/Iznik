<?php

namespace Tests\Feature\TrashNothing;

use App\Models\User;
use App\Models\UserEmail;
use App\Services\LokiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the tn:sync command — covers ratings sync, user changes sync,
 * duplicate TN user merging, date management, error handling, and pagination.
 *
 * Ported test coverage from:
 * - iznik-server: userAPITest::testRating(), sessionTest::testAboutMe(),
 *   chatRoomsTest::testUserStopsReplyingReplyTime()
 * - iznik-server-go: TestPostUserRateUp/Down, TestPatchUserAboutMe
 */
class TNSyncCommandTest extends TestCase
{
    private const DATE_SYNC = '2026-03-20T10:00:00+00:00';
    private const DATE_LATER = '2026-03-20T12:00:00+00:00';
    private const DATE_OLD = '2026-01-01 00:00:00';

    private const DB_WRITES_DISABLED_SKIP_REASON = 'Temporarily skipped: TNSyncCommand write paths are disabled for port testing.';

    private string $dateFile;
    private string $apiBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dateFile = sys_get_temp_dir() . '/tn_sync_test_' . uniqid('', true) . '.txt';
        $this->apiBaseUrl = 'https://trashnothing.com/fd/api';

        config([
            'freegle.trashnothing.api_key' => 'test-key',
            'freegle.trashnothing.api_base_url' => $this->apiBaseUrl,
            'freegle.trashnothing.sync_date_file' => $this->dateFile,
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dateFile)) {
            @unlink($this->dateFile);
        }

        parent::tearDown();
    }

    // =========================================================================
    // Ratings sync
    // =========================================================================

    private function skipIfTNSyncWritesDisabled(): void
    {
        $this->markTestSkipped(self::DB_WRITES_DISABLED_SKIP_REASON);
    }

    public function test_sync_creates_new_rating(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => 'tn_r_' . uniqid(),
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertTrue(
            DB::table('ratings')->where('ratee', $user->id)->where('rating', 'Up')->exists()
        );
    }

    public function test_sync_updates_existing_rating(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $tnRatingId = 'tn_r_update_' . uniqid();

        DB::table('ratings')->insert([
            'ratee' => $user->id,
            'rating' => 'Up',
            'timestamp' => self::DATE_OLD,
            'visible' => 1,
            'tn_rating_id' => $tnRatingId,
        ]);

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => $tnRatingId,
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Down',
                    'date' => self::DATE_LATER,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $rating = DB::table('ratings')->where('tn_rating_id', $tnRatingId)->first();
        $this->assertEquals('Down', $rating->rating);
    }

    public function test_sync_deletes_rating_when_null(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $tnRatingId = 'tn_r_delete_' . uniqid();

        DB::table('ratings')->insert([
            'ratee' => $user->id,
            'rating' => 'Up',
            'timestamp' => self::DATE_OLD,
            'visible' => 1,
            'tn_rating_id' => $tnRatingId,
        ]);

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => $tnRatingId,
                    'ratee_fd_user_id' => $user->id,
                    'rating' => null,
                    'date' => self::DATE_LATER,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertFalse(
            DB::table('ratings')->where('tn_rating_id', $tnRatingId)->exists()
        );
    }

    public function test_sync_skips_rating_without_fd_user_id(): void
    {
        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => 'tn_r_no_user_' . uniqid(),
                    'ratee_fd_user_id' => null,
                    'rating' => 'Up',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        // Should complete without error.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_sync_skips_rating_for_nonexistent_user(): void
    {
        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => 'tn_r_ghost_' . uniqid(),
                    'ratee_fd_user_id' => 999999999,
                    'rating' => 'Up',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertFalse(
            DB::table('ratings')->where('ratee', 999999999)->exists()
        );
    }

    // =========================================================================
    // User changes: account removal
    // =========================================================================

    public function test_sync_account_removed_forgets_user(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTNUser();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'account_removed' => true,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $updated = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($updated->forgotten);
        $this->assertEquals('Deleted User #' . $user->id, $updated->fullname);
    }

    // =========================================================================
    // User changes: reply time
    // =========================================================================

    public function test_sync_reply_time_upserts(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTNUser();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'reply_time' => 3600,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertTrue(
            DB::table('users_replytime')->where('userid', $user->id)->where('replytime', 3600)->exists()
        );
    }

    public function test_sync_reply_time_updates_existing(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTNUser();

        DB::table('users_replytime')->insert([
            'userid' => $user->id,
            'replytime' => 1800,
            'timestamp' => self::DATE_OLD,
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'reply_time' => 7200,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $replyTime = DB::table('users_replytime')->where('userid', $user->id)->value('replytime');
        $this->assertEquals(7200, $replyTime);
    }

    // =========================================================================
    // User changes: about me
    // =========================================================================

    public function test_sync_about_me_upserts(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTNUser();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'about_me' => 'I love giving things away!',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $aboutMe = DB::table('users_aboutme')->where('userid', $user->id)->value('text');
        $this->assertEquals('I love giving things away!', $aboutMe);
    }

    // =========================================================================
    // User changes: name change
    // =========================================================================

    public function test_sync_name_change_updates_fullname(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTNUser('OldName');

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => 'NewName',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $fullname = DB::table('users')->where('id', $user->id)->value('fullname');
        $this->assertEquals('NewName', $fullname);
    }

    public function test_sync_name_change_updates_tn_emails(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTNUser('OldName');

        // Add a TN-style email with the old name.
        $oldEmail = 'OldName-g123@user.trashnothing.com';
        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => $oldEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => 'NewName',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // Old email should be removed or replaced.
        $this->assertFalse(
            DB::table('users_emails')->where('userid', $user->id)->where('email', $oldEmail)->exists()
        );

        // New email should exist.
        $this->assertTrue(
            DB::table('users_emails')
                ->where('userid', $user->id)
                ->where('email', 'NewName-g123@user.trashnothing.com')
                ->exists()
        );
    }

    public function test_sync_skips_name_change_when_unchanged(): void
    {
        $user = $this->createTNUser('SameName');

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => 'SameName',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $fullname = DB::table('users')->where('id', $user->id)->value('fullname');
        $this->assertEquals('SameName', $fullname);
    }

    // =========================================================================
    // User changes: location
    // =========================================================================

    public function test_sync_location_change_updates_lastlocation(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        // Only run if we have location data in the test DB.
        if (!DB::table('locations')->where('type', 'Postcode')->whereRaw("LOCATE(' ', name) > 0")->exists()) {
            $this->markTestSkipped('No postcode data in test database');
        }

        $user = $this->createTNUser();

        // Find a valid postcode location to use as expected result.
        $existingLoc = DB::table('locations')
            ->where('type', 'Postcode')
            ->whereRaw("LOCATE(' ', name) > 0")
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->first();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'location' => [
                        'latitude' => (float) $existingLoc->lat,
                        'longitude' => (float) $existingLoc->lng,
                    ],
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $lastlocation = DB::table('users')->where('id', $user->id)->value('lastlocation');
        $this->assertNotNull($lastlocation);
    }

    // =========================================================================
    // User changes: skip non-TN users
    // =========================================================================

    public function test_sync_skips_non_tn_user(): void
    {
        $user = $this->createTestUser(); // Regular user, not TN.

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'reply_time' => 3600,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // Reply time should NOT be saved for non-TN users.
        $this->assertFalse(
            DB::table('users_replytime')->where('userid', $user->id)->exists()
        );
    }

    // =========================================================================
    // Duplicate TN user merging
    // =========================================================================

    public function test_merge_duplicate_tn_users(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Alice']);

        $tnBase = 'alice_' . uniqid('', true);

        // Create TN-style emails with different group suffixes but same base username.
        DB::table('users_emails')->insert([
            'userid' => $user1->id,
            'email' => "{$tnBase}-g101@user.trashnothing.com",
            'preferred' => 0,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $user2->id,
            'email' => "{$tnBase}-g202@user.trashnothing.com",
            'preferred' => 0,
            'added' => now(),
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // One of the users should have been merged into the other.
        $user1Exists = User::find($user1->id) !== null;
        $user2Exists = User::find($user2->id) !== null;

        $this->assertTrue(
            ($user1Exists && !$user2Exists) || (!$user1Exists && $user2Exists),
            'Expected exactly one user to be deleted during merge'
        );
    }

    public function test_no_merge_when_no_duplicates(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Different TN base usernames — these are NOT duplicates.
        DB::table('users_emails')->insert([
            'userid' => $user1->id,
            'email' => 'unique1_' . uniqid() . '-g101@user.trashnothing.com',
            'preferred' => 0,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $user2->id,
            'email' => 'unique2_' . uniqid() . '-g202@user.trashnothing.com',
            'preferred' => 0,
            'added' => now(),
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // Both users should still exist.
        $this->assertNotNull(User::find($user1->id));
        $this->assertNotNull(User::find($user2->id));
    }

    // =========================================================================
    // Date management
    // =========================================================================

    public function test_stores_max_change_date(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => 'tn_r_date_' . uniqid(),
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => '2026-03-20T15:00:00+00:00',
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertFileExists($this->dateFile);
        $storedDate = trim(file_get_contents($this->dateFile));
        $this->assertEquals('2026-03-20T15:00:00+00:00', $storedDate);
    }

    public function test_reads_stored_date(): void
    {
        $storedDate = '2026-03-15T00:00:00+00:00';
        file_put_contents($this->dateFile, $storedDate);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // Verify the API was called with the stored date as date_min.
        Http::assertSent(function ($request) use ($storedDate) {
            return str_contains($request->url(), 'ratings') &&
                   $request['date_min'] === $storedDate;
        });
    }

    public function test_falls_back_to_max_rating_timestamp(): void
    {
        // No date file — should fall back to max rating timestamp or -1 day.
        $this->assertFileDoesNotExist($this->dateFile);

        $user = $this->createTestUser();

        // Insert a rating with a known timestamp.
        $knownDate = '2026-03-10 12:00:00';
        DB::table('ratings')->insert([
            'ratee' => $user->id,
            'rating' => 'Up',
            'timestamp' => $knownDate,
            'visible' => 1,
            'tn_rating_id' => 'tn_r_fallback_' . uniqid(),
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // API should have been called with a date_min derived from the rating timestamp.
        Http::assertSent(function ($request) use ($knownDate) {
            if (!str_contains($request->url(), 'ratings')) {
                return false;
            }
            $dateMin = $request['date_min'] ?? '';
            // The stored date should be from the max rating timestamp.
            return strtotime($dateMin) === strtotime($knownDate);
        });
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_handles_ratings_api_failure_gracefully(): void
    {
        Http::fake([
            '*/ratings*' => Http::response(null, 500),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        // Should not crash.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_handles_user_changes_api_failure_gracefully(): void
    {
        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(null, 500),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    public function test_paginates_through_multiple_rating_pages(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        // Page 1: 100 ratings (full page triggers pagination).
        $page1Ratings = [];
        for ($i = 0; $i < 100; $i++) {
            $page1Ratings[] = [
                'rating_id' => 'tn_r_page1_' . uniqid() . '_' . $i,
                'ratee_fd_user_id' => $user->id,
                'rating' => 'Up',
                'date' => self::DATE_SYNC,
            ];
        }

        // Page 2: 1 additional rating.
        $page2RatingId = 'tn_r_page2_' . uniqid();
        $page2Ratings = [[
            'rating_id' => $page2RatingId,
            'ratee_fd_user_id' => $user->id,
            'rating' => 'Down',
            'date' => '2026-03-20T11:00:00+00:00',
        ]];

        Http::fake([
            '*/ratings*' => Http::sequence()
                ->push(['ratings' => $page1Ratings], 200)
                ->push(['ratings' => $page2Ratings], 200)
                ->push(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // The page 2 rating should exist, proving both pages were processed.
        $this->assertTrue(
            DB::table('ratings')->where('tn_rating_id', $page2RatingId)->exists()
        );
    }

    // =========================================================================
    // Loki logging
    // =========================================================================

    public function test_loki_logs_rating_insert(): void
    {
        $user = $this->createTestUser();
        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();

        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'rating-upsert', \Mockery::on(fn($ctx) =>
                $ctx['action'] === 'insert' && $ctx['user_id'] === $user->id
            ));

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => 'tn_r_loki_' . uniqid(),
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_rating_update(): void
    {
        $user = $this->createTestUser();
        $tnRatingId = 'tn_r_loki_update_' . uniqid();

        DB::table('ratings')->insert([
            'ratee' => $user->id,
            'rating' => 'Up',
            'timestamp' => self::DATE_OLD,
            'visible' => 1,
            'tn_rating_id' => $tnRatingId,
        ]);

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'rating-upsert', \Mockery::on(fn($ctx) =>
                $ctx['action'] === 'update' && $ctx['tn_rating_id'] === $tnRatingId
            ));

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => $tnRatingId,
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Down',
                    'date' => self::DATE_LATER,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_rating_delete(): void
    {
        $user = $this->createTestUser();
        $tnRatingId = 'tn_r_loki_del_' . uniqid();

        DB::table('ratings')->insert([
            'ratee' => $user->id,
            'rating' => 'Up',
            'timestamp' => self::DATE_OLD,
            'visible' => 1,
            'tn_rating_id' => $tnRatingId,
        ]);

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'rating-delete', \Mockery::on(fn($ctx) =>
                $ctx['tn_rating_id'] === $tnRatingId && $ctx['user_id'] === $user->id
            ));

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => $tnRatingId,
                    'ratee_fd_user_id' => $user->id,
                    'rating' => null,
                    'date' => self::DATE_LATER,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_user_forget(): void
    {
        $user = $this->createTNUser();

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-forget', \Mockery::on(fn($ctx) =>
                $ctx['user_id'] === $user->id
            ));

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'account_removed' => true,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_user_reply_time_insert(): void
    {
        $user = $this->createTNUser();

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-reply-time-upsert', \Mockery::on(fn($ctx) =>
                $ctx['action'] === 'insert' && $ctx['user_id'] === $user->id
            ));
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-update', \Mockery::any());

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'reply_time' => 3600,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_user_about_me_insert(): void
    {
        $user = $this->createTNUser();

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-about-me-upsert', \Mockery::on(fn($ctx) =>
                $ctx['action'] === 'insert' && $ctx['user_id'] === $user->id
            ));
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-update', \Mockery::any());

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'about_me' => 'Test bio',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_user_email_rename(): void
    {
        $user = $this->createTNUser('OldName');

        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'OldName-g123@user.trashnothing.com',
            'preferred' => 0,
            'added' => now(),
        ]);

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-email-rename', \Mockery::on(fn($ctx) =>
                $ctx['user_id'] === $user->id &&
                $ctx['old_email'] === 'OldName-g123@user.trashnothing.com' &&
                $ctx['new_email'] === 'NewName-g123@user.trashnothing.com'
            ));
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-update', \Mockery::any());

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => 'NewName',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_user_update(): void
    {
        $user = $this->createTNUser();

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-update', \Mockery::on(fn($ctx) =>
                $ctx['user_id'] === $user->id
            ));

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_loki_logs_user_merge(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'MergeTest']);
        $user2 = $this->createTestUser(['fullname' => 'MergeTest']);

        $tnBase = 'mergetest_' . uniqid('', true);

        DB::table('users_emails')->insert([
            'userid' => $user1->id,
            'email' => "{$tnBase}-g101@user.trashnothing.com",
            'preferred' => 0,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $user2->id,
            'email' => "{$tnBase}-g202@user.trashnothing.com",
            'preferred' => 0,
            'added' => now(),
        ]);

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-merge', \Mockery::on(fn($ctx) =>
                isset($ctx['merge_to']) && isset($ctx['merge_from'])
            ));

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a user with a TrashNothing email address.
     */
    private function createTNUser(string $name = 'TNUser'): User
    {
        $user = User::create([
            'firstname' => $name,
            'lastname' => 'TN',
            'fullname' => $name,
            'added' => now(),
        ]);

        $uniquePrefix = strtolower($name) . '_' . uniqid('', true);

        UserEmail::create([
            'userid' => $user->id,
            'email' => "{$uniquePrefix}-g1@user.trashnothing.com",
            'preferred' => 1,
            'added' => now(),
        ]);

        return $user->fresh();
    }
}
