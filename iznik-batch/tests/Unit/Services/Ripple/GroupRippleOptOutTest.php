<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\GroupRippleOptOut;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupRippleOptOutTest extends TestCase
{
    private function optOut(): GroupRippleOptOut
    {
        return new GroupRippleOptOut();
    }

    /** Give a group a settings blob and return its id. */
    private function groupWithSettings(?string $settings): int
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['settings' => $settings]);

        return (int) $group->id;
    }

    /**
     * The whole point of the default: a community that has never been given the setting
     * ripples in both directions, so deploying this changes nothing on its own.
     */
    public function test_group_with_no_rippling_setting_is_not_excluded(): void
    {
        $plain = $this->groupWithSettings(null);
        $other = $this->groupWithSettings('{"showjoin": 5}');

        $optOut = $this->optOut();

        foreach ([GroupRippleOptOut::DIRECTION_OUT, GroupRippleOptOut::DIRECTION_IN] as $direction) {
            $this->assertTrue($optOut->permits($plain, $direction), "settings NULL still ripples $direction");
            $this->assertTrue($optOut->permits($other, $direction), "unrelated settings still ripple $direction");
        }
    }

    /**
     * The two directions are independent - switching ripple-out off must not stop the
     * community being a crosspost target, and vice versa.
     */
    public function test_directions_are_independent(): void
    {
        $outOff = $this->groupWithSettings('{"rippling": {"out": 0}}');
        $inOff = $this->groupWithSettings('{"rippling": {"in": 0}}');

        $optOut = $this->optOut();

        $this->assertFalse($optOut->permits($outOff, GroupRippleOptOut::DIRECTION_OUT));
        $this->assertTrue($optOut->permits($outOff, GroupRippleOptOut::DIRECTION_IN), 'out=0 leaves ripple-in alone');

        $this->assertFalse($optOut->permits($inOff, GroupRippleOptOut::DIRECTION_IN));
        $this->assertTrue($optOut->permits($inOff, GroupRippleOptOut::DIRECTION_OUT), 'in=0 leaves ripple-out alone');
    }

    /**
     * ModTools-style integer 0, a real JSON boolean and the string spellings all mean off.
     * The command writes 0; a hand-edited blob or another writer may use any of these.
     */
    public function test_every_false_spelling_counts_as_off(): void
    {
        foreach (['0', 'false', '"0"', '"false"'] as $spelling) {
            $id = $this->groupWithSettings('{"rippling": {"out": ' . $spelling . '}}');
            $this->assertFalse(
                $this->optOut()->permits($id, GroupRippleOptOut::DIRECTION_OUT),
                "out: $spelling is an opt-out"
            );
        }
    }

    /**
     * Anything that is not an explicit false leaves rippling ON, including a malformed value.
     * The fail-safe direction matters: wrongly-off silently stops a real community rippling
     * and nobody would notice, whereas wrongly-on is visible and a mod can reject the copy.
     */
    public function test_unrecognised_and_malformed_values_leave_rippling_on(): void
    {
        $cases = [
            'explicit 1' => '{"rippling": {"out": 1}}',
            'explicit true' => '{"rippling": {"out": true}}',
            'json null' => '{"rippling": {"out": null}}',
            'empty string' => '{"rippling": {"out": ""}}',
            'typo value' => '{"rippling": {"out": "nope"}}',
            'rippling not an object' => '{"rippling": 0}',
            'rippling a string' => '{"rippling": "off"}',
            'unparseable settings' => '{rippling not json',
            'wrong direction key' => '{"rippling": {"outward": 0}}',
        ];

        foreach ($cases as $label => $settings) {
            $id = $this->groupWithSettings($settings);
            $this->assertTrue(
                $this->optOut()->permits($id, GroupRippleOptOut::DIRECTION_OUT),
                "$label must leave rippling on"
            );
        }
    }

    /** The id list is what gets spliced into SQL, so it must be plain ints. */
    public function test_excluded_ids_are_ints(): void
    {
        $id = $this->groupWithSettings('{"rippling": {"out": 0, "in": 0}}');

        $optOut = $this->optOut();
        foreach ([GroupRippleOptOut::DIRECTION_OUT, GroupRippleOptOut::DIRECTION_IN] as $direction) {
            $ids = $optOut->excludedGroupIds($direction);
            $this->assertContains($id, $ids, "the group appears in the $direction exclusion list");
            foreach ($ids as $one) {
                $this->assertIsInt($one, 'exclusion ids are ints, so they cannot inject');
            }
        }
    }

    /** Memoized for the run, so a batch pass reads the groups table once. */
    public function test_result_is_memoized_until_forgotten(): void
    {
        $id = $this->groupWithSettings(null);
        $optOut = $this->optOut();
        $this->assertTrue($optOut->permits($id, GroupRippleOptOut::DIRECTION_OUT));

        DB::table('groups')->where('id', $id)->update(['settings' => '{"rippling": {"out": 0}}']);
        $this->assertTrue($optOut->permits($id, GroupRippleOptOut::DIRECTION_OUT), 'memoized within the run');

        $optOut->forget();
        $this->assertFalse($optOut->permits($id, GroupRippleOptOut::DIRECTION_OUT), 'forget() re-reads');
    }
}
