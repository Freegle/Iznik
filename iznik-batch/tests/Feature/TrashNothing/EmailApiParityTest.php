<?php

namespace Tests\Feature\TrashNothing;

use App\Models\Group;
use App\Models\Membership;
use App\Models\UserEmail;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Runs the legacy email path (tn:replay-emails) and the new API path
 * (tn:sync --local-testing) over the same fixture data and checks their
 * TN-SYNC-TRACE log output lines up.
 *
 * The email path loads tests/fixtures/tn_sync/fd_post_log.csv (four posts).
 * The API path loads tests/fixtures/tn_sync/posts_page_1.json (the same
 * four posts). The --from/--to window passed to tn:sync is derived from the
 * min/max Date values in the CSV so both commands operate on the same range.
 *
 * Fixture data deliberately uses MODERATED members / an unmapped member /
 * a Big-Switch-moderated group — every post routes to "Pending" on both
 * paths. Posts from a DEFAULT/UNMODERATED member are NOT covered here:
 * GroupPostIngestionService::ingest() routes those straight to "approved",
 * while IncomingMailService::handleGroupPost() intentionally no longer
 * approves on arrival (see the routing note there) — a known, accepted
 * divergence pending resolution, not something this test should paper over
 * by asserting a false equivalence.
 *
 * Each command runs inside its own transaction that is rolled back
 * afterward, so the second command sees the same starting DB state as the
 * first rather than hitting postAlreadyExists()/duplicate-messageid
 * detection from the first command's writes.
 */
class EmailApiParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The CSV fixture has no secret column; EmailReplaySyncer injects the
        // configured secret so IncomingMailService::shouldSkipSpamCheck() returns
        // true (all CSV rows are real TN posts that originally carried the secret).
        config(['freegle.mail.trashnothing_secret' => 'test-secret-12345']);
    }

    public function test_email_replay_and_api_dry_run_produce_matching_write_traces(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'name' => 'TestLocation_' . uniqid('', true),
            'type' => 'Postcode',
            'lat'  => 55.9533,
            'lng'  => -3.1883,
        ]);

        // Both syncers resolve message location via a spatial-server KNN call;
        // fake it to deterministically return the same location for every
        // coordinate so both paths compute an identical locationid.
        Http::fake([
            '*/v1/postcodes/knn*' => Http::response(['results' => [['id' => $locationId, 'distance' => 0]]], 200),
        ]);

        $groupA = Group::firstOrCreate(['nameshort' => '8444'], [
            'namefull' => 'TN Parity Test Group A',
            'type'     => Group::TYPE_FREEGLE,
            'region'   => 'TestRegion',
            'lat'      => 55.9533,
            'lng'      => -3.1883,
            'onhere'   => 1,
            'publish'  => 1,
        ]);

        $groupB = Group::firstOrCreate(['nameshort' => '8445'], [
            'namefull'           => 'TN Parity Test Group B',
            'type'               => Group::TYPE_FREEGLE,
            'region'             => 'TestRegion',
            'lat'                => 55.8642,
            'lng'                => -4.2518,
            'onhere'             => 1,
            'publish'            => 1,
            'overridemoderation' => 'ModerateAll',
        ]);

        // Matches user_id/from_address in posts_page_1.json / fd_post_log.csv.
        $this->seedTestUser(99010001, 'usera@user.trashnothing.com', $groupA->id, 'MODERATED', $locationId);
        $this->seedTestUser(99010002, 'userb@user.trashnothing.com', $groupA->id, 'DEFAULT', null);
        $this->seedTestUser(99010003, 'userc@user.trashnothing.com', $groupB->id, 'DEFAULT', $locationId);

        // Derive the date window from the fixture CSV so both commands operate
        // on the same range without hard-coding dates here.
        [$from, $to] = $this->getFixtureDateRange();

        $emailLogLines = $this->captureTraceLogs(function () {
            $this->artisan('tn:replay-emails', [
                '--local-testing' => true,
            ])->assertExitCode(0);
        });

        $apiLogLines = $this->captureTraceLogs(function () use ($from, $to) {
            $this->artisan('tn:sync', [
                '--from'          => $from,
                '--to'            => $to,
                '--local-testing' => true,
            ])->assertExitCode(0);
        });

        $this->assertNotEmpty($emailLogLines, 'Expected TN-SYNC-TRACE log lines from tn:replay-emails');
        $this->assertNotEmpty($apiLogLines, 'Expected TN-SYNC-TRACE log lines from tn:sync');

        $this->assertSame(
            $this->normalizeTraceLines($emailLogLines),
            $this->normalizeTraceLines($apiLogLines),
            "Legacy email path and API path TN-SYNC-TRACE [WRITE]/[POST-RESULT]/[POST-SKIP] lines diverged.\n\n"
            . "Email path:\n" . implode("\n", $emailLogLines) . "\n\n"
            . "API path:\n" . implode("\n", $apiLogLines)
        );
    }

    /**
     * Read the fixture CSV and return [minDate, maxDate] from its Date column.
     * These are passed as --from/--to to tn:sync so the API-path fixture window
     * matches the emails being replayed.
     *
     * @return array{string|null, string|null}
     */
    private function getFixtureDateRange(): array
    {
        $path    = base_path('tests/fixtures/tn_sync/fd_post_log.csv');
        $csvText = (string) file_get_contents($path);

        return EmailReplaySyncer::extractDateRangeFromCsvText($csvText);
    }

    /**
     * Runs $callback inside a rolled-back transaction, capturing every
     * TN-SYNC-TRACE [WRITE]/[POST-RESULT]/[POST-SKIP] log line emitted
     * during it. Rolling back means the next call starts from the same
     * clean state (auto-increment counters still advance, but no rows
     * persist — see normalizeTraceLines() for why that's fine).
     *
     * @return string[]
     */
    private function captureTraceLogs(\Closure $callback): array
    {
        $lines    = [];
        $listener = function ($message) use (&$lines) {
            $text = (string) $message->message;
            // [WRITE] is restricted to `table=` (DB row writes) — tn:sync also emits a
            // command-level `[WRITE] op=file-write ...` line for its sync-date-file
            // bookkeeping, which has no equivalent in tn:replay-emails and isn't part
            // of per-post ingestion behaviour.
            if (preg_match('/TN-SYNC-TRACE \[(POST-RESULT|POST-SKIP)\]/', $text)
                || preg_match('/TN-SYNC-TRACE \[WRITE\] table=/', $text)) {
                $lines[] = $text;
            }
        };

        Log::listen($listener);

        DB::beginTransaction();
        try {
            $callback();
        } finally {
            DB::rollBack();
        }

        return $lines;
    }

    /**
     * Strips the one class of value that's expected to differ between two
     * independent runs against the same fixtures: the message's own
     * auto-increment primary key (msgid=...), which advances every insert
     * even across a rolled-back transaction. Everything else (groupid,
     * fromuser, subject, lat/lng, locationid, collection, reason) is fixed
     * by the seeded users/groups and fixture data, so it's expected to
     * match exactly.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private function normalizeTraceLines(array $lines): array
    {
        return array_map(
            static fn (string $line) => preg_replace('/\bmsgid=\d+/', 'msgid=N', $line),
            $lines
        );
    }

    private function seedTestUser(int $id, string $email, int $groupId, string $postingStatus, ?int $lastLocation): void
    {
        DB::table('users')->insert([
            'id'           => $id,
            'fullname'     => 'TN Parity Test User ' . $id,
            'systemrole'   => 'User',
            'added'        => now(),
            'lastlocation' => $lastLocation,
        ]);

        UserEmail::create([
            'userid'    => $id,
            'email'     => $email,
            'preferred' => 1,
            'added'     => now(),
            'canon'     => $email,
        ]);

        Membership::create([
            'userid'           => $id,
            'groupid'          => $groupId,
            'role'             => Membership::ROLE_MEMBER,
            'collection'       => Membership::COLLECTION_APPROVED,
            'emailfrequency'   => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'ourPostingStatus' => $postingStatus,
            'added'            => now(),
        ]);
    }
}
