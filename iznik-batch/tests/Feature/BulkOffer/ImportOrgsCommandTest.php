<?php

namespace Tests\Feature\BulkOffer;

use App\Models\MessagesBulkOutreach;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportOrgsCommandTest extends TestCase
{
    private int $msgid;

    protected function setUp(): void
    {
        parent::setUp();

        // A bulk offer is just a normal Offer message; create one to import against.
        $donor = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->msgid = $this->createTestMessage($donor, $group, [
            'subject' => 'OFFER: Office clearance - 23 desks, 23 chairs (Hampstead)',
        ])->id;
    }

    /** Write a CSV with the outreach-list header and return its path. */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orgcsv').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, [
            'Tier', 'Cost to donor', 'Organisation', 'Type', 'Area', 'Email',
            'Website', 'Likely wants / why', 'Cluster', 'Activity evidence', 'Confidence', 'Source',
        ]);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    public function test_creates_user_and_outreach_with_welcome_suppressed(): void
    {
        $email = $this->uniqueEmail('org');
        $csv = $this->writeCsv([[
            '2 - community recipient', 'free - recipient collects', 'Hampstead Community Centre',
            'community centre', 'Hampstead, NW3', $email, 'https://example.org',
            'Desks and chairs for the office', 'office setup / task chairs',
            'https://example.org/news - 2026-06 - summer programme', 'high', 'dir:camden',
        ]]);

        $code = Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid, '--input' => $csv]);
        $this->assertSame(0, $code);

        // User created with the org name + source, email attached.
        $emailRow = UserEmail::where('email', $email)->first();
        $this->assertNotNull($emailRow, 'user email should be created');
        $user = User::find($emailRow->userid);
        $this->assertSame('Hampstead Community Centre', $user->fullname);
        $this->assertSame('cold_outreach', $user->source);

        // Welcome email suppressed: added is backdated beyond the welcome window (1 day).
        $this->assertTrue(
            Carbon::parse($user->added)->lt(Carbon::now()->subDay()),
            'new org user added-date must be backdated so the welcome mailer skips it'
        );

        // Outreach record with the org details + preferences for this bulk offer.
        $outreach = MessagesBulkOutreach::where('msgid', $this->msgid)->where('userid', $user->id)->first();
        $this->assertNotNull($outreach);
        $this->assertSame(MessagesBulkOutreach::STATUS_IMPORTED, $outreach->status);
        $this->assertSame('community centre', $outreach->orgtype);
        $this->assertSame('2', $outreach->tier);
        $this->assertSame('high', $outreach->confidence);
        $this->assertSame(['office setup', 'task chairs'], $outreach->clusters);
        $this->assertSame('https://example.org/news', $outreach->activity_url);
        $this->assertStringContainsString('office', strtolower($outreach->preferences));

        @unlink($csv);
    }

    public function test_matches_existing_user_by_email(): void
    {
        $email = $this->uniqueEmail('existing');
        $existing = $this->createTestUser(['email_preferred' => $email]);

        $csv = $this->writeCsv([[
            '1 - free reuse charity', 'FREE - charity collects', 'Existing Reuse Charity',
            'charity', 'Camden', $email, 'https://reuse.example', 'Anything', 'office setup',
            'https://reuse.example - 2026-05', 'med', 'tier1',
        ]]);

        $countBefore = User::count();
        Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid, '--input' => $csv]);

        $this->assertSame($countBefore, User::count(), 'must not create a duplicate user');
        $this->assertDatabaseHas('messages_bulk_outreach', [
            'msgid' => $this->msgid,
            'userid' => $existing->id,
            'orgname' => 'Existing Reuse Charity',
        ]);

        @unlink($csv);
    }

    public function test_rerun_upserts_without_duplicating(): void
    {
        $email = $this->uniqueEmail('rerun');
        $csv1 = $this->writeCsv([[
            '2', '', 'Rerun Org', 'charity', 'Camden', $email, '', 'desks', 'office setup', '', 'low', 'dir:camden',
        ]]);
        Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid, '--input' => $csv1]);

        $userid = UserEmail::where('email', $email)->first()->userid;
        $this->assertSame(1, MessagesBulkOutreach::where('msgid', $this->msgid)->where('userid', $userid)->count());

        // Re-run with an updated note - should update the same row, not duplicate.
        $csv2 = $this->writeCsv([[
            '2', '', 'Rerun Org', 'charity', 'Camden', $email, '', 'desks and tables now', 'office setup / tables', '', 'high', 'dir:camden',
        ]]);
        Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid, '--input' => $csv2]);

        $rows = MessagesBulkOutreach::where('msgid', $this->msgid)->where('userid', $userid)->get();
        $this->assertCount(1, $rows, 'UNIQUE(msgid,userid) - re-run must update not duplicate');
        $this->assertSame('high', $rows->first()->confidence);
        $this->assertSame(['office setup', 'tables'], $rows->first()->clusters);

        @unlink($csv1);
        @unlink($csv2);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $email = $this->uniqueEmail('dry');
        $csv = $this->writeCsv([[
            '2', '', 'Dry Run Org', 'charity', 'Camden', $email, '', 'desks', 'office setup', '', 'high', 'dir:camden',
        ]]);

        Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid, '--input' => $csv, '--dry-run' => true]);

        $this->assertNull(UserEmail::where('email', $email)->first(), 'dry-run must not create a user');
        $this->assertSame(0, MessagesBulkOutreach::where('msgid', $this->msgid)->count(), 'dry-run must not write outreach rows');

        @unlink($csv);
    }

    public function test_skips_rows_with_invalid_email(): void
    {
        $good = $this->uniqueEmail('good');
        $csv = $this->writeCsv([
            ['2', '', 'No Email Org', 'charity', 'Camden', '', '', 'desks', 'office setup', '', 'high', 'dir:camden'],
            ['2', '', 'Bad Email Org', 'charity', 'Camden', 'not-an-email', '', 'desks', 'office setup', '', 'high', 'dir:camden'],
            ['2', '', 'Good Org', 'charity', 'Camden', $good, '', 'desks', 'office setup', '', 'high', 'dir:camden'],
        ]);

        Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid, '--input' => $csv]);

        // Only the valid row is imported.
        $this->assertSame(1, MessagesBulkOutreach::where('msgid', $this->msgid)->count());
        $this->assertNotNull(UserEmail::where('email', $good)->first());

        @unlink($csv);
    }

    public function test_fails_when_bulk_offer_message_missing(): void
    {
        $missing = (int) DB::table('messages')->max('id') + 1000000;
        $csv = $this->writeCsv([[
            '2', '', 'Org', 'charity', 'Camden', $this->uniqueEmail('x'), '', 'desks', 'office setup', '', 'high', 'dir',
        ]]);

        $code = Artisan::call('bulkoffer:import-orgs', ['--msgid' => $missing, '--input' => $csv]);
        $this->assertSame(1, $code, 'should fail when the bulk offer message does not exist');
        $this->assertSame(0, MessagesBulkOutreach::where('msgid', $missing)->count());

        @unlink($csv);
    }

    public function test_requires_msgid_and_input(): void
    {
        $this->assertSame(1, Artisan::call('bulkoffer:import-orgs', ['--input' => '/tmp/x.csv']));
        $this->assertSame(1, Artisan::call('bulkoffer:import-orgs', ['--msgid' => $this->msgid]));
    }
}
