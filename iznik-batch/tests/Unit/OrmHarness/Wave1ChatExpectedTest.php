<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, reply-expected tracking: Layer 1 parity for the converted sites in
 * app/Services/ChatExpectedService.php.
 */
class Wave1ChatExpectedTest extends TestCase
{
    // Two identical statements, at the two "stop expecting a reply" call sites.
    // UPDATE chat_messages SET replyexpected = 0 WHERE id = ?
    private const SITE_CLEAR_EXPECTED_A = '2ed1e85ee303';
    private const SITE_CLEAR_EXPECTED_B = 'db62be21a27d';

    // UPDATE chat_messages SET replyreceived = 1 WHERE id = ?
    private const SITE_MARK_RECEIVED = '96489b97d4c7';

    // SELECT cm.date FROM chat_messages cm
    //   WHERE cm.chatid = ? AND cm.userid = ? AND cm.id > ? ORDER BY cm.id DESC LIMIT 1
    private const SITE_OUTSTANDING = 'd0ee07b50420';

    // SELECT MAX(date) AS max FROM chat_messages WHERE chatid = ? AND id < ? AND userid = ?
    private const SITE_LAST_OTHER_MAX = '2b0a0c6d24d3';

    public function test_clear_reply_expected(): void
    {
        $build = fn () => [
            DB::table('chat_messages')->where('id', 1),
            ['replyexpected' => 0],
        ];

        GoldenSql::assertUpdate(self::SITE_CLEAR_EXPECTED_A, $build);
        GoldenSql::assertUpdate(self::SITE_CLEAR_EXPECTED_B, $build);
    }

    public function test_mark_reply_received(): void
    {
        GoldenSql::assertUpdate(self::SITE_MARK_RECEIVED, fn () => [
            DB::table('chat_messages')->where('id', 1),
            ['replyreceived' => 1],
        ]);
    }

    public function test_outstanding_unreplied_message(): void
    {
        GoldenSql::assert(self::SITE_OUTSTANDING, fn () => DB::table('chat_messages as cm')
            ->select('cm.date')
            ->where('cm.chatid', 1)
            ->where('cm.userid', 2)
            ->where('cm.id', '>', 3)
            ->orderByDesc('cm.id')
            ->limit(1));
    }

    /**
     * This site carries a recorded approvedDiff, and the reason is worth
     * knowing before anyone "fixes" it: ->max('date') emits its result under
     * the alias `aggregate`, where the raw statement said `AS max`. That is
     * equivalent rather than merely similar - the alias never leaves the
     * statement, since ->max() reads the value off it and hands back a scalar,
     * and the caller was rewritten from $lastOther[0]->max to that scalar.
     *
     * The rendering that WOULD match the golden byte for byte is
     * ->selectRaw('MAX(date) AS max'), and it would be a step backwards:
     * selectRaw is itself a raw site in this inventory, so the site would move
     * from surface "statement" to surface "expression" and nothing would have
     * been converted. Matching the golden is not the goal; removing the raw
     * SQL without changing behaviour is.
     */
    public function test_last_other_message_time(): void
    {
        GoldenSql::assert(self::SITE_LAST_OTHER_MAX, function () {
            $q = DB::table('chat_messages')
                ->where('chatid', 1)
                ->where('id', '<', 2)
                ->where('userid', 3);
            // Mirror what ->max('date') assembles, without executing it.
            $q->aggregate = ['function' => 'max', 'columns' => ['date']];

            return $q;
        });
    }
}
