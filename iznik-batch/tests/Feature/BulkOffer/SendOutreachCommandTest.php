<?php

namespace Tests\Feature\BulkOffer;

use App\Models\MessagesBulkOutreach;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises bulkoffer:send-outreach in dry-run (the default) so the whole render +
 * send path runs without contacting Google. Dry-run send returns a 'dryrun-...'
 * id, which the command stores in gmail_message_id - so a Sent row with that
 * marker proves the email was fully built and "sent".
 */
class SendOutreachCommandTest extends TestCase
{
    private int $msgid;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.gmail_outreach.dry_run', true);

        $donor = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->msgid = $this->createTestMessage($donor, $group, [
            'subject' => 'OFFER: Office clearance (Hampstead)',
        ])->id;

        // One catalogue item so the email has content.
        DB::table('messages_bulk_items')->insert([
            'msgid' => $this->msgid,
            'position' => 0,
            'name' => 'Desk lamp',
            'quantity' => 6,
            'condition' => 'Good',
        ]);
    }

    /** Queue a single org and return its outreach row id. */
    private function queueOrg(int $msgid, array $overrides = []): int
    {
        $org = $this->createTestUser(['email_preferred' => $this->uniqueEmail('org')]);

        return DB::table('messages_bulk_outreach')->insertGetId(array_merge([
            'msgid' => $msgid,
            'userid' => $org->id,
            'orgname' => 'Hampstead Community Centre',
            'area' => 'Hampstead, NW3',
            'tier' => '2',
            'status' => MessagesBulkOutreach::STATUS_QUEUED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_dry_run_renders_and_marks_row_sent(): void
    {
        $id = $this->queueOrg($this->msgid);

        $code = Artisan::call('bulkoffer:send-outreach', ['--msgid' => $this->msgid]);
        $this->assertSame(0, $code);

        $row = DB::table('messages_bulk_outreach')->find($id);
        $this->assertSame(MessagesBulkOutreach::STATUS_SENT, $row->status);
        $this->assertSame('email', $row->sent_via);
        $this->assertNotNull($row->sent_at);
        $this->assertStringStartsWith('dryrun-', (string) $row->gmail_message_id, 'dry-run send id should be recorded');
    }

    public function test_skips_suppressed_rows(): void
    {
        $id = $this->queueOrg($this->msgid, ['suppressed_until' => now()->addDay()->toDateString()]);

        Artisan::call('bulkoffer:send-outreach', ['--msgid' => $this->msgid]);

        $row = DB::table('messages_bulk_outreach')->find($id);
        $this->assertSame(MessagesBulkOutreach::STATUS_QUEUED, $row->status, 'a future-suppressed org must not be emailed');
    }

    public function test_respects_limit_flag(): void
    {
        $this->queueOrg($this->msgid);
        $this->queueOrg($this->msgid);
        $this->queueOrg($this->msgid);

        Artisan::call('bulkoffer:send-outreach', ['--msgid' => $this->msgid, '--limit' => 2]);

        $sent = DB::table('messages_bulk_outreach')
            ->where('msgid', $this->msgid)
            ->where('status', MessagesBulkOutreach::STATUS_SENT)
            ->count();
        $this->assertSame(2, $sent);
    }

    public function test_only_touches_the_given_offer(): void
    {
        $other = $this->createTestMessage(
            $this->createTestUser(),
            $this->createTestGroup(),
            ['subject' => 'OFFER: Other clearance']
        )->id;
        DB::table('messages_bulk_items')->insert([
            'msgid' => $other, 'position' => 0, 'name' => 'Chair', 'quantity' => 2, 'condition' => 'Used',
        ]);
        $mine = $this->queueOrg($this->msgid);
        $theirs = $this->queueOrg($other);

        Artisan::call('bulkoffer:send-outreach', ['--msgid' => $this->msgid]);

        $this->assertSame(MessagesBulkOutreach::STATUS_SENT, DB::table('messages_bulk_outreach')->find($mine)->status);
        $this->assertSame(MessagesBulkOutreach::STATUS_QUEUED, DB::table('messages_bulk_outreach')->find($theirs)->status);
    }

    public function test_refuses_live_without_explicit_flag(): void
    {
        Config::set('services.gmail_outreach.dry_run', false);
        $id = $this->queueOrg($this->msgid);

        $code = Artisan::call('bulkoffer:send-outreach', ['--msgid' => $this->msgid]);

        $this->assertSame(1, $code, 'live send without --live must fail');
        $this->assertSame(MessagesBulkOutreach::STATUS_QUEUED, DB::table('messages_bulk_outreach')->find($id)->status);
    }
}
