<?php

namespace Tests\Feature\TrashNothing;

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
    private function bindSyncer(?string $date = null): object
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
            'outcome'  => null,
            'group_id' => null,
            // The verifier places an absentee from the LIVE post, not from the
            // email header, so a source post has to carry coordinates here or
            // it is expected-absent rather than a miss.
            'lat'      => self::LAT,
            'lng'      => self::LNG,
            'post'     => ['post_id' => $postId],
        ]);

        $syncer->method('ingestFetchedPost')->willReturnCallback(function (mixed $post) use ($recorder) {
            $recorder->ingested[] = $post['post_id'];
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
}
