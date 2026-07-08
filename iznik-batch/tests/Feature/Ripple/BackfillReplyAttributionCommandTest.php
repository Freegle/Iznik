<?php

namespace Tests\Feature\Ripple;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ripple:backfill-reply-attribution - the one-shot production backfill wrapper around
 * ReplyAttributionBackfillService. Dry-run reports without touching rows; a real run derives
 * attribution for legacy rows and exits cleanly when nothing is pending.
 */
class BackfillReplyAttributionCommandTest extends TestCase
{
    private function seedLegacyRow(): array
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);
        $replier = $this->createTestUser();

        DB::table('rippling_reply_attribution')->insert([
            'msgid' => $message->id,
            'userid' => $replier->id,
            'replied_at' => now()->subHours(2),
            'was_home_member' => 1,
        ]);

        return [$message->id, $replier->id];
    }

    public function test_dry_run_reports_without_changing_rows(): void
    {
        [$msgid, $userid] = $this->seedLegacyRow();

        $this->artisan('ripple:backfill-reply-attribution', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $row = DB::table('rippling_reply_attribution')
            ->where('msgid', $msgid)->where('userid', $userid)->first();
        $this->assertNull($row->attribution, 'dry-run must not derive anything');
    }

    public function test_backfills_legacy_rows(): void
    {
        [$msgid, $userid] = $this->seedLegacyRow();

        $this->artisan('ripple:backfill-reply-attribution')->assertExitCode(0);

        $row = DB::table('rippling_reply_attribution')
            ->where('msgid', $msgid)->where('userid', $userid)->first();
        $this->assertSame('home', $row->attribution);
    }

    public function test_no_pending_rows_is_a_clean_noop(): void
    {
        $this->artisan('ripple:backfill-reply-attribution')->assertExitCode(0);
    }
}
