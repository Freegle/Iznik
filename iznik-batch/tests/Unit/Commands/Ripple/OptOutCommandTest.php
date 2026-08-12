<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\GroupRippleOptOut;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OptOutCommandTest extends TestCase
{
    /**
     * The stored rippling object for a group, decoded and key-sorted.
     *
     * Sorted because groups.settings is longtext: a PHP write keeps its key order verbatim,
     * but anything that goes through a MySQL JSON function (as the seeding migration does)
     * comes back with the keys normalised. Assertions here should not care which wrote it.
     */
    private function rippling(int $groupid): ?array
    {
        $settings = json_decode((string) DB::table('groups')->where('id', $groupid)->value('settings'), true);
        if (!is_array($settings) || !isset($settings['rippling']) || !is_array($settings['rippling'])) {
            return null;
        }
        $rippling = $settings['rippling'];
        ksort($rippling);

        return $rippling;
    }

    public function test_switches_both_directions_off_by_name(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort]])
            ->expectsOutputToContain('Rippling OFF')
            ->assertExitCode(0);

        $this->assertSame(['in' => 0, 'out' => 0], $this->rippling((int) $group->id));
        $optOut = new GroupRippleOptOut();
        $this->assertFalse($optOut->permits((int) $group->id, GroupRippleOptOut::DIRECTION_OUT));
        $this->assertFalse($optOut->permits((int) $group->id, GroupRippleOptOut::DIRECTION_IN));
    }

    public function test_accepts_a_group_id_as_well_as_a_nameshort(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [(string) $group->id]])->assertExitCode(0);

        $this->assertSame(['in' => 0, 'out' => 0], $this->rippling((int) $group->id));
    }

    /** One direction at a time, so a community can stop its own posts travelling but still receive. */
    public function test_direction_option_changes_only_that_direction(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort], '--direction' => 'out'])
            ->assertExitCode(0);

        $this->assertSame(['out' => 0], $this->rippling((int) $group->id));
    }

    /**
     * Absent means on, so switching back on REMOVES the key rather than storing a 1 - and when
     * both directions are back on the rippling object goes entirely, which drops the community
     * out of the resolver's prefilter.
     */
    public function test_on_removes_the_setting_rather_than_storing_a_truthy_value(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['settings' => '{"rippling": {"out": 0, "in": 0}}']);

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort], '--direction' => 'in', '--on' => true])
            ->expectsOutputToContain('Rippling ON')
            ->assertExitCode(0);
        $this->assertSame(['out' => 0], $this->rippling((int) $group->id), 'only the in key is removed');

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort], '--on' => true])->assertExitCode(0);
        $this->assertNull($this->rippling((int) $group->id), 'the whole rippling object goes when nothing is off');
    }

    /** Other settings must survive: the command merges rather than replacing the blob. */
    public function test_preserves_other_settings(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['settings' => '{"showjoin": 5, "closed": true}']);

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort]])->assertExitCode(0);

        $settings = json_decode((string) DB::table('groups')->where('id', $group->id)->value('settings'), true);
        $this->assertSame(5, $settings['showjoin']);
        $this->assertTrue($settings['closed']);
        $this->assertSame(['in' => 0, 'out' => 0], $this->rippling((int) $group->id));
    }

    /**
     * MySQL's JSON_SET cannot create `$.rippling.out` when `$.rippling` is missing, which is
     * exactly the state of every community that has never had this set - so the command does the
     * JSON in PHP. This is the regression guard for that.
     */
    public function test_writes_the_setting_when_settings_is_empty_or_unparseable(): void
    {
        foreach ([null, '', '{}', 'not json at all'] as $starting) {
            $group = $this->createTestGroup();
            DB::table('groups')->where('id', $group->id)->update(['settings' => $starting]);

            $this->artisan('ripple:opt-out', ['group' => [$group->nameshort], '--direction' => 'out'])
                ->assertExitCode(0);

            $this->assertSame(
                ['out' => 0],
                $this->rippling((int) $group->id),
                'the setting lands whatever settings started as: ' . var_export($starting, true)
            );
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort], '--dry-run' => true])
            ->expectsOutputToContain('Would switch rippling OFF')
            ->assertExitCode(0);

        $this->assertNull($this->rippling((int) $group->id));
    }

    public function test_rejects_an_unknown_community(): void
    {
        $this->artisan('ripple:opt-out', ['group' => ['NoSuchCommunity_zzz']])
            ->expectsOutputToContain('No community matches')
            ->assertExitCode(1);
    }

    public function test_rejects_a_bad_direction(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [$group->nameshort], '--direction' => 'sideways'])
            ->expectsOutputToContain('--direction must be one of')
            ->assertExitCode(2);

        $this->assertNull($this->rippling((int) $group->id), 'a bad direction writes nothing');
    }

    public function test_requires_a_community_or_list(): void
    {
        $this->artisan('ripple:opt-out')
            ->expectsOutputToContain('Name at least one community')
            ->assertExitCode(2);
    }

    public function test_list_reports_the_current_opt_outs(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['settings' => '{"rippling": {"out": 0}}']);

        $this->artisan('ripple:opt-out', ['--list' => true])
            ->expectsOutputToContain($group->nameshort)
            ->assertExitCode(0);
    }

    /** Several communities in one go, since they are set as a group. */
    public function test_handles_several_communities_at_once(): void
    {
        $a = $this->createTestGroup();
        $b = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [$a->nameshort, $b->nameshort]])->assertExitCode(0);

        $this->assertSame(['in' => 0, 'out' => 0], $this->rippling((int) $a->id));
        $this->assertSame(['in' => 0, 'out' => 0], $this->rippling((int) $b->id));
    }

    /** An unknown name in the list stops the whole run, so a typo cannot half-apply. */
    public function test_an_unknown_name_aborts_before_writing_anything(): void
    {
        $good = $this->createTestGroup();

        $this->artisan('ripple:opt-out', ['group' => [$good->nameshort, 'NoSuchCommunity_zzz']])
            ->assertExitCode(1);

        $this->assertNull($this->rippling((int) $good->id), 'nothing is written when any name is unknown');
    }
}
