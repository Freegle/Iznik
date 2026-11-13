<?php

namespace Tests\Unit\Services\Ripple;

use App\Database\Expressions\CastAs;
use App\Database\Sql;
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
            DB::connection()->getDriverName() === 'pgsql'
                ? 'COALESCE(CASE WHEN rr.reach_labels_next_fp = '.self::FP.' THEN rr.reach_labels_next END, rr.reach_labels)'
                : 'COALESCE(IF(rr.reach_labels_next_fp = '.self::FP.', rr.reach_labels_next, NULL), rr.reach_labels)',
            ReachService::liveLabelsSql('rr.')
        );
        $this->assertSame('staged', ReachService::pickLabels($this->row('live', 'staged', self::FP)));
    }

    /**
     * The fingerprint is a 64-bit hash, so half of all values are above the largest PHP integer. Read as
     * an integer such a value became the largest integer: the SQL then matched no stamp, and in PHP any
     * two large fingerprints looked the same. It must be matched exactly in both.
     */
    public function testAFingerprintAboveTheLargestIntegerStillPicksTheStagedLabel(): void
    {
        $big = '12345678901234567890';
        $this->setPairing($big);
        $pg = DB::connection()->getDriverName() === 'pgsql';
        // What the fp column holds: the digits on MySQL, the same 64 bits as a signed number on PostgreSQL.
        $stored = ReachService::fingerprintForDb($big);
        $otherStored = ReachService::fingerprintForDb('12345678901234567891');

        $this->assertSame($big, ReachService::livePartitionFingerprint());
        $this->assertSame($big, ReachService::fingerprintFromDb($stored));
        $this->assertSame(
            $pg
                ? 'COALESCE(CASE WHEN rr.reach_labels_next_fp = '.$stored.' THEN rr.reach_labels_next END, rr.reach_labels)'
                : 'COALESCE(IF(rr.reach_labels_next_fp = '.$big.', rr.reach_labels_next, NULL), rr.reach_labels)',
            ReachService::liveLabelsSql('rr.')
        );

        $row = (object) ['reach_labels' => 'live', 'reach_labels_next' => 'staged', 'reach_labels_next_fp' => $stored];
        $this->assertSame('staged', ReachService::pickLabels($row));

        // Another fingerprint that is also above the largest integer belongs to another layout.
        $other = (object) ['reach_labels' => 'live', 'reach_labels_next' => 'staged', 'reach_labels_next_fp' => $otherStored];
        $this->assertSame('live', ReachService::pickLabels($other));

        // A record typed by hand may end in a newline. It is the same fingerprint, in SQL and in PHP.
        $this->setPairing($big."\n");
        $this->assertSame($big, ReachService::livePartitionFingerprint());
        $this->assertSame('staged', ReachService::pickLabels($row));

        // The SQL picks the same blob from a real column value. On MySQL the bind goes through CHAR
        // because MySQL reads a bare parameter as a signed number, which cannot hold this one.
        $expr = ReachService::liveLabelsSql();
        $column = $pg ? 'CAST(? AS bigint)' : 'CAST(CAST(? AS CHAR) AS UNSIGNED)';
        $picked = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, 'staged' AS reach_labels_next, {$column} AS reach_labels_next_fp) AS t",
            [$stored]
        );
        $this->assertSame('staged', $picked->picked);
        $stale = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, 'staged' AS reach_labels_next, {$column} AS reach_labels_next_fp) AS t",
            [$otherStored]
        );
        $this->assertSame('live', $stale->picked);
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
        $fpBind = Sql::render(new CastAs(DB::raw('?'), 'SIGNED'));
        $nullFp = Sql::render(new CastAs(DB::raw('NULL'), 'SIGNED'));
        $picked = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, 'staged' AS reach_labels_next, {$fpBind} AS reach_labels_next_fp) AS t",
            [self::FP]
        );
        $this->assertSame('staged', $picked->picked);

        $stale = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, 'staged' AS reach_labels_next, {$fpBind} AS reach_labels_next_fp) AS t",
            [42]
        );
        $this->assertSame('live', $stale->picked);

        $unstaged = DB::selectOne(
            "SELECT {$expr} AS picked FROM (SELECT 'live' AS reach_labels, NULL AS reach_labels_next, {$nullFp} AS reach_labels_next_fp) AS t"
        );
        $this->assertSame('live', $unstaged->picked);
    }
}
