<?php

namespace Tests\Feature\TrashNothing;

use App\Console\Commands\TrashNothing\TNSyncCommand;
use App\Console\Concerns\PreventsOverlapping;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\LokiService;
use App\Services\TrashNothing\Sync\PostSyncer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Tests for the tn:sync command — covers ratings sync, user changes sync,
 * duplicate TN user merging, date management, error handling, and pagination.
 *
 * Ported test coverage from:
 * - the legacy V1 PHP implementation: userAPITest::testRating(), sessionTest::testAboutMe(),
 *   chatRoomsTest::testUserStopsReplyingReplyTime()
 * - iznik-server-go: TestPostUserRateUp/Down, TestPatchUserAboutMe
 *
 * Process isolation: each test runs in a separate PHP process to prevent
 * memory accumulation. 28+ artisan() calls in one process exhaust the heap
 * because PHP's zend_mm doubles its segment size on each expansion attempt.
 */
/**
 * Runs in one process, like every other class. It used to run each test in its
 * own process, and before that to force a garbage collection around each test,
 * to get round memory that accumulated across artisan() calls in early versions
 * of the command. Neither is needed now, and both were expensive: a process per
 * test cost a PHP start and an application boot each, and a forced collection
 * late in the suite walks the hundreds of megabytes of coverage data PHPUnit is
 * holding by then, about 150ms a time.
 */
class TNSyncCommandTest extends TestCase
{
    private const DATE_SYNC = '2026-03-20T10:00:00+00:00';
    private const DATE_LATER = '2026-03-20T12:00:00+00:00';
    private const DATE_OLD = '2026-01-01 00:00:00';
    private const FEED_HOLD_STORED = '2026-03-20T10:00:00Z';
    private const FEED_HOLD_LATER = '2026-03-20T15:00:00Z';


    private string $dateFile;
    private string $apiBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        // tn:sync logs as it goes. Swap in a NullLogger so none of that reaches the
        // test output.
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
                    'rating_id' => random_int(1000000, 9000000000000),
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
        $tnRatingId = random_int(1000000, 9000000000000);

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
        $tnRatingId = random_int(1000000, 9000000000000);

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

    public function test_sync_falsy_rating_is_not_treated_as_a_deletion(): void
    {
        $user = $this->createTestUser();
        $tnRatingId = random_int(100000, 999999);

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
                    // Falsy but present. Only a null rating means "deleted".
                    'rating' => 0,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertTrue(
            DB::table('ratings')->where('tn_rating_id', $tnRatingId)->exists(),
            'A falsy-but-present rating must not delete the row.'
        );
    }

    public function test_sync_rating_without_a_date_does_not_move_the_watermark(): void
    {
        $user = $this->createTestUser();

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [
                    [
                        // No 'date' key at all — must neither warn nor clobber
                        // the watermark the dated row below establishes.
                        'rating_id' => random_int(100000, 999999),
                        'ratee_fd_user_id' => $user->id,
                        'rating' => 'Up',
                    ],
                    [
                        'rating_id' => random_int(100000, 999999),
                        'ratee_fd_user_id' => $user->id,
                        'rating' => 'Down',
                        'date' => self::DATE_SYNC,
                    ],
                ],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame(self::DATE_SYNC, trim(file_get_contents($this->dateFile)));
    }

    public function test_sync_skips_rating_without_fd_user_id(): void
    {
        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => random_int(1000000, 9000000000000),
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
                    'rating_id' => random_int(1000000, 9000000000000),
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

    public function test_sync_reply_time_zero_is_stored(): void
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
                    'reply_time' => 0,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // A reply time of 0 is a real value (instant replies), not "no change".
        $replyTime = DB::table('users_replytime')->where('userid', $user->id)->value('replytime');
        $this->assertEquals(0, $replyTime);
    }

    public function test_sync_reply_time_absent_is_left_alone(): void
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
                    'username' => 'SomeName',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $replyTime = DB::table('users_replytime')->where('userid', $user->id)->value('replytime');
        $this->assertEquals(1800, $replyTime);
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

