<?php

namespace Tests\Feature\TrashNothing;

use App\Console\Commands\TrashNothing\TNSyncCommand;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\LokiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Tests for the tn:sync command — covers ratings sync, user changes sync,
 * duplicate TN user merging, date management, error handling, and pagination.
 *
 * Ported test coverage from:
 * - iznik-server: userAPITest::testRating(), sessionTest::testAboutMe(),
 *   chatRoomsTest::testUserStopsReplyingReplyTime()
 * - iznik-server-go: TestPostUserRateUp/Down, TestPatchUserAboutMe
 *
 * Process isolation: each test runs in a separate PHP process to prevent
 * memory accumulation. 28+ artisan() calls in one process exhaust the heap
 * because PHP's zend_mm doubles its segment size on each expansion attempt.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TNSyncCommandTest extends TestCase
{
    private const DATE_SYNC = '2026-03-20T10:00:00+00:00';
    private const DATE_LATER = '2026-03-20T12:00:00+00:00';
    private const DATE_OLD = '2026-01-01 00:00:00';


    private string $dateFile;
    private string $apiBaseUrl;

    protected function setUp(): void
    {
        // Free cyclic garbage from the previous test (or a crashed test that skipped tearDown).
        // Each artisan() call boots a full Laravel kernel with many cyclic references that
        // PHP's reference counter won't collect automatically — gc_collect_cycles() does.
        gc_collect_cycles();

        parent::setUp();

        // With #[RunTestsInSeparateProcesses], each test runs in its own PHP process.
        // LOG_CHANNEL=stderr in Docker means logs go to child-process stderr, which
        // PHPUnit captures and treats as risky output (failOnRisky: true → error).
        // Swap the logger for a NullLogger to prevent all log output in child processes.
        Log::swap(new NullLogger());

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

        // Force collection of cyclic references left by Laravel's service container
        // and Eloquent after each artisan() run. Without this, cycles accumulate
        // across the 41 tests in this class and exhaust the 1GB memory limit.
        gc_collect_cycles();
    }

    // =========================================================================
    // Ratings sync
    // =========================================================================

    public function test_sync_creates_new_rating(): void
    {
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

    public function test_sync_account_removed_executes_without_error(): void
    {
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

        // Writes are commented out in port-testing mode; verify the command doesn't crash.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // User changes: reply time
    // =========================================================================

    public function test_sync_reply_time_upserts(): void
    {
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

    public function test_sync_reply_time_executes_without_error(): void
    {
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

        // Save is commented out in port-testing mode; verify model setup doesn't crash.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // User changes: about me
    // =========================================================================

    public function test_sync_about_me_upserts(): void
    {
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

    public function test_sync_about_me_executes_without_error(): void
    {
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

        // Save is commented out in port-testing mode; verify model setup doesn't crash.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // User changes: name change
    // =========================================================================

    public function test_sync_name_change_updates_fullname(): void
    {
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

    public function test_sync_name_change_executes_without_error_when_name_changes(): void
    {
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

        // User has no email matching the "{oldname}-" pattern so no removeEmail/addEmail
        // is triggered. Save is commented out in port-testing mode.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // User changes: location
    // =========================================================================

    public function test_sync_location_change_updates_lastlocation(): void
    {

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
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Alice']);

        $tnBase = 'alice_' . uniqid('', true);

        // Create TN-style emails with different group suffixes but same base username.
        DB::table('users_emails')->insert([
            'userid' => $user1->id,
            'email' => "{$tnBase}-g101@user.trashnothing.com",
            'backwards' => strrev("{$tnBase}-g101@user.trashnothing.com"),
            'preferred' => 0,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $user2->id,
            'email' => "{$tnBase}-g202@user.trashnothing.com",
            'backwards' => strrev("{$tnBase}-g202@user.trashnothing.com"),
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
        $email1 = 'unique1_' . uniqid() . '-g101@user.trashnothing.com';
        $email2 = 'unique2_' . uniqid() . '-g202@user.trashnothing.com';
        DB::table('users_emails')->insert([
            'userid' => $user1->id,
            'email' => $email1,
            'backwards' => strrev($email1),
            'preferred' => 0,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $user2->id,
            'email' => $email2,
            'backwards' => strrev($email2),
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
            'backwards' => strrev("{$tnBase}-g101@user.trashnothing.com"),
            'preferred' => 0,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $user2->id,
            'email' => "{$tnBase}-g202@user.trashnothing.com",
            'backwards' => strrev("{$tnBase}-g202@user.trashnothing.com"),
            'preferred' => 0,
            'added' => now(),
        ]);

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-merge', \Mockery::on(fn($ctx) =>
                ($ctx['merge_to'] === $user1->id && $ctx['merge_from'] === $user2->id) ||
                ($ctx['merge_to'] === $user2->id && $ctx['merge_from'] === $user1->id)
            ));

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }


    // =========================================================================
    // Edge cases and uncovered paths
    // =========================================================================

    // TODO Finnbarr: remove this code when I remove custom flocking with PreventsOverlapping after testing is complete.
    // At this point, the Laravel scheduler's withoutOverlapping will handle this.
    public function test_exits_early_when_lock_already_held(): void
    {
        // acquireLock() is protected, and partialMock() can't mock protected methods.
        // Use an anonymous subclass that overrides acquireLock() to return false,
        // simulating a concurrent run. The command must exit 0 (not an error).
        $loki = $this->app->make(LokiService::class);
        $this->app->bind(TNSyncCommand::class, fn () => new class($loki) extends TNSyncCommand {
            protected function acquireLock(): bool
            {
                return false;
            }
        });

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_returns_failure_on_unexpected_exception(): void
    {
        // Make the ratings HTTP call throw so the outer catch in handle() fires.
        Http::fake([
            '*/ratings*' => function () {
                throw new \RuntimeException('API exploded during sync');
            },
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(1);
    }

    public function test_from_option_overrides_sync_date(): void
    {
        $fromDate = '2026-01-15T00:00:00+00:00';

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync', ['--from' => $fromDate])->assertExitCode(0);

        Http::assertSent(function ($request) use ($fromDate) {
            return str_contains($request->url(), 'ratings')
                && $request['date_min'] === $fromDate;
        });
    }

    public function test_to_option_overrides_sync_date(): void
    {
        $toDate = '2026-06-01T00:00:00+00:00';

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync', ['--to' => $toDate])->assertExitCode(0);

        Http::assertSent(function ($request) use ($toDate) {
            return str_contains($request->url(), 'ratings')
                && $request['date_max'] === $toDate;
        });
    }

    // =========================================================================
    // Local testing flag
    // =========================================================================

    public function test_local_testing_flag_loads_from_fixtures_without_http(): void
    {
        // Prevent any real or faked Http calls — if the flag is wired correctly
        // the command loads from fixture files and Http is never touched.
        Http::preventStrayRequests();

        $this->artisan('tn:sync', ['--local-testing' => true])->assertExitCode(0);
    }

    public function test_mark_queue_run_completed_warns_when_run_id_not_found(): void
    {
        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        // Pass a run-id for which no background_tasks row exists; command must
        // still complete successfully (the missing row is just a warning).
        $this->artisan('tn:sync', ['--run-id' => 'missing-run-id-' . uniqid()])->assertExitCode(0);
    }

    public function test_mark_queue_run_completed_updates_background_tasks_row(): void
    {
        $runId = 'test-run-' . uniqid('', true);

        DB::table('background_tasks')->insert([
            'task_type' => 'tn_sync_command',
            'data' => json_encode(['run_id' => $runId]),
            'created_at' => now(),
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync', ['--run-id' => $runId])->assertExitCode(0);

        $row = DB::table('background_tasks')
            ->where('task_type', 'tn_sync_command')
            ->whereRaw("JSON_EXTRACT(data, '$.run_id') = ?", [$runId])
            ->first();

        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertTrue($data['tn_sync_finished']);
        $this->assertEquals('success', $data['tn_sync_status']);
        $this->assertEquals(0, $data['tn_sync_exit_code']);
    }

    public function test_store_sync_date_logs_error_when_write_fails(): void
    {
        // Set the date file to a path in a non-existent directory so the write fails.
        // /dev/full MUST NOT be used here: it is a device that returns infinite zeros
        // on read, which causes file_get_contents() to exhaust all available memory.
        config(['freegle.trashnothing.sync_date_file' => '/nonexistent-dir/tn_sync_date.txt']);

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

        // Command should still exit successfully even when the date file write fails.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_skips_user_change_without_fd_user_id(): void
    {
        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => null,
                    'reply_time' => 3600,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        // Should complete without error.
        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_location_change_with_no_postcode_result_is_silently_ignored(): void
    {
        // Provide coordinates in the middle of the ocean — closestPostcode returns null.
        $user = $this->createTNUser();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'location' => [
                        'latitude' => 0.0,
                        'longitude' => 0.0,
                    ],
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    public function test_location_change_with_null_lat_lng_is_skipped(): void
    {
        $user = $this->createTNUser();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'location' => [
                        'latitude' => null,
                        'longitude' => null,
                    ],
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // Staleness alerting (no-op cycle)
    // =========================================================================

    public function test_no_op_alerts_when_sync_is_stale(): void
    {
        // Stored sync date ~13 hours ago — older than the 12h threshold.
        $staleDate = gmdate('Y-m-d\TH:i:s\Z', time() - (13 * 3600));
        file_put_contents($this->dateFile, $staleDate);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        Log::spy();

        $this->artisan('tn:sync')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'TN sync stale')
            );
    }

    public function test_no_op_does_not_alert_when_sync_is_fresh(): void
    {
        // Stored sync date ~1 hour ago — well within the 12h threshold.
        $freshDate = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        file_put_contents($this->dateFile, $freshDate);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        Log::spy();

        $this->artisan('tn:sync')->assertExitCode(0);

        // No warnings expected: Mockery's shouldNotHaveReceived() doesn't support ->withArgs() chaining.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_no_op_does_not_alert_when_no_baseline(): void
    {
        // No sync date file, and no TN ratings seeded — there is no baseline,
        // so a no-op cycle must not emit a stale warning.
        if (file_exists($this->dateFile)) {
            @unlink($this->dateFile);
        }
        $this->assertFileDoesNotExist($this->dateFile);

        DB::table('ratings')->whereNotNull('tn_rating_id')->delete();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        Log::spy();

        $this->artisan('tn:sync')->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
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
