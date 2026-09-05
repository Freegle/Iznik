<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A partition cutover switches every reader from the live label to the one
 * staged for the next partition in ONE step: the config pairing record
 * changes and every staged post follows, with nothing rewritten. These pin
 * the three rules that make that atomic:
 *
 *   - no pairing record  -> the live column, exactly as before
 *   - record = the stamp -> the staged label
 *   - record != the stamp -> the live column (a stale stage is ignored)
 */
class ReachLabelsStagingTest extends TestCase
{
    private const FP = 1259147727407222857;

    protected function setUp(): void
    {
        parent::setUp();
        ReachService::resetPartitionFpMemo();
    }

    protected function tearDown(): void
    {
        ReachService::resetPartitionFpMemo();
        parent::tearDown();
    }

    private function setPairing(?string $value): void
    {
        DB::table('config')->where('key', ReachService::PARTITION_FP_CONFIG_KEY)->delete();
        if ($value !== null) {
            DB::table('config')->insert(['key' => ReachService::PARTITION_FP_CONFIG_KEY, 'value' => $value]);
        }
        ReachService::resetPartitionFpMemo();
    }

    private function row(string $live, ?string $next, ?int $nextFp): object
    {
        return (object) ['reach_labels' => $live, 'reach_labels_next' => $next, 'reach_labels_next_fp' => $nextFp];
    }

    public function testNoPairingRecordMeansTheLiveColumn(): void
    {
        $this->setPairing(null);

        $this->assertNull(ReachService::livePartitionFp());
        $this->assertSame('rr.reach_labels', ReachService::liveLabelsSql('rr.'));
        $this->assertSame('reach_labels', ReachService::liveLabelsSql());
        $this->assertSame('live', ReachService::pickLabels($this->row('live', 'staged', self::FP)));
    }

    public function testMatchingStampPicksTheStagedLabel(): void
    {
        $this->setPairing((string) self::FP);

        $this->assertSame(self::FP, ReachService::livePartitionFp());
        $this->assertSame(
            'COALESCE(IF(rr.reach_labels_next_fp = '.self::FP.', rr.reach_labels_next, NULL), rr.reach_labels)',
            ReachService::liveLabelsSql('rr.')
        );
        $this->assertSame('staged', ReachService::pickLabels($this->row('live', 'staged', self::FP)));
    }

    public function testStaleStampFallsBackToTheLiveColumn(): void
    {
        $this->setPairing((string) self::FP);

        // Staged for some OTHER partition: not this engine's, so ignored.
        $this->assertSame('live', ReachService::pickLabels($this->row('live', 'staged', 42)));
        // Nothing staged at all.
        $this->assertSame('live', ReachService::pickLabels($this->row('live', null, null)));
        $this->assertNull(ReachService::pickLabels(null));
    }

    public function testNonNumericRecordIsTreatedAsAbsent(): void
    {
        $this->setPairing('not a fingerprint');

        $this->assertNull(ReachService::livePartitionFp());
        $this->assertSame('reach_labels', ReachService::liveLabelsSql());
    }

    public function testSqlExpressionSelectsTheSameBlobAsPhp(): void
    {
        $this->setPairing((string) self::FP);

        // The SQL and PHP forms must agree, or a digest could probe one blob
        // while the reply gate decodes another.
        $expr = ReachService::liveLabelsSql();
        $picked = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, 'staged' AS reach_labels_next, ? AS reach_labels_next_fp) AS t",
            [self::FP]
        );
        $this->assertSame('staged', $picked->picked);

        $stale = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, 'staged' AS reach_labels_next, ? AS reach_labels_next_fp) AS t",
            [42]
        );
        $this->assertSame('live', $stale->picked);

        $unstaged = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, NULL AS reach_labels_next, NULL AS reach_labels_next_fp) AS t"
        );
        $this->assertSame('live', $unstaged->picked);
    }
}
