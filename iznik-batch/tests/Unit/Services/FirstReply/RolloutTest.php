<?php

namespace Tests\Unit\Services\FirstReply;

use App\Services\FirstReply\Rollout;
use Tests\TestCase;

/**
 * The trial split. Worth testing carefully rather than eyeballing: a rollout that
 * silently includes everything, or that puts one post in different arms on
 * different paths, produces numbers that look fine and mean nothing.
 */
class RolloutTest extends TestCase
{
    public function test_defaults_to_nobody_so_switching_a_lever_on_is_not_a_full_rollout(): void
    {
        config(['freegle.firstreply.rollout_percent' => null]);

        $this->assertSame(0, Rollout::percent());
        $this->assertFalse(Rollout::includes(1));
        $this->assertFalse(Rollout::includes(1234567));
    }

    public function test_at_a_hundred_percent_every_post_is_in(): void
    {
        config(['freegle.firstreply.rollout_percent' => 100]);

        foreach ([1, 42, 99, 100, 123456789] as $msgid) {
            $this->assertTrue(Rollout::includes($msgid), "msgid $msgid should be in");
        }
    }

    public function test_selects_roughly_the_configured_share(): void
    {
        config(['freegle.firstreply.rollout_percent' => 10]);

        $in = 0;
        for ($msgid = 1; $msgid <= 10000; $msgid++) {
            if (Rollout::includes($msgid)) {
                $in++;
            }
        }

        // CRC32 bucketing is uniform but not arithmetic: over 10,000 sequential
        // ids a 10% rollout selects close to 1,000, not exactly 1,000 the way
        // msgid % 100 did. Allow generous statistical slack - what this test
        // guards is the SHARE being right, not the exact draw. (For 10,000
        // fair 10% trials, ±90 is over three standard deviations.)
        $this->assertGreaterThan(910, $in);
        $this->assertLessThan(1090, $in);
    }

    public function test_bucketing_is_the_shared_crc32_and_matches_the_database(): void
    {
        // Pinned cross-language values: PHP crc32, Go crc32.ChecksumIEEE
        // (TestRolloutBucketPinnedCrossLanguage) and MySQL CRC32() (the
        // metrics arm split) must all place a post in the same bucket, or the
        // three doors run different trials. A hash rather than msgid % 100
        // because ids are minted under Galera's auto_increment_increment
        // stride, and a raw modulus is only uniform while the stride stays
        // coprime with the bucket count.
        $this->assertSame(69, crc32('1|firstreply') % 100);
        $this->assertSame(47, crc32('121254506|firstreply') % 100);
        $this->assertSame(92, crc32('121346222|firstreply') % 100);

        config(['freegle.firstreply.rollout_percent' => 48]);
        $this->assertTrue(Rollout::includes(121254506), 'bucket 47 is inside a 48% rollout');
        config(['freegle.firstreply.rollout_percent' => 47]);
        $this->assertFalse(Rollout::includes(121254506), 'bucket 47 is outside a 47% rollout');

        // And the database renders the identical bucket, so the SQL filters
        // and the PHP checks can never disagree about a post's arm.
        $db = \DB::selectOne('SELECT CRC32(CONCAT(121254506, "|firstreply")) % 100 AS b');
        $this->assertSame(47, (int) $db->b);
    }

    public function test_a_post_never_changes_arm(): void
    {
        // The whole comparison rests on this: if a post could move between arms,
        // a before/after on it would be comparing two different treatments.
        config(['freegle.firstreply.rollout_percent' => 25]);

        $first = Rollout::includes(4242);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, Rollout::includes(4242));
        }
    }

    public function test_raising_the_percentage_only_ever_adds_posts(): void
    {
        // So a trial can be widened without shuffling anyone out of the arm they
        // were already being measured in.
        $inAtTen = [];
        config(['freegle.firstreply.rollout_percent' => 10]);
        for ($msgid = 1; $msgid <= 500; $msgid++) {
            if (Rollout::includes($msgid)) {
                $inAtTen[] = $msgid;
            }
        }

        config(['freegle.firstreply.rollout_percent' => 40]);
        foreach ($inAtTen as $msgid) {
            $this->assertTrue(Rollout::includes($msgid), "msgid $msgid dropped out when widening");
        }
    }

    public function test_out_of_range_values_are_clamped_rather_than_trusted(): void
    {
        config(['freegle.firstreply.rollout_percent' => 150]);
        $this->assertSame(100, Rollout::percent());

        config(['freegle.firstreply.rollout_percent' => -5]);
        $this->assertSame(0, Rollout::percent());
        $this->assertFalse(Rollout::includes(7));
    }

    public function test_sql_filter_matches_the_php_decision(): void
    {
        // The bulk queries filter in SQL and the passthrough decides in PHP. They
        // have to agree, or a post would be scouted but not passed through.
        config(['freegle.firstreply.rollout_percent' => 30]);

        $sql = Rollout::sqlFilter('ms.msgid');
        $this->assertStringContainsString('% 100) < 30', $sql);

        config(['freegle.firstreply.rollout_percent' => 100]);
        $this->assertSame('', Rollout::sqlFilter('ms.msgid'), 'full rollout adds nothing to the query');

        config(['freegle.firstreply.rollout_percent' => 0]);
        $this->assertStringContainsString('1 = 0', Rollout::sqlFilter('ms.msgid'), 'zero selects nothing, not everything');
    }

    public function test_describe_explains_a_quiet_run(): void
    {
        config(['freegle.firstreply.rollout_percent' => 0]);
        $this->assertStringContainsString('FIRSTREPLY_ROLLOUT_PERCENT', Rollout::describe());

        config(['freegle.firstreply.rollout_percent' => 15]);
        $this->assertStringContainsString('15%', Rollout::describe());
    }
}