    public function test_sync_about_me_empty_string_clears_bio(): void
    {
        $user = $this->createTNUser();

        DB::table('users_aboutme')->insert([
            'userid' => $user->id,
            'text' => 'Old bio',
            'timestamp' => self::DATE_OLD,
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'about_me' => '',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // An empty string means the member cleared their bio, not "no change".
        $aboutMe = DB::table('users_aboutme')->where('userid', $user->id)->value('text');
        $this->assertSame('', $aboutMe);
    }

    public function test_sync_about_me_absent_is_left_alone(): void
    {
        $user = $this->createTNUser();

        DB::table('users_aboutme')->insert([
            'userid' => $user->id,
            'text' => 'Old bio',
            'timestamp' => self::DATE_OLD,
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => 'SomeName',
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $aboutMe = DB::table('users_aboutme')->where('userid', $user->id)->value('text');
        $this->assertEquals('Old bio', $aboutMe);
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
        $user = $this->createTNUser('OldName', 'oldname' . random_int(100000, 999999));
        $new = 'new.name' . random_int(100000, 999999);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => $new,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // fullname is the display name for the username, as a provisioned user's is.
        $fullname = DB::table('users')->where('id', $user->id)->value('fullname');
        $this->assertEquals(User::tnDisplayName($new), $fullname);
    }

    public function test_sync_name_change_updates_tn_emails(): void
    {
        $old = 'oldname' . random_int(100000, 999999);
        $new = 'newname' . random_int(100000, 999999);
        $user = $this->createTNUser('OldName', $old);

        // A second per-group alias for the same username.
        $oldEmail = "{$old}-g123@user.trashnothing.com";
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
                    'username' => $new,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // Each alias moves to the new username and keeps its group suffix; the
        // preferred one stays preferred.
        $this->assertEquals(
            ["{$new}-g1@user.trashnothing.com" => 1, "{$new}-g123@user.trashnothing.com" => 0],
            DB::table('users_emails')->where('userid', $user->id)->orderBy('email')->pluck('preferred', 'email')->all()
        );
    }

    public function test_sync_skips_name_change_when_unchanged(): void
    {
        $username = 'same.name' . random_int(100000, 999999);
        $user = $this->createTNUser(User::tnDisplayName($username), $username);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => $username,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        // The prettified fullname never equals the raw username; that is not a rename.
        $fullname = DB::table('users')->where('id', $user->id)->value('fullname');
        $this->assertEquals(User::tnDisplayName($username), $fullname);
        $this->assertTrue(
            DB::table('users_emails')->where('userid', $user->id)->where('email', "{$username}-g1@user.trashnothing.com")->exists()
        );
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

        $this->artisan('tn:sync')->assertExitCode(0);
    }

    // =========================================================================
    // User changes: location
    // =========================================================================

    public function test_sync_location_change_updates_lastlocation(): void
    {

        $user = $this->createTNUser();

        // Seed a known postcode row; closestPostcode resolves the user's coords
        // to it. tn:sync mocks HTTP via Http::fake, which also intercepts the
        // spatial-server call, so we fake the postcodes KNN response here rather
        // than seeding the live index.
        $pcId = 99000201;
        $lat = 55.9533;
        $lng = -3.1883;
        $srid = (int) config('freegle.srid', config('freegle.srid'));
        DB::table('locations')->updateOrInsert(['id' => $pcId], [
            'name' => 'EH1 1AA',
            'type' => 'Postcode',
            'lat' => $lat,
            'lng' => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid)),
        ]);

        try {
            Http::fake([
                '*/ratings*' => Http::response(['ratings' => []], 200),
                '*/user-changes*' => Http::response([
                    'changes' => [[
                        'fd_user_id' => $user->id,
                        'location' => ['latitude' => $lat, 'longitude' => $lng],
                        'date' => self::DATE_SYNC,
                    ]],
                ], 200),
                '*/v1/postcodes/knn*' => Http::response(['results' => [['id' => $pcId, 'distance' => 0]]], 200),
            ]);

            $this->artisan('tn:sync')->assertExitCode(0);

            $lastlocation = DB::table('users')->where('id', $user->id)->value('lastlocation');
            $this->assertNotNull($lastlocation);
        } finally {
            DB::table('locations')->where('id', $pcId)->delete();
        }
    }

    /**
     * A move TN tells us about is logged like a postcode change in Settings, so moderators
     * can see it in the member's logs (Discourse 10277). No move, no row.
     */
    public function test_sync_location_change_is_logged_once(): void
    {
        $user = $this->createTNUser();

        $pcId = 99000203;
        $lat = 51.5502;
        $lng = 0.1350;
        $srid = (int) config('freegle.srid', config('freegle.srid'));
        DB::table('locations')->updateOrInsert(['id' => $pcId], [
            'name' => 'RM9 5ZZ',
            'type' => 'Postcode',
            'lat' => $lat,
            'lng' => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid)),
        ]);

        $logged = fn () => DB::table('logs')
            ->where('user', $user->id)
            ->where('type', 'User')
            ->where('subtype', 'PostcodeChange')
            ->pluck('text')
            ->all();

        try {
            Http::fake([
                '*/ratings*' => Http::response(['ratings' => []], 200),
                '*/user-changes*' => Http::response([
                    'changes' => [[
                        'fd_user_id' => $user->id,
                        'location' => ['latitude' => $lat, 'longitude' => $lng],
                        'date' => self::DATE_SYNC,
                    ]],
                ], 200),
                '*/v1/postcodes/knn*' => Http::response(['results' => [['id' => $pcId, 'distance' => 0]]], 200),
            ]);

            $this->artisan('tn:sync')->assertExitCode(0);
            $this->assertEquals(['RM9 5ZZ'], $logged(), 'the move is logged with the postcode');

            // The same location again is not a move.
            $this->artisan('tn:sync', ['--from' => self::DATE_SYNC])->assertExitCode(0);
            $this->assertEquals(['RM9 5ZZ'], $logged(), 'an unchanged location logs nothing more');
        } finally {
            DB::table('logs')->where('user', $user->id)->where('subtype', 'PostcodeChange')->delete();
            DB::table('locations')->where('id', $pcId)->delete();
        }
    }

