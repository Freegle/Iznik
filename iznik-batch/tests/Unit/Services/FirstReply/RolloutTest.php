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

        // Message ids are a dense auto-increment, so mod 100 is exactly uniform
        // over any whole number of hundreds - this is not a statistical estimate.
        $this->assertSame(1000, $in);
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
