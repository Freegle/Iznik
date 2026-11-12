<?php

namespace Tests\Unit\Services\Automod;

use App\Services\AutoApproveCleanService;
use App\Services\Automod\AutomodMode;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which of the two rollout lists a community is in, and how that set is expressed as SQL.
 * Shadow always wins: the global approve switch must never silently promote a community
 * that is still being watched.
 */
class AutomodModeTest extends TestCase
{
    public function test_the_two_lists_name_the_two_modes(): void
    {
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '7, 8',
            'freegle.automod.shadow_group_ids' => '5',
        ]);

        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(7));
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(8));
        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(5));
        $this->assertNull(AutomodMode::for(9));
        $this->assertTrue(AutomodMode::isApproveMode(7));
        $this->assertFalse(AutomodMode::isApproveMode(5));

        $eligible = AutomodMode::eligibleGroupIds();
        sort($eligible);
        $this->assertSame([5, 7, 8], $eligible);
    }

    public function test_shadow_wins_when_approve_is_on_everywhere(): void
    {
        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
            'freegle.automod.shadow_group_ids' => '5',
        ]);

        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(5));
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(6));
        $this->assertFalse(AutomodMode::isApproveMode(5));
        $this->assertTrue(AutomodMode::isApproveMode(6));
        $this->assertNull(AutomodMode::eligibleGroupIds(), 'every community runs the chart in one mode or the other');
    }

    public function test_shadow_wins_over_the_approve_list_too(): void
    {
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '5,7',
            'freegle.automod.shadow_group_ids' => '5',
        ]);

        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(5));
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(7));
    }

    /** The one SQL form of "an approve-mode community", used by every candidate query. */
    public function test_the_approve_scope_as_sql(): void
    {
        $idsMatching = function (): array {
            $rows = DB::select(
                'SELECT id FROM (SELECT 7 AS id UNION SELECT 8 UNION SELECT 9) t WHERE '
                . AutomodMode::approveModeSql('t.id') . ' ORDER BY id'
            );

            return array_map(fn ($r) => (int) $r->id, $rows);
        };

        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => '7,8', 'freegle.automod.shadow_group_ids' => '7']);
        $this->assertSame([8], $idsMatching());

        config(['freegle.autoapprove.enabled' => true, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '7']);
        $this->assertSame([8, 9], $idsMatching());

        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '7']);
        $this->assertSame([], $idsMatching(), 'nothing is in approve mode while both switches are off');

        config(['freegle.autoapprove.enabled' => true, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '']);
        $this->assertSame([7, 8, 9], $idsMatching());
    }

    /**
     * PHP's own cast reads "off" and "no" as true, which switched approve mode ON everywhere
     * while the Go API (exactly "true" or "1") showed nothing. One rule now: EnvSetting::flag.
     */
    public function test_a_word_that_only_looks_like_a_value_does_not_switch_approve_on(): void
    {
        foreach (['off', 'no', 'disabled', 'yes', 'on', 'false', '0', ''] as $word) {
            config(['freegle.autoapprove.enabled' => $word, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '']);

            $this->assertSame([], (new AutoApproveCleanService())->enabledGroupIds(), "'{$word}' must leave approve mode dark");
            $this->assertNull(AutomodMode::for(7), "'{$word}' must not put a community in approve mode");
        }

        foreach (['true', 'TRUE', 'True', '1', ' 1 '] as $word) {
            config(['freegle.autoapprove.enabled' => $word, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '']);

            $this->assertNull((new AutoApproveCleanService())->enabledGroupIds(), "'{$word}' switches approve mode on everywhere");
            $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(7));
        }
    }

    public function test_the_group_lists_read_only_plain_digit_tokens(): void
    {
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '12abc, 13 ,1e3,+14,,0',
            'freegle.automod.shadow_group_ids' => '5x,6, 7.5',
        ]);

        $this->assertSame([13], (new AutoApproveCleanService())->enabledGroupIds());
        $this->assertSame([6], AutomodMode::shadowGroupIds());
        $this->assertNull(AutomodMode::for(12), '12abc is not community 12');
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(13));
        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(6));
    }
}