    /**
     * TN is the master for a TN member's location. A stale FD-side settings.mylocation
     * (V1 data from before the account was linked to TN) is read first by the profile,
     * distances and Browse, so it must go once TN tells us where the member is - even
     * when the postcode TN resolves to is the one we already hold.
     */
    public function test_sync_location_drops_stale_fd_mylocation(): void
    {
        $user = $this->createTNUser();

        $pcId = 99000202;
        $lat = 55.94439;
        $lng = -2.95412;
        $srid = (int) config('freegle.srid', config('freegle.srid'));
        DB::table('locations')->updateOrInsert(['id' => $pcId], [
            'name' => 'EH33 1LH',
            'type' => 'Postcode',
            'lat' => $lat,
            'lng' => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid)),
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $pcId,
            'settings' => json_encode([
                'dummy' => true,
                'mylocation' => ['id' => 1, 'name' => 'M34 6PB', 'lat' => 53.453008, 'lng' => -2.103187],
                'browseMaxMinutes' => 45,
            ]),
        ]);

        try {
            Http::fake([
                '*/ratings*' => Http::response(['ratings' => []], 200),
                '*/user-changes*' => Http::response([
                    'changes' => [[
                        'fd_user_id' => $user->id,
                        'location' => ['latitude' => $lat, 'longitude' => $lng],
                        'date' => self::DATE_SYNC,
                    ]],
                ], 200),
                '*/v1/postcodes/knn*' => Http::response(['results' => [['id' => $pcId, 'distance' => 0]]], 200),
            ]);

            $this->artisan('tn:sync')->assertExitCode(0);

            $row = DB::table('users')->where('id', $user->id)->first(['lastlocation', 'settings']);
            $settings = json_decode($row->settings, true);
            $this->assertEquals($pcId, $row->lastlocation);
            $this->assertArrayNotHasKey('mylocation', $settings);
            $this->assertSame(45, $settings['browseMaxMinutes']);
        } finally {
            DB::table('locations')->where('id', $pcId)->delete();
        }
    }

    public function test_sync_without_location_keeps_fd_mylocation(): void
    {
        $user = $this->createTNUser();
        $myloc = ['id' => 1, 'name' => 'M34 6PB', 'lat' => 53.453008, 'lng' => -2.103187];
        DB::table('users')->where('id', $user->id)->update([
            'settings' => json_encode(['mylocation' => $myloc]),
        ]);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'reply_time' => 60,
                    'date' => self::DATE_SYNC,
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $settings = json_decode(DB::table('users')->where('id', $user->id)->value('settings'), true);
        $this->assertSame($myloc, $settings['mylocation']);
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

    /**
     * Two accounts sharing a TN username, neither holding a TN user id: nothing proves
     * they are one member, so they are flagged for a person and both kept.
     */
    public function test_a_shared_username_without_tn_ids_is_flagged_not_merged(): void
    {
        $events = $this->recordSyncEvents();
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

        $this->assertNotNull(User::find($user1->id));
        $this->assertNotNull(User::find($user2->id));
        $this->assertTrue($this->flagged($events, [$user1->id, $user2->id], 'tnuserid-unproven'));
    }

    /**
     * A shared username with two different tnuserids is two TN accounts - a username
     * released and retaken, or a member who re-registered. Merging would delete one
     * account and its tnuserid, after which that TN user's posts resolve to nobody.
     */
    public function test_merge_skips_users_with_different_tnuserids(): void
    {
        $this->fakeEmptyTnFeeds();

        $user1 = $this->createTestUser(['fullname' => 'Carol', 'tnuserid' => 99020101]);
        $user2 = $this->createTestUser(['fullname' => 'Carol', 'tnuserid' => 99020102]);

        $tnBase = 'carol_' . str_replace('.', '', uniqid('', true));
        $this->insertTnAddress($user1->id, "{$tnBase}-g101@user.trashnothing.com");
        $this->insertTnAddress($user2->id, "{$tnBase}-g202@user.trashnothing.com");

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-merge-skip', \Mockery::on(fn ($ctx) =>
                $ctx['reason'] === 'tnuserid-conflict'
                && $ctx['user_ids'] === [$user1->id, $user2->id]
                && $ctx['tn_user_ids'] === [99020101, 99020102]
            ));
        $loki->shouldNotReceive('logEvent')->with('tn-sync', 'user-merge', \Mockery::any());

        $this->artisan('tn:sync', ['--full-duplicate-scan' => true])->assertExitCode(0);

        $this->assertNotNull(User::find($user1->id), 'the first TN account must survive');
        $this->assertNotNull(User::find($user2->id), 'the second TN account must survive');
        $this->assertEquals(99020101, User::find($user1->id)->tnuserid);
        $this->assertEquals(99020102, User::find($user2->id)->tnuserid);
    }

    /**
     * One side holding a TN user id and the other none does not prove one member: the
     * other account may be someone who took the username later. Both are kept and the
     * group is flagged.
     */
    public function test_one_tn_id_on_a_shared_username_is_flagged_not_merged(): void
    {
        $this->fakeEmptyTnFeeds();
        $events = $this->recordSyncEvents();

        $user1 = $this->createTestUser(['fullname' => 'Dave']);
        $user2 = $this->createTestUser(['fullname' => 'Dave', 'tnuserid' => 99020103]);

        $tnBase = 'dave_' . str_replace('.', '', uniqid('', true));
        $this->insertTnAddress($user1->id, "{$tnBase}-g101@user.trashnothing.com");
        $this->insertTnAddress($user2->id, "{$tnBase}-g202@user.trashnothing.com");

        $this->artisan('tn:sync', ['--full-duplicate-scan' => true])->assertExitCode(0);

        $this->assertNotNull(User::find($user1->id));
        $this->assertNotNull(User::find($user2->id));
        $this->assertEquals(99020103, User::find($user2->id)->tnuserid);
        $this->assertTrue($this->flagged($events, [$user1->id, $user2->id], 'tnuserid-unproven'));
    }

    /**
     * Every group is flagged on its own, with its own reason.
     */
    public function test_each_shared_username_group_is_flagged_with_its_reason(): void
    {
        $this->fakeEmptyTnFeeds();
        $events = $this->recordSyncEvents();

        $conflict1 = $this->createTestUser(['fullname' => 'Eve', 'tnuserid' => 99020104]);
        $conflict2 = $this->createTestUser(['fullname' => 'Eve', 'tnuserid' => 99020105]);
        $twin1 = $this->createTestUser(['fullname' => 'Fay']);
        $twin2 = $this->createTestUser(['fullname' => 'Fay']);

        $eve = 'eve_' . str_replace('.', '', uniqid('', true));
        $fay = 'fay_' . str_replace('.', '', uniqid('', true));
        $this->insertTnAddress($conflict1->id, "{$eve}-g1@user.trashnothing.com");
        $this->insertTnAddress($conflict2->id, "{$eve}-g2@user.trashnothing.com");
        $this->insertTnAddress($twin1->id, "{$fay}-g1@user.trashnothing.com");
        $this->insertTnAddress($twin2->id, "{$fay}-g2@user.trashnothing.com");

        $this->artisan('tn:sync', ['--full-duplicate-scan' => true])->assertExitCode(0);

        foreach ([$conflict1, $conflict2, $twin1, $twin2] as $user) {
            $this->assertNotNull(User::find($user->id));
        }
        $this->assertTrue($this->flagged($events, [$conflict1->id, $conflict2->id], 'tnuserid-conflict'));
        $this->assertTrue($this->flagged($events, [$twin1->id, $twin2->id], 'tnuserid-unproven'));
    }

    /**
     * Record the tn-sync Loki events, so a test can see which groups the duplicate
     * check flagged.
     */
    private function recordSyncEvents(): \ArrayObject
    {
        $events = new \ArrayObject();
        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')->andReturnUsing(function ($type, $subtype, $context = []) use ($events) {
            $events[] = [$subtype, $context];
        });

        return $events;
    }

    /** Whether the duplicate check flagged exactly these accounts as one group. */
    private function flagged(\ArrayObject $events, array $userIds, ?string $reason = null): bool
    {
        sort($userIds);
        foreach ($events as [$subtype, $context]) {
            if ($subtype !== 'user-merge-skip') {
                continue;
            }
            $ids = $context['user_ids'];
            sort($ids);
            if ($ids === $userIds && ($reason === null || $context['reason'] === $reason)) {
                return true;
            }
        }

        return false;
    }

    private function insertTnAddress(int $userid, string $email): void
    {
        DB::table('users_emails')->insert([
            'userid' => $userid,
            'email' => $email,
            'backwards' => strrev($email),
            'preferred' => 0,
            'added' => now(),
        ]);
    }

    private function fakeEmptyTnFeeds(): void
    {
        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
    }

    /**
     * The per-tick duplicate check now reads only addresses added since last time,
     * instead of streaming all ~400,000 Trash Nothing addresses every minute. A pair
     * created after the last run must still be caught.
     */
    public function test_incremental_scan_catches_a_newly_created_duplicate(): void
    {
        $events = $this->recordSyncEvents();
        // Establish a position first, so the next run is genuinely incremental.
        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
        $this->artisan('tn:sync')->assertExitCode(0);
        $this->assertNotNull(
            DB::table('config')->where('key', 'tn.dupscan_cursor')->value('value'),
            'the first run should record how far it read'
        );

        $user1 = $this->createTestUser(['fullname' => 'Bob']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);
        $tnBase = 'bob_' . uniqid('', true);

        foreach ([[$user1->id, 'g101'], [$user2->id, 'g202']] as [$uid, $suffix]) {
            $email = "{$tnBase}-{$suffix}@user.trashnothing.com";
            DB::table('users_emails')->insert([
                'userid' => $uid,
                'email' => $email,
                'backwards' => strrev($email),
                'preferred' => 0,
                'added' => now(),
            ]);
        }

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertTrue(
            $this->flagged($events, [$user1->id, $user2->id]),
            'a group created since the last run must still be flagged'
        );
    }

    /**
     * A username is not a prefix of an address. `bibiana-g%@user.trashnothing.com`
     * also matches `bibiana-gomes-g4840@...`, so the probe on its own merged two
     * unrelated members and deleted one of them (2026-09-13). A longer username that
     * happens to start with `<username>-g` must be left alone.
     */
    public function test_incremental_scan_leaves_a_longer_username_alone(): void
    {
        $events = $this->recordSyncEvents();
        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $shortBase = 'dee_' . uniqid('', true);
        $longBase = $shortBase . '-gomes';

        // The member with the longer name has been around a while.
        $longUser = $this->createTestUser(['fullname' => 'Dee Gomes']);
        $longEmail = "{$longBase}-g4840@user.trashnothing.com";
        DB::table('users_emails')->insert([
            'userid' => $longUser->id,
            'email' => $longEmail,
            'backwards' => strrev($longEmail),
            'preferred' => 0,
            'added' => now(),
        ]);

        // Read past it, so the next run is genuinely incremental.
        $this->artisan('tn:sync')->assertExitCode(0);

        // A different member, whose name is the other's prefix, joins a group.
        $shortUser = $this->createTestUser(['fullname' => 'Dee']);
        $shortEmail = "{$shortBase}-g288@user.trashnothing.com";
        DB::table('users_emails')->insert([
            'userid' => $shortUser->id,
            'email' => $shortEmail,
            'backwards' => strrev($shortEmail),
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertNotNull(User::find($shortUser->id), 'the new arrival must survive');
        $this->assertNotNull(User::find($longUser->id), 'the member with the longer name must survive');
        $this->assertEquals(
            $longUser->id,
            DB::table('users_emails')->where('email', $longEmail)->value('userid'),
            'the longer name keeps its own address'
        );
        $this->assertFalse($this->flagged($events, [$shortUser->id, $longUser->id]), 'two different names are not one group');
    }

    /**
     * The hole the incremental check cannot see: a duplicate made by re-pointing an
     * existing row, which adds no new id. One tick a day re-scans everything to catch
     * those, which is the only reason narrowing the per-tick check is safe.
     */
    public function test_a_full_rescan_catches_a_duplicate_the_cursor_missed(): void
    {
        $events = $this->recordSyncEvents();
        $user1 = $this->createTestUser(['fullname' => 'Carol']);
        $user2 = $this->createTestUser(['fullname' => 'Carol']);
        $tnBase = 'carol_' . uniqid('', true);

        foreach ([[$user1->id, 'g101'], [$user2->id, 'g202']] as [$uid, $suffix]) {
            $email = "{$tnBase}-{$suffix}@user.trashnothing.com";
            DB::table('users_emails')->insert([
                'userid' => $uid,
                'email' => $email,
                'backwards' => strrev($email),
                'preferred' => 0,
                'added' => now(),
            ]);
        }

        // Pretend the per-tick check has already read past them, and that a whole-table
        // re-scan happened recently - so only --full-duplicate-scan can find this pair.
        DB::table('config')->upsert([
            ['key' => 'tn.dupscan_cursor', 'value' => (string) ((int) DB::table('users_emails')->max('id') + 1)],
            ['key' => 'tn.dupscan_full_at', 'value' => now()->toDateTimeString()],
        ], ['key'], ['value']);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        // An ordinary tick sees nothing new.
        $this->artisan('tn:sync')->assertExitCode(0);
        $this->assertFalse($this->flagged($events, [$user1->id, $user2->id]));

        // The whole-table pass finds them.
        $this->artisan('tn:sync --full-duplicate-scan')->assertExitCode(0);
        $this->assertTrue(
            $this->flagged($events, [$user1->id, $user2->id]),
            'the full re-scan must catch what the cursor stepped over'
        );
    }

    /**
     * The blind spot that let 96 duplicate pairs pile up on production between
     * 2026-05 and 2026-08.
     *
     * 5e2a90450 swapped `email LIKE '%@user.trashnothing.com'` (a 400s full scan)
     * for a range scan on the `backwards` index, on the stated assumption that
     * `backwards` is REVERSE(email). It is not. Rows written by V1 hold
     * REVERSE(canon), and canon has the TN group suffix AND the dots in the
     * domain stripped - so those rows reverse to `mocgnihtonhsartresu@...`, not
     * `moc.gnihtonhsart.resu@...`, and the filter steps straight over them.
     * On production that is 1,752,575 of 2,217,411 TN addresses, with a further
     * 13,772 holding NULL that no prefix reaches at all.
     *
     * Every existing test here builds `backwards` as strrev($email), which is the
     * one format the filter does match, which is why none of them caught it. This
     * one builds the pair the way production actually holds it: the older account
     * canon-style (invisible), the newer one raw-style (visible). The scan sees
     * one account, groups it alone, and merges nothing.
     */
    public function test_full_scan_sees_a_pair_whose_older_row_has_canon_style_backwards(): void
    {
        $events = $this->recordSyncEvents();

        [$older, $newer, $tnBase] = $this->createCanonStyleDuplicatePair();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync --full-duplicate-scan')->assertExitCode(0);

        $this->assertTrue(
            $this->flagged($events, [$older->id, $newer->id]),
            "the pair sharing TN username {$tnBase} must be found even though the "
            . 'older row holds REVERSE(canon) rather than REVERSE(email)'
        );
    }

    /**
     * The 13,772 Trash Nothing rows on production whose backwards is NULL outright.
     * No set of prefixes on that column can ever reach them, which is why the widened
     * filter tests the address instead. Two of the 96 split usernames sit here.
     */
    public function test_full_scan_sees_a_pair_whose_older_row_has_no_backwards_at_all(): void
    {
        $events = $this->recordSyncEvents();

        $older = $this->createTestUser(['fullname' => 'Nullback']);
        $newer = $this->createTestUser(['fullname' => 'Nullback']);
        $tnBase = 'nb' . str_replace('.', '', uniqid('', true));

        DB::table('users_emails')->insert([
            'userid' => $older->id,
            'email' => "{$tnBase}-g1997@user.trashnothing.com",
            'canon' => "{$tnBase}@usertrashnothingcom",
            'backwards' => null,
            'preferred' => 1,
            'added' => now(),
        ]);
        DB::table('users_emails')->insert([
            'userid' => $newer->id,
            'email' => "{$tnBase}-g9190@user.trashnothing.com",
            'canon' => null,
            'backwards' => null,
            'preferred' => 1,
            'added' => now(),
        ]);

        DB::table('config')->upsert([
            ['key' => 'tn.dupscan_cursor', 'value' => (string) ((int) DB::table('users_emails')->max('id') + 1)],
            ['key' => 'tn.dupscan_full_at', 'value' => now()->toDateTimeString()],
        ], ['key'], ['value']);

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync --full-duplicate-scan')->assertExitCode(0);

        $this->assertTrue(
            $this->flagged($events, [$older->id, $newer->id]),
            'a pair with no backwards at all must still be found'
        );
    }

    /**
     * Reporting lists every group, including the ones only the address filter can see,
     * and changes nothing.
     */
    public function test_report_duplicates_lists_the_legacy_pair_without_merging(): void
    {
        [$older, $newer, $tnBase] = $this->createCanonStyleDuplicatePair();

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('tn:sync --report-duplicates')
            ->expectsOutputToContain($tnBase)
            ->assertExitCode(0);

        $this->assertNotNull(User::find($older->id), 'reporting must not merge');
        $this->assertNotNull(User::find($newer->id), 'reporting must not merge');
    }

    /**
     * Builds the production shape: one TN member, two accounts, the older row
     * carrying REVERSE(canon) and the newer one REVERSE(email).
     *
     * @return array{0: User, 1: User, 2: string}
     */
    private function createCanonStyleDuplicatePair(): array
    {
        $older = $this->createTestUser(['fullname' => 'Immy']);
        $newer = $this->createTestUser(['fullname' => 'Immy']);
        $tnBase = 'i9test' . str_replace('.', '', uniqid('', true));

        // The older account, as V1 wrote it: canon has the -gNNNN suffix and the
        // domain dots stripped, and backwards is the reverse of THAT.
        $olderEmail = "{$tnBase}-g4707@user.trashnothing.com";
        $olderCanon = "{$tnBase}@usertrashnothingcom";
        DB::table('users_emails')->insert([
            'userid' => $older->id,
            'email' => $olderEmail,
            'canon' => $olderCanon,
            'backwards' => strrev($olderCanon),
            'preferred' => 1,
            'added' => now(),
        ]);

        // The account a second TN group's alias minted, as the Laravel model
        // writes it: no canon, backwards reversed from the raw address.
        $newerEmail = "{$tnBase}-g1586@user.trashnothing.com";
        DB::table('users_emails')->insert([
            'userid' => $newer->id,
            'email' => $newerEmail,
            'canon' => null,
            'backwards' => strrev($newerEmail),
            'preferred' => 1,
            'added' => now(),
        ]);

        // Both rows predate the cursor, as the live backlog does, so only a
        // whole-table pass can reach them.
        DB::table('config')->upsert([
            ['key' => 'tn.dupscan_cursor', 'value' => (string) ((int) DB::table('users_emails')->max('id') + 1)],
            ['key' => 'tn.dupscan_full_at', 'value' => now()->toDateTimeString()],
        ], ['key'], ['value']);

        return [$older, $newer, $tnBase];
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
                    'rating_id' => random_int(1000000, 9000000000000),
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

    /**
     * A bound PostSyncer double: the command resolves one with makeWith(), and a closure
     * binding is honoured where an instance is not.
     */
    private function bindPostSyncer(?string $postsMaxDate, ?string $stalledAt): void
    {
        $this->app->bind(PostSyncer::class, fn () => new class($postsMaxDate, $stalledAt) extends PostSyncer {
            public function __construct(private ?string $max, private ?string $stalled) {}

            public function sync(string $from, string $to): array
            {
                return [1, $this->max];
            }

            public function stalledAt(): ?string
            {
                return $this->stalled;
            }
        });
    }

    /**
     * A poster TN could not be asked about stops the post sync. Ratings and user changes
     * that came in after the window it stopped in must not carry the stored date past the
     * post, or the next run starts after it and the post is never fetched again.
     */
    public function test_the_stored_date_stays_before_a_post_the_sync_stopped_at(): void
    {
        config(['freegle.trashnothing.ingest_posts_via_api' => true]);
        file_put_contents($this->dateFile, '2026-03-20T10:00:00Z');
        $user = $this->createTestUser();

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => random_int(1000000, 9000000000000),
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => '2026-03-20T15:00:00Z',
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
        // The posts window began at the stored date less the overlap and stopped there.
        $this->bindPostSyncer('2026-03-20T09:59:50Z', '2026-03-20T09:59:50Z');

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame('2026-03-20T10:00:00Z', trim(file_get_contents($this->dateFile)), 'no progress to record');
    }

    public function test_the_stored_date_advances_to_the_window_a_stop_happened_in(): void
    {
        config(['freegle.trashnothing.ingest_posts_via_api' => true]);
        file_put_contents($this->dateFile, '2026-03-17T10:00:00Z');
        $user = $this->createTestUser();

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => random_int(1000000, 9000000000000),
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => '2026-03-20T15:00:00Z',
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
        // Two days were done and the third stopped at its start.
        $this->bindPostSyncer('2026-03-19T20:00:00Z', '2026-03-19T09:59:50Z');

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame('2026-03-19T09:59:50Z', trim(file_get_contents($this->dateFile)));
    }

    public function test_the_stored_date_is_unaffected_when_the_post_sync_got_through(): void
    {
        config(['freegle.trashnothing.ingest_posts_via_api' => true]);
        file_put_contents($this->dateFile, '2026-03-20T10:00:00Z');
        $user = $this->createTestUser();

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => random_int(1000000, 9000000000000),
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => '2026-03-20T15:00:00Z',
                ]],
            ], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
        $this->bindPostSyncer('2026-03-20T11:00:00Z', null);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame('2026-03-20T15:00:00Z', trim(file_get_contents($this->dateFile)));
    }

    /**
     * One date is stored for ratings, user changes and posts. A feed TN would not serve has
     * changes this run never saw, and the other feeds' dates say nothing about where they are,
     * so the date stays and the next run reads the window again.
     */
    public function test_the_stored_date_stays_when_the_user_changes_feed_could_not_be_read(): void
    {
        file_put_contents($this->dateFile, '2026-03-20T10:00:00Z');
        $user     = $this->createTestUser();
        $ratingId = random_int(1000000, 9000000000000);

        Http::fake([
            '*/ratings*' => Http::response([
                'ratings' => [[
                    'rating_id' => $ratingId,
                    'ratee_fd_user_id' => $user->id,
                    'rating' => 'Up',
                    'date' => '2026-03-20T15:00:00Z',
                ]],
            ], 200),
            '*/user-changes*' => Http::response(null, 500),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame('2026-03-20T10:00:00Z', trim(file_get_contents($this->dateFile)));
        $this->assertTrue(
            DB::table('ratings')->where('tn_rating_id', $ratingId)->exists(),
            'what the run did read was still applied'
        );
    }

    public function test_the_stored_date_stays_when_the_ratings_feed_could_not_be_read(): void
    {
        file_put_contents($this->dateFile, '2026-03-20T10:00:00Z');

        Http::fake([
            '*/ratings*' => Http::response(null, 500),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => null,
                    'date' => '2026-03-20T15:00:00Z',
                ]],
            ], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame('2026-03-20T10:00:00Z', trim(file_get_contents($this->dateFile)));
    }

    public function test_the_stored_date_moves_again_once_the_feed_can_be_read(): void
    {
        file_put_contents($this->dateFile, '2026-03-20T10:00:00Z');

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::sequence()
                ->push(null, 500)
                ->push(['changes' => [['fd_user_id' => null, 'date' => '2026-03-20T15:00:00Z']]], 200),
        ]);

        $this->artisan('tn:sync')->assertExitCode(0);
        $this->assertSame('2026-03-20T10:00:00Z', trim(file_get_contents($this->dateFile)));

        $this->artisan('tn:sync')->assertExitCode(0);
        $this->assertSame('2026-03-20T15:00:00Z', trim(file_get_contents($this->dateFile)));
    }

    /** True while TN will not serve the ratings feed. See feedHoldFake(). */
    private bool $ratingsFeedDown = false;

    /**
     * One fake for a whole test, because fakes merge and the first registered stub wins: the
     * ratings feed is down while $ratingsFeedDown is set, and the user changes feed always
     * serves one change with a later date than the stored one.
     */
    private function feedHoldFake(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ratings')) {
                return $this->ratingsFeedDown
                    ? Http::response(null, 500)
                    : Http::response(['ratings' => []], 200);
            }

            return Http::response(['changes' => [['fd_user_id' => null, 'date' => self::FEED_HOLD_LATER]]], 200);
        });
    }

    /** Binds a recorder as 'sentry' and returns it. */
    private function recordSentry(): object
    {
        $sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = (string) $message;
            }
        };
        $this->app->instance('sentry', $sentry);

        return $sentry;
    }

    /**
     * A feed that fails for good must not hold the date, and so grow the window each run reads,
     * for ever. The date is held for FEED_HOLD_MAX_MINUTES, Sentry hears once the hold has
     * lasted FEED_HOLD_ALERT_MINUTES, and the first run after that gives up and moves on, with
     * a report. One run a minute here.
     */
    public function test_a_feed_that_keeps_failing_is_held_then_alerted_then_given_up_on(): void
    {
        $sentry = $this->recordSentry();
        file_put_contents($this->dateFile, self::FEED_HOLD_STORED);
        $this->ratingsFeedDown = true;
        $this->feedHoldFake();

        for ($minute = 0; $minute < TNSyncCommand::FEED_HOLD_MAX_MINUTES; $minute++) {
            $this->artisan('tn:sync')->assertExitCode(0);

            $this->assertSame(self::FEED_HOLD_STORED, trim(file_get_contents($this->dateFile)), "minute {$minute} holds the date");
            $this->assertCount(
                $minute >= TNSyncCommand::FEED_HOLD_ALERT_MINUTES ? 1 : 0,
                $sentry->calls,
                "minute {$minute}: Sentry hears once, when the hold has lasted"
            );
            $this->travel(1)->minutes();
        }

        $this->assertStringContainsString('ratings feed', $sentry->calls[0]);
        $this->assertStringContainsString('moves on after ' . TNSyncCommand::FEED_HOLD_MAX_MINUTES . ' minutes', $sentry->calls[0]);

        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame(self::FEED_HOLD_LATER, trim(file_get_contents($this->dateFile)), 'the date moves on to what the other feed saw');
        $this->assertCount(2, $sentry->calls, 'and Sentry hears of the give-up');
        $this->assertStringContainsString('gave up on the ratings feed', $sentry->calls[1]);
    }

    /**
     * The limits are minutes, not runs: tn:sync runs every few seconds, and many runs close
     * together are a short hold, not a long one.
     */
    public function test_many_runs_close_together_are_a_short_hold(): void
    {
        $sentry = $this->recordSentry();
        file_put_contents($this->dateFile, self::FEED_HOLD_STORED);
        $this->ratingsFeedDown = true;
        $this->feedHoldFake();

        // 70 runs ten seconds apart: more runs than either limit, in under twelve minutes.
        for ($run = 1; $run <= 70; $run++) {
            $this->artisan('tn:sync')->assertExitCode(0);
            $this->assertSame(self::FEED_HOLD_STORED, trim(file_get_contents($this->dateFile)), "run {$run} holds the date");
            $this->travel(10)->seconds();
        }

        $this->assertSame([], $sentry->calls, 'not yet held for long enough to tell anyone');
    }

    /**
     * And a few runs far apart are a long hold: two runs an hour apart give up.
     */
    public function test_two_runs_an_hour_apart_give_up(): void
    {
        $sentry = $this->recordSentry();
        file_put_contents($this->dateFile, self::FEED_HOLD_STORED);
        $this->ratingsFeedDown = true;
        $this->feedHoldFake();

        $this->artisan('tn:sync')->assertExitCode(0);
        $this->assertSame(self::FEED_HOLD_STORED, trim(file_get_contents($this->dateFile)));

        $this->travel(TNSyncCommand::FEED_HOLD_MAX_MINUTES)->minutes();
        $this->artisan('tn:sync')->assertExitCode(0);

        $this->assertSame(self::FEED_HOLD_LATER, trim(file_get_contents($this->dateFile)), 'the date moves on');
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('gave up on the ratings feed', $sentry->calls[0]);
    }

    /**
     * With nothing from the other feeds to say how far to go, giving up moves the date to the
     * end of the window the run read, so the window stops growing.
     */
    public function test_giving_up_on_a_feed_with_nothing_else_seen_moves_the_date_to_the_end_of_the_window(): void
    {
        $this->recordSentry();
        file_put_contents($this->dateFile, self::FEED_HOLD_STORED);
        Http::fake([
            '*/ratings*' => Http::response(null, 500),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $before = time();
        $this->artisan('tn:sync')->assertExitCode(0);
        $this->travel(TNSyncCommand::FEED_HOLD_MAX_MINUTES)->minutes();
        $this->artisan('tn:sync')->assertExitCode(0);

        $stored = strtotime(trim(file_get_contents($this->dateFile)));
        $this->assertGreaterThanOrEqual($before, $stored);
    }

    /**
     * The hold is of runs in a row. A run that reads every feed ends it, so a feed that fails
     * now and then is never given up on.
     */
    public function test_a_run_that_reads_every_feed_ends_the_hold(): void
    {
        $sentry = $this->recordSentry();
        file_put_contents($this->dateFile, self::FEED_HOLD_STORED);
        $this->feedHoldFake();

        for ($round = 1; $round <= 2; $round++) {
            $this->ratingsFeedDown = true;
            for ($minute = 0; $minute < TNSyncCommand::FEED_HOLD_ALERT_MINUTES; $minute++) {
                $this->artisan('tn:sync')->assertExitCode(0);
                $this->travel(1)->minutes();
            }

            // Reads everything, so the date moves and the hold ends.
            $this->ratingsFeedDown = false;
            $this->artisan('tn:sync')->assertExitCode(0);
            $this->assertSame(self::FEED_HOLD_LATER, trim(file_get_contents($this->dateFile)));
            file_put_contents($this->dateFile, self::FEED_HOLD_STORED);
        }

        $this->assertSame([], $sentry->calls, 'two short holds are not one long one');
    }

    /**
     * Every ten seconds, so a post reaches Freegle seconds after it is posted on TN, and never
     * two at once: a run takes three to five seconds.
     *
     * The scheduler's overlap guard would hold its flock in schedule:run for the whole minute and
     * skip every repeat, so it must not be there. The command's own lock does that job.
     */
    public function test_it_is_scheduled_every_ten_seconds_one_at_a_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => preg_match("/artisan'?\s+tn:sync$/", (string) $e->command) === 1);

        $this->assertNotNull($event);
        $this->assertTrue($event->isRepeatable(), 'more often than once a minute');
        $this->assertSame(10, $event->repeatSeconds);
        $this->assertTrue($event->runInBackground);
        $this->assertFalse($event->withoutOverlapping, 'the scheduler guard would stop the repeats');
        $this->assertContains(PreventsOverlapping::class, class_uses(TNSyncCommand::class), 'the command keeps runs one at a time');
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

        // Verify the API was called with the stored date minus the overlap window as date_min.
        // resolveFromDate() subtracts SYNC_OVERLAP_SECONDS (10) from the stored date.
        $expectedDateMin = '2026-03-14T23:59:50Z';
        Http::assertSent(function ($request) use ($expectedDateMin) {
            return str_contains($request->url(), 'ratings') &&
                   $request['date_min'] === $expectedDateMin;
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
            'tn_rating_id' => random_int(1000000, 9000000000000),
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
            // resolveFromDate() subtracts SYNC_OVERLAP_SECONDS (10) from the fallback timestamp.
            return strtotime($dateMin) === strtotime($knownDate) - 10;
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
                'rating_id' => random_int(1000000, 9000000000000),
                'ratee_fd_user_id' => $user->id,
                'rating' => 'Up',
                'date' => self::DATE_SYNC,
            ];
        }

        // Page 2: 1 additional rating.
        $page2RatingId = random_int(1000000, 9000000000000);
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
                    'rating_id' => random_int(1000000, 9000000000000),
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
        $tnRatingId = random_int(1000000, 9000000000000);

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
        $tnRatingId = random_int(1000000, 9000000000000);

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
        $old = 'oldname' . random_int(100000, 999999);
        $new = 'newname' . random_int(100000, 999999);
        $user = $this->createTNUser('OldName', $old);

        $loki = $this->mock(LokiService::class);
        $loki->shouldIgnoreMissing();
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-email-rename', \Mockery::on(fn($ctx) =>
                $ctx['user_id'] === $user->id &&
                $ctx['old_email'] === "{$old}-g1@user.trashnothing.com" &&
                $ctx['new_email'] === "{$new}-g1@user.trashnothing.com"
            ));
        $loki->shouldReceive('logEvent')
            ->once()
            ->with('tn-sync', 'user-update', \Mockery::any());

        Http::fake([
            '*/ratings*' => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response([
                'changes' => [[
                    'fd_user_id' => $user->id,
                    'username' => $new,
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

    public function test_loki_logs_a_flagged_group_and_no_merge(): void
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
            ->with('tn-sync', 'user-merge-skip', \Mockery::on(fn ($ctx) =>
                $ctx['reason'] === 'tnuserid-unproven'
                && $ctx['user_ids'] === [$user1->id, $user2->id]
                && $ctx['tn_user_ids'] === []
            ));
        $loki->shouldNotReceive('logEvent')->with('tn-sync', 'user-merge', \Mockery::any());

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
            ->where('data->run_id', $runId)
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
                    'rating_id' => random_int(1000000, 9000000000000),
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
    // Overlap-window idempotency
    //
    // Each syncer requests data with a small backward overlap (SYNC_OVERLAP_SECONDS)
    // so data written in the last few seconds of the previous window is not missed.
    // These tests confirm that re-processing the same item from the overlap window
    // does not produce a second row in the database.
    // =========================================================================

    public function test_overlap_window_does_not_duplicate_ratings(): void
    {
        $user       = $this->createTestUser();
        $tnRatingId = random_int(1000000, 9000000000000);

        $ratingPayload = [
            'rating_id'        => $tnRatingId,
            'ratee_fd_user_id' => $user->id,
            'rating'           => 'Up',
            'date'             => self::DATE_SYNC,
        ];

        // First sync window: rating is created for the first time.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => [$ratingPayload]], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
        $this->artisan('tn:sync', ['--from' => '2026-03-20T09:00:00Z', '--to' => '2026-03-20T10:00:00Z'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('ratings')->where('tn_rating_id', $tnRatingId)->count());

        // Second sync window: the same rating falls inside the 10-second overlap and is
        // returned again. firstOrNew on tn_rating_id must not create a second row.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => [$ratingPayload]], 200),
            '*/user-changes*' => Http::response(['changes' => []], 200),
        ]);
        $this->artisan('tn:sync', ['--from' => '2026-03-20T09:59:50Z', '--to' => '2026-03-20T11:00:00Z'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('ratings')->where('tn_rating_id', $tnRatingId)->count());
    }

    public function test_overlap_window_does_not_duplicate_user_reply_times(): void
    {
        $user = $this->createTNUser();

        $changePayload = [
            'fd_user_id' => $user->id,
            'reply_time' => 3600,
            'date'       => self::DATE_SYNC,
        ];

        // First sync window: reply time created.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => [$changePayload]], 200),
        ]);
        $this->artisan('tn:sync', ['--from' => '2026-03-20T09:00:00Z', '--to' => '2026-03-20T10:00:00Z'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('users_replytime')->where('userid', $user->id)->count());

        // Second sync window: same change re-fetched via overlap. firstOrNew on userid
        // must upsert in place and not create a second users_replytime row.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => [$changePayload]], 200),
        ]);
        $this->artisan('tn:sync', ['--from' => '2026-03-20T09:59:50Z', '--to' => '2026-03-20T11:00:00Z'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('users_replytime')->where('userid', $user->id)->count());
    }

    public function test_overlap_window_does_not_duplicate_about_me(): void
    {
        $user = $this->createTNUser();

        $changePayload = [
            'fd_user_id' => $user->id,
            'about_me'   => 'I am a tidy person who loves repurposing things.',
            'date'       => self::DATE_SYNC,
        ];

        // First sync window: about_me created.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => [$changePayload]], 200),
        ]);
        $this->artisan('tn:sync', ['--from' => '2026-03-20T09:00:00Z', '--to' => '2026-03-20T10:00:00Z'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('users_aboutme')->where('userid', $user->id)->count());

        // Second sync window: same about_me re-fetched via overlap.
        Http::fake([
            '*/ratings*'      => Http::response(['ratings' => []], 200),
            '*/user-changes*' => Http::response(['changes' => [$changePayload]], 200),
        ]);
        $this->artisan('tn:sync', ['--from' => '2026-03-20T09:59:50Z', '--to' => '2026-03-20T11:00:00Z'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('users_aboutme')->where('userid', $user->id)->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a user with a TrashNothing email address. $tnUsername sets the
     * username in that address, which is what the sync reads as the current one.
     * The default username is unrelated to $name on purpose: a fullname says nothing
     * about the username, so a test must only pass if the code reads the address.
     */
    private function createTNUser(string $name = 'TNUser', ?string $tnUsername = null): User
    {
        $user = User::create([
            'firstname' => $name,
            'lastname' => 'TN',
            'fullname' => $name,
            'added' => now(),
        ]);

        $uniquePrefix = $tnUsername ?? uniqid('tnuser_', true);

        UserEmail::create([
            'userid' => $user->id,
            'email' => "{$uniquePrefix}-g1@user.trashnothing.com",
            'preferred' => 1,
            'added' => now(),
        ]);

        return $user->fresh();
    }
}
