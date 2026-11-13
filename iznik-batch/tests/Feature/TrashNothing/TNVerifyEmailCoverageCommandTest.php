<?php

namespace Tests\Feature\TrashNothing;

use App\Services\TrashNothing\Ingestion\DeliberateSkips;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Sync\PostSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The command's guard rails. Auto-ingest writes member-visible posts from a
 * scheduled job, so the rules about when it declines to write are the part that
 * most needs pinning down — see plans/tn-api-post-ingestion.md section S.5.
 */
class TNVerifyEmailCoverageCommandTest extends TestCase
{
    private string $archiveDir;

    // Far from the UK and from createTestGroup()'s London default, so this
    // test's own group is the only candidate Location::groupsNear() can return.
    private const LAT = 10.0;

    private const LNG = 10.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveDir = storage_path('incoming-archive/test-verify-' . uniqid('', true));
        mkdir($this->archiveDir, 0755, true);

        config(['freegle.trashnothing.ingest_posts_via_api' => true]);
        config(['freegle.trashnothing.verify_coverage.auto_ingest' => true]);
        config(['freegle.trashnothing.verify_coverage.auto_ingest_max' => 20]);
        config(['freegle.trashnothing.verify_coverage.max_age_hours' => 72]);

        $this->createTestGroup(['lat' => self::LAT, 'lng' => self::LNG]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->archiveDir);
        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Archive a TN post email so the inventory picks it up.
     */
    private function archivePost(string $postId, string $timestamp): void
    {
        $ts      = CarbonImmutable::parse($timestamp, 'UTC');
        $dateDir = $this->archiveDir . '/' . $ts->format('Y-m-d');

        if (! is_dir($dateDir)) {
            mkdir($dateDir, 0755, true);
        }

        $raw = implode("\r\n", [
            'From: Poster <poster@user.trashnothing.com>',
            'Subject: OFFER: Bookshelf (Camden)',
            'Message-ID: <' . $postId . '@tn.trashnothing.com>',
            'Date: ' . $ts->toRfc2822String(),
            'X-Trash-Nothing-Post-Id: ' . $postId,
            'X-Trash-Nothing-Post-Coordinates: ' . self::LAT . ',' . self::LNG,
        ]) . "\r\n\r\nFree to collect.\r\n";

        file_put_contents(
            $dateDir . '/' . $ts->format('His') . '_' . random_int(100000, 999999) . '.json',
            json_encode([
                'version'   => 3,
                'timestamp' => $ts->format('Y-m-d\TH:i:s\Z'),
                'envelope'  => [
                    'from' => 'poster@user.trashnothing.com',
                    'to'   => 'camdengroup@' . config('freegle.mail.group_domain'),
                ],
                'raw_email' => base64_encode($raw),
            ], JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Bind a PostSyncer double reporting every post as a live source post.
     *
     * @return object{ingested: string[]}
     */
    private function bindSyncer(?string $date = null, bool $posterLookupFails = false, ?string $outcome = null): object
    {
        $recorder = new class
        {
            /** @var string[] */
            public array $ingested = [];
        };

        $syncer = $this->getMockBuilder(PostSyncer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lookupPostById', 'ingestFetchedPost'])
            ->getMock();

        $syncer->method('lookupPostById')->willReturnCallback(fn (string $postId) => [
            'status'   => 'found',
            'date'     => $date ?? CarbonImmutable::now('UTC')->subHours(9)->format('Y-m-d\TH:i:s\Z'),
            'outcome'  => $outcome,
            'group_id' => null,
            // The verifier places an absentee from the LIVE post, not from the
            // email header, so a source post has to carry coordinates here or
            // it is expected-absent rather than a miss.
            'lat'      => self::LAT,
            'lng'      => self::LNG,
            'post'     => ['post_id' => $postId],
        ]);

        $syncer->method('ingestFetchedPost')->willReturnCallback(function (mixed $post) use ($recorder, $posterLookupFails) {
            if ($posterLookupFails) {
                return false;
            }

            $recorder->ingested[] = $post['post_id'];

            return true;
        });

        $this->bindSyncerInstance($syncer);

        return $recorder;
    }

    /**
     * bind(), NOT instance().
     *
     * The command resolves the syncer with makeWith() so it can pass the
     * constructor's scalars. Passing parameters makes the container treat the
     * resolution as a contextual build, which skips registered instances
     * outright — an instance() double is silently ignored and the command
     * builds a real PostSyncer pointed at the live TN API. A closure binding is
     * honoured, because getConcrete() returns it before that check applies.
     */
    private function bindSyncerInstance(PostSyncer $syncer): void
    {
        $this->app->bind(PostSyncer::class, fn () => $syncer);
    }

    /**
     * A window that comfortably contains a post archived `$hoursAgo` ago.
     */
    private function runForPostsArchived(int $hoursAgo, array $extra = []): \Illuminate\Testing\PendingCommand
    {
        $to   = CarbonImmutable::now('UTC')->subHours($hoursAgo - 1);
        $from = CarbonImmutable::now('UTC')->subHours($hoursAgo + 1);

        return $this->artisan('tn:verify-email-coverage', array_merge([
            '--archive-dir' => $this->archiveDir,
            '--from'        => $from->format('Y-m-d\TH:i:s\Z'),
            '--to'          => $to->format('Y-m-d\TH:i:s\Z'),
        ], $extra));
    }

    public function test_refuses_to_run_while_the_email_path_is_still_routing(): void
    {
        // Both paths stamp messages.tnpostid, so "covered" would prove nothing.
        config(['freegle.trashnothing.ingest_posts_via_api' => false]);

        $this->artisan('tn:verify-email-coverage', ['--archive-dir' => $this->archiveDir])
            ->assertExitCode(1);
    }

    public function test_force_overrides_the_guard(): void
    {
        config(['freegle.trashnothing.ingest_posts_via_api' => false]);

        $this->artisan('tn:verify-email-coverage', [
            '--archive-dir' => $this->archiveDir,
            '--force'       => true,
        ])->assertExitCode(0);
    }

    public function test_an_empty_window_passes_without_calling_the_api(): void
    {
        $syncer = $this->getMockBuilder(PostSyncer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lookupPostById'])
            ->getMock();
        $syncer->expects($this->never())->method('lookupPostById');
        $this->bindSyncerInstance($syncer);

        $this->artisan('tn:verify-email-coverage', ['--archive-dir' => $this->archiveDir])
            ->assertExitCode(0);
    }

    public function test_a_missing_archive_fails_rather_than_passing_green(): void
    {
        // A verifier that reports "all clear" because it cannot see anything is
        // the exact failure mode this command exists to prevent.
        $this->artisan('tn:verify-email-coverage', [
            '--archive-dir' => $this->archiveDir . '/gone',
        ])->assertExitCode(1);
    }

    public function test_backfills_a_genuine_miss(): void
    {
        $this->archivePost('tn-miss-1', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)->assertExitCode(0);

        $this->assertSame(['tn-miss-1'], $recorder->ingested);
    }

    /**
     * TN could not be asked who the poster is, so nothing was ingested. That is not a
     * backfill to remember (it would then count as a repeat miss and fail the run for a
     * week) and not a failure either: the post is still there to try on the next run.
     */
    public function test_a_post_whose_poster_could_not_be_looked_up_is_tried_again_next_run(): void
    {
        $this->archivePost('tn-poster-lookup', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $this->bindSyncer(posterLookupFails: true);

        $this->runForPostsArchived(9)->assertExitCode(0);

        $this->assertFalse(Cache::has('tn-verify:backfilled:tn-poster-lookup'));

        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)->assertExitCode(0);

        $this->assertSame(['tn-poster-lookup'], $recorder->ingested);
    }

    public function test_report_only_never_writes(): void
    {
        $this->archivePost('tn-miss-2', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9, ['--report-only' => true])->assertExitCode(0);

        $this->assertSame([], $recorder->ingested);
    }

    public function test_config_default_off_means_report_only(): void
    {
        config(['freegle.trashnothing.verify_coverage.auto_ingest' => false]);

        $this->archivePost('tn-miss-3', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)->assertExitCode(0);

        $this->assertSame([], $recorder->ingested);
    }

    public function test_a_mass_miss_ingests_nothing_and_fails(): void
    {
        // Rail 4: this is a broken sync, not a backfill job. Backfilling
        // hundreds of hours-late posts is worse than paging a human.
        config(['freegle.trashnothing.verify_coverage.auto_ingest_max' => 2]);

        foreach (range(1, 3) as $i) {
            $this->archivePost('tn-mass-' . $i, CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        }

        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)->assertExitCode(1);

        $this->assertSame([], $recorder->ingested);
    }

    /**
     * The ten-minute catch-up checks the recent past but leaves the last few minutes to
     * the sync, which ingests a published post a minute or two after its email.
     */
    public function test_recent_checks_the_last_minutes_but_not_the_newest(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->archivePost('tn-recent-old', $now->subMinutes(30)->toIso8601String());
        $this->archivePost('tn-recent-new', $now->subMinutes(2)->toIso8601String());
        $recorder = $this->bindSyncer(date: $now->subMinutes(30)->format('Y-m-d\TH:i:s\Z'));

        $this->artisan('tn:verify-email-coverage', [
            '--archive-dir' => $this->archiveDir,
            '--recent'      => 70,
        ])->assertExitCode(0);

        $this->assertSame(['tn-recent-old'], $recorder->ingested);
    }

    /**
     * Ingestion skips a post whose username belongs to another TN member's account, and
     * notes why. The catch-up runs every ten minutes over overlapping windows, so it meets
     * the post again: that is a person's job, reported each time, never a failed backfill.
     */
    public function test_a_post_ingestion_skipped_for_a_noted_reason_is_reported_not_a_repeat(): void
    {
        $this->archivePost('tn-clash-1', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());

        $syncer = $this->getMockBuilder(PostSyncer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lookupPostById', 'ingestFetchedPost'])
            ->getMock();
        $syncer->method('lookupPostById')->willReturnCallback(fn (string $postId) => [
            'status'   => 'found',
            'date'     => CarbonImmutable::now('UTC')->subHours(9)->format('Y-m-d\TH:i:s\Z'),
            'outcome'  => null,
            'group_id' => null,
            'lat'      => self::LAT,
            'lng'      => self::LNG,
            'post'     => ['post_id' => $postId],
        ]);
        $syncer->method('ingestFetchedPost')->willReturnCallback(function (mixed $post) {
            DeliberateSkips::record($post['post_id'], GroupPostIngestionService::REASON_TN_USER_LOOKUP_REFUSED);

            return true;
        });
        $this->bindSyncerInstance($syncer);

        $this->runForPostsArchived(9)->assertExitCode(0);
        $this->runForPostsArchived(9)
            ->expectsOutputToContain('needs a person')
            ->assertExitCode(0);
    }

    public function test_max_raises_the_cap_for_one_run(): void
    {
        config(['freegle.trashnothing.verify_coverage.auto_ingest_max' => 2]);

        foreach (range(1, 3) as $i) {
            $this->archivePost('tn-max-' . $i, CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        }

        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9, ['--max' => 5])->assertExitCode(0);

        $this->assertCount(3, $recorder->ingested);
    }

    /**
     * A post TN held until a Freegle community approved it may be closed on TN before
     * Freegle ever has it. It is only backfilled when asked, so it can be counted with
     * its outcome; otherwise it stays an expected absence.
     */
    public function test_a_post_closed_on_tn_is_backfilled_only_with_ingest_closed(): void
    {
        $this->archivePost('tn-closed-1', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $recorder = $this->bindSyncer(outcome: 'satisfied');

        $this->runForPostsArchived(9)->assertExitCode(0);
        $this->assertSame([], $recorder->ingested);

        $this->runForPostsArchived(9, ['--ingest-closed' => true])->assertExitCode(0);
        $this->assertSame(['tn-closed-1'], $recorder->ingested);
    }

    public function test_a_stale_post_is_not_backfilled(): void
    {
        // Rail 5: a very old post surfacing now is more likely a data problem.
        config(['freegle.trashnothing.verify_coverage.max_age_hours' => 4]);

        $this->archivePost('tn-stale', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $recorder = $this->bindSyncer(CarbonImmutable::now('UTC')->subHours(9)->format('Y-m-d\TH:i:s\Z'));

        $this->runForPostsArchived(9)->assertExitCode(0);

        $this->assertSame([], $recorder->ingested);
    }

    public function test_a_repeat_miss_escalates_instead_of_reingesting(): void
    {
        // Rail 6: we already backfilled this one and it is STILL missing, so
        // ingestion is failing rather than lagging. Retrying would just loop.
        $this->archivePost('tn-repeat', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        Cache::put('tn-verify:backfilled:tn-repeat', true, now()->addDay());

        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)->assertExitCode(1);

        $this->assertSame([], $recorder->ingested);
    }

    public function test_a_covered_post_is_not_backfilled(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => self::LAT, 'lng' => self::LNG]);
        $this->createTestMessage($user, $group, ['tnpostid' => 'tn-already-there']);

        $this->archivePost('tn-already-there', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)->assertExitCode(0);

        $this->assertSame([], $recorder->ingested);
    }

    /**
     * Ingestion skipped these posts on purpose (a banned poster, a post TN gave no user id for).
     * They are shown, with the reason, and are not misses: nothing is backfilled, a repeat is
     * not escalated, and more of them than the cap allows is not "a broken sync".
     */
    public function test_posts_skipped_on_purpose_are_reported_and_never_a_miss(): void
    {
        config(['freegle.trashnothing.verify_coverage.auto_ingest_max' => 1]);

        foreach (['tn-skip-1', 'tn-skip-2', 'tn-skip-3'] as $postId) {
            $this->archivePost($postId, CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        }
        DeliberateSkips::record('tn-skip-1', GroupPostIngestionService::REASON_BANNED);
        DeliberateSkips::record('tn-skip-2', GroupPostIngestionService::REASON_BANNED);
        DeliberateSkips::record('tn-skip-3', GroupPostIngestionService::REASON_NO_USER_ID);
        // A post that is backfilled and skipped again would otherwise come back as a repeat.
        Cache::put('tn-verify:backfilled:tn-skip-1', true, now()->addDay());

        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)
            ->expectsOutputToContain('skipped on purpose  3')
            ->expectsOutputToContain('GENUINE MISSES      0')
            ->assertExitCode(0);

        $this->assertSame([], $recorder->ingested);
    }

    /**
     * A post skipped because two members share a TN username, or a deleted account holds the
     * poster's address or id, waits for a person. It is a miss again, with the reason beside it
     * and counted per reason, and the backfill gets its chance to ingest it.
     */
    public function test_posts_skipped_for_a_reason_that_needs_a_person_are_misses_with_their_reasons(): void
    {
        foreach (['tn-clash-1', 'tn-clash-2', 'tn-deleted-1'] as $postId) {
            $this->archivePost($postId, CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        }
        DeliberateSkips::record('tn-clash-1', GroupPostIngestionService::REASON_TN_USER_LOOKUP_REFUSED);
        DeliberateSkips::record('tn-clash-2', GroupPostIngestionService::REASON_TN_USER_LOOKUP_REFUSED);
        DeliberateSkips::record('tn-deleted-1', GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED);
        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)
            ->expectsOutputToContain('skipped on purpose  0')
            ->expectsOutputToContain('GENUINE MISSES      3  (ingestion skipped 3: tn-account-deleted: 1, tn-user-lookup-refused: 2)')
            ->expectsOutputToContain('skipped=tn-user-lookup-refused')
            ->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['tn-clash-1', 'tn-clash-2', 'tn-deleted-1'], $recorder->ingested);
    }

    public function test_a_post_skipped_on_purpose_does_not_hide_a_real_miss_beside_it(): void
    {
        $this->archivePost('tn-skip-4', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        $this->archivePost('tn-real-miss', CarbonImmutable::now('UTC')->subHours(9)->toIso8601String());
        DeliberateSkips::record('tn-skip-4', GroupPostIngestionService::REASON_BANNED);
        $recorder = $this->bindSyncer();

        $this->runForPostsArchived(9)
            ->expectsOutputToContain('GENUINE MISSES      1')
            ->assertExitCode(0);

        $this->assertSame(['tn-real-miss'], $recorder->ingested);
    }
}
