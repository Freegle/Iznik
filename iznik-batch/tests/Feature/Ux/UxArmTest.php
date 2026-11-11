<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HidesExposureTable;
use Tests\TestCase;

/**
 * Server code's arm: UxArm::for, ::pick and ::expose. The go twin is ux.ExperimentArm and
 * ux.RecordExposure (iznik-server-go/test/ux_serverarm_test.go), and both read the same rules.
 *
 * Members on "digest-subject" with arms control [0,49] and items [50,99]:
 *   member    1      2      3      5     12345  999999
 *   bucket   87     77     72     88     29     68      without a salt
 *   bucket   88     97      5      2     11     58      with the salt r2
 */
class UxArmTest extends TestCase
{
    use HidesExposureTable;

    private const NAME = 'digest-subject';

    private const ARMS = ['control' => [0, 49], 'items' => [50, 99]];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        UxArm::flush();
        config()->set('ux_switches', [['name' => self::NAME]]);
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY])->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        UxArm::flush();
        parent::tearDown();
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /** A running entry that ends in an hour. */
    private function running(array $over = []): array
    {
        return $over + ['arms' => self::ARMS, 'until' => $this->now() + 3600];
    }

    private function setConfig(string $key, string $value): void
    {
        DB::table('config')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
    }

    /** Publish the entries as the engine would, and switch server arms on. */
    private function publish(array $entries, ?string $switch = '1'): void
    {
        $this->setConfig(UxExperimentService::CONFIG_KEY, json_encode($entries));
        if ($switch === null) {
            DB::table('config')->where('key', UxArm::SWITCH_KEY)->delete();
        } else {
            $this->setConfig(UxArm::SWITCH_KEY, $switch);
        }
        UxArm::flush();
    }

    private function publishRaw(string $json): void
    {
        $this->setConfig(UxExperimentService::CONFIG_KEY, $json);
        $this->setConfig(UxArm::SWITCH_KEY, '1');
        UxArm::flush();
    }

    // ---------------------------------------------------------------- the arm

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function members(): array
    {
        return [
            'member 1, bucket 87' => [1, '', 'items'],
            'member 2, bucket 77' => [2, '', 'items'],
            'member 3, bucket 72' => [3, '', 'items'],
            'member 5, bucket 88' => [5, '', 'items'],
            'member 12345, bucket 29' => [12345, '', 'control'],
            'member 999999, bucket 68' => [999999, '', 'items'],
            'member 1 with r2, bucket 88' => [1, 'r2', 'items'],
            'member 2 with r2, bucket 97' => [2, 'r2', 'items'],
            'member 3 with r2, bucket 5' => [3, 'r2', 'control'],
            'member 5 with r2, bucket 2' => [5, 'r2', 'control'],
            'member 12345 with r2, bucket 11' => [12345, 'r2', 'control'],
            'member 999999 with r2, bucket 58' => [999999, 'r2', 'items'],
        ];
    }

    #[DataProvider('members')]
    public function test_a_running_entry_gives_the_arm_the_shared_bucket_picks(int $userId, string $salt, string $want): void
    {
        $this->publish([self::NAME => $this->running($salt === '' ? [] : ['salt' => $salt])]);

        $this->assertSame($want, UxArm::for($userId, self::NAME));
    }

    public function test_an_entry_that_has_not_run_out_serves_until_the_second_it_ends(): void
    {
        $this->publish([self::NAME => $this->running(['until' => $this->now() + 1])]);
        $this->assertSame('items', UxArm::for(3, self::NAME));

        $this->publish([self::NAME => $this->running(['until' => $this->now()])]);
        $this->assertSame('', UxArm::for(3, self::NAME), 'until is the end, not the last second');

        $this->publish([self::NAME => $this->running(['until' => $this->now() - 1])]);
        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_an_entry_with_no_until_is_control_when_running(): void
    {
        $entry = $this->running();
        unset($entry['until']);
        $this->publish([self::NAME => $entry]);

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_an_until_more_than_fifteen_days_ahead_is_not_believed(): void
    {
        $this->publish([self::NAME => $this->running(['until' => $this->now() + 15 * 86400])]);
        $this->assertSame('items', UxArm::for(3, self::NAME), 'exactly fifteen days is the most');

        $this->publish([self::NAME => $this->running(['until' => $this->now() + 15 * 86400 + 1])]);
        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_an_adopted_entry_ignores_until(): void
    {
        $adopted = ['arms' => ['items' => [0, 89], 'control' => [90, 99]], 'adopted' => true];

        $this->publish([self::NAME => $adopted + ['until' => $this->now() - 99999]]);
        $this->assertSame('items', UxArm::for(3, self::NAME), 'a past until');

        $this->publish([self::NAME => $adopted]);
        $this->assertSame('items', UxArm::for(3, self::NAME), 'no until');

        $this->publish([self::NAME => $adopted + ['until' => $this->now() + 40 * 86400]]);
        $this->assertSame('items', UxArm::for(3, self::NAME), 'an until far ahead');
    }

    public function test_adoption_keeps_the_salt_or_it_would_move_everyone(): void
    {
        // Member 2: bucket 77 without a salt (adopted arm), 97 with r2 (the holdout).
        $arms = ['items' => [0, 89], 'control' => [90, 99]];
        $this->publish([self::NAME => ['arms' => $arms, 'adopted' => true, 'salt' => 'r2']]);
        $this->assertSame('control', UxArm::for(2, self::NAME));

        $this->publish([self::NAME => ['arms' => $arms, 'adopted' => true]]);
        $this->assertSame('items', UxArm::for(2, self::NAME));
    }

    public function test_only_boolean_true_adopts(): void
    {
        $this->publishRaw('{"digest-subject":{"arms":{"items":[0,99]},"adopted":"true","until":'.($this->now() - 5).'}}');

        $this->assertSame('', UxArm::for(3, self::NAME), '"true" is a string, so this is a lapsed running entry');
    }

    public function test_the_first_arm_that_holds_the_bucket_wins_and_an_empty_range_holds_nobody(): void
    {
        $this->publish([self::NAME => $this->running(['arms' => ['empty' => [99, 98], 'first' => [0, 99], 'second' => [0, 99]]])]);

        $this->assertSame('first', UxArm::for(3, self::NAME));
    }

    public function test_an_entry_whose_only_arms_are_empty_is_control(): void
    {
        $this->publish([self::NAME => $this->running(['arms' => ['b' => [99, 98]]])]);

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_a_bucket_outside_every_range_is_control(): void
    {
        $this->publish([self::NAME => $this->running(['arms' => ['items' => [0, 10]]])]);

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_whole_numbers_written_with_a_fraction_of_zero_count(): void
    {
        $this->publishRaw('{"digest-subject":{"arms":{"control":[0.0,49.0],"items":[50.0,99.0]},"until":'.($this->now() + 3600).'}}');

        $this->assertSame('items', UxArm::for(3, self::NAME));
    }

    // ---------------------------------------------------------------- what is not understood is control

    /** @return array<string, array{0: string}> */
    public static function malformedEntries(): array
    {
        $until = '9999999999';

        return [
            'no arms' => ['{"digest-subject":{"until":UNTIL}}'],
            'arms null' => ['{"digest-subject":{"arms":null,"until":UNTIL}}'],
            'arms a list' => ['{"digest-subject":{"arms":[[0,49]],"until":UNTIL}}'],
            'arms empty' => ['{"digest-subject":{"arms":{},"until":UNTIL}}'],
            'more than eight arms' => ['{"digest-subject":{"arms":{"a":[0,1],"b":[2,3],"c":[4,5],"d":[6,7],"e":[8,9],"f":[10,11],"g":[12,13],"h":[14,15],"i":[16,17]},"until":UNTIL}}'],
            'an arm name with a space' => ['{"digest-subject":{"arms":{"bad arm":[0,99]},"until":UNTIL}}'],
            'an arm name of 33 characters' => ['{"digest-subject":{"arms":{"'.str_repeat('a', 33).'":[0,99]},"until":UNTIL}}'],
            'a range above 99' => ['{"digest-subject":{"arms":{"b":[0,100]},"until":UNTIL}}'],
            'a range below 0' => ['{"digest-subject":{"arms":{"b":[-1,99]},"until":UNTIL}}'],
            'a range of one number' => ['{"digest-subject":{"arms":{"b":[0]},"until":UNTIL}}'],
            'a range of three numbers' => ['{"digest-subject":{"arms":{"b":[0,1,2]},"until":UNTIL}}'],
            'a range of strings' => ['{"digest-subject":{"arms":{"b":["0","99"]},"until":UNTIL}}'],
            'a range with a fraction' => ['{"digest-subject":{"arms":{"b":[0.5,99]},"until":UNTIL}}'],
            'a range that is an object' => ['{"digest-subject":{"arms":{"b":{"from":0,"to":99}},"until":UNTIL}}'],
            'the old flat shape' => ['{"digest-subject":{"control":[0,49],"b":[50,99]}}'],
            'an entry that is a string' => ['{"digest-subject":"x"}'],
            'an entry that is a list' => ['{"digest-subject":[1,2]}'],
            'an entry that is null' => ['{"digest-subject":null}'],
            'the whole row a list' => ['[{"digest-subject":{"arms":{"b":[0,99]},"until":UNTIL}}]'],
            'the whole row not JSON' => ['{not json'],
            'a row that is empty' => [''],
            'one bad arm among good ones' => ['{"digest-subject":{"arms":{"control":[0,49],"bad arm":[50,99]},"until":UNTIL}}'],
        ];
    }

    #[DataProvider('malformedEntries')]
    public function test_an_entry_that_is_not_understood_is_control(string $json): void
    {
        $this->publishRaw(str_replace('UNTIL', (string) ($this->now() + 3600), $json));

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    /** @return array<string, array{0: string}> */
    public static function malformedSalts(): array
    {
        return [
            'a space' => ['"bad salt"'],
            '17 characters' => ['"abcdefghijklmnopq"'],
            'an empty string' => ['""'],
            'a number' => ['5'],
            'true' => ['true'],
            'a list' => ['["r2"]'],
            'an object' => ['{"r":2}'],
            'a symbol' => ['"r2!"'],
            'a newline at the end' => ['"r2\n"'],
        ];
    }

    #[DataProvider('malformedSalts')]
    public function test_a_salt_that_is_present_and_malformed_drops_the_entry(string $salt): void
    {
        $until = $this->now() + 3600;
        $this->publishRaw('{"digest-subject":{"arms":{"control":[0,49],"items":[50,99]},"until":'.$until.',"salt":'.$salt.'}}');
        $this->assertSame('', UxArm::for(3, self::NAME), 'a running entry');

        $this->publishRaw('{"digest-subject":{"arms":{"items":[0,99]},"adopted":true,"salt":'.$salt.'}}');
        $this->assertSame('', UxArm::for(3, self::NAME), 'an adopted entry');
    }

    public function test_a_null_salt_is_no_salt_and_sixteen_characters_is_the_most(): void
    {
        $until = $this->now() + 3600;
        $this->publishRaw('{"digest-subject":{"arms":{"control":[0,49],"items":[50,99]},"until":'.$until.',"salt":null}}');
        $this->assertSame('items', UxArm::for(3, self::NAME), 'null is the same as no salt');

        $this->publishRaw('{"digest-subject":{"arms":{"control":[0,49],"items":[50,99]},"until":'.$until.',"salt":"abcdefghijklmnop"}}');
        $this->assertNotSame('', UxArm::for(3, self::NAME));
    }

    public function test_an_until_that_is_a_string_is_not_a_time(): void
    {
        $this->publishRaw('{"digest-subject":{"arms":{"items":[0,99]},"until":"'.($this->now() + 3600).'"}}');

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_a_name_that_is_not_safe_is_never_served(): void
    {
        config()->set('ux_switches', [['name' => 'bad name!'], ['name' => 'constructor'], ['name' => self::NAME]]);
        $entry = $this->running(['arms' => ['items' => [0, 99]]]);
        $this->publish(['bad name!' => $entry, 'constructor' => $entry, self::NAME => $entry]);

        $this->assertSame('', UxArm::for(3, 'bad name!'));
        $this->assertSame('', UxArm::for(3, 'constructor'));
        $this->assertSame('items', UxArm::for(3, self::NAME));
    }

    // ---------------------------------------------------------------- the switch, the list and the copy

    /** @return array<string, array{0: ?string}> */
    public static function switchesThatAreOff(): array
    {
        return [
            'no row' => [null],
            '0' => ['0'],
            'true' => ['true'],
            'on' => ['on'],
            'an empty value' => [''],
            'yes' => ['yes'],
            '01' => ['01'],
            '2' => ['2'],
        ];
    }

    #[DataProvider('switchesThatAreOff')]
    public function test_only_one_switches_server_arms_on(?string $switch): void
    {
        $this->publish([self::NAME => $this->running()], $switch);

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_one_with_spaces_round_it_is_still_one(): void
    {
        $this->publish([self::NAME => $this->running()], " 1\n");

        $this->assertSame('items', UxArm::for(3, self::NAME));
    }

    public function test_a_name_this_code_has_no_switch_for_is_control_whatever_the_config_says(): void
    {
        $this->publish(['unlisted-test' => $this->running(), self::NAME => $this->running()]);

        $this->assertSame('', UxArm::for(3, 'unlisted-test'));
        $this->assertSame('items', UxArm::for(3, self::NAME));
    }

    public function test_a_member_with_no_id_is_control(): void
    {
        $this->publish([self::NAME => $this->running(['arms' => ['items' => [0, 99]]])]);

        $this->assertSame('', UxArm::for(0, self::NAME));
        $this->assertSame('', UxArm::for(-4, self::NAME));
    }

    public function test_the_config_is_kept_for_a_minute_and_flush_forgets_it(): void
    {
        $this->publish([self::NAME => $this->running()]);
        $this->assertSame('items', UxArm::for(3, self::NAME));

        // The switch is turned off. For 59 seconds this process still holds the copy it read.
        $this->setConfig(UxArm::SWITCH_KEY, '0');
        Carbon::setTestNow('2026-10-20 10:00:59');
        $this->assertSame('items', UxArm::for(3, self::NAME));

        // At a minute it reads again and acts as control.
        Carbon::setTestNow('2026-10-20 10:01:00');
        $this->assertSame('', UxArm::for(3, self::NAME));

        // flush() makes the next call read straight away.
        $this->setConfig(UxArm::SWITCH_KEY, '1');
        $this->assertSame('', UxArm::for(3, self::NAME), 'still the copy');
        UxArm::flush();
        $this->assertSame('items', UxArm::for(3, self::NAME));
    }

    public function test_a_clock_that_goes_back_reads_again(): void
    {
        $this->publish([self::NAME => $this->running()]);
        $this->assertSame('items', UxArm::for(3, self::NAME));

        $this->setConfig(UxArm::SWITCH_KEY, '0');
        Carbon::setTestNow('2026-10-20 09:59:30');

        $this->assertSame('', UxArm::for(3, self::NAME));
    }

    public function test_an_experiment_that_is_not_listed_does_not_ask_the_database_at_all(): void
    {
        $this->publish([self::NAME => $this->running()]);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        UxArm::for(3, 'unlisted-test');

        $this->assertSame(0, $queries);
    }

    public function test_both_config_rows_are_read_in_one_query_and_then_not_again_for_a_minute(): void
    {
        $this->publish([self::NAME => $this->running()]);
        $reads = [];
        DB::listen(function ($q) use (&$reads) {
            if (str_contains($q->sql, '`config`')) {
                $reads[] = $q->sql;
            }
        });

        UxArm::for(3, self::NAME);
        UxArm::for(5, self::NAME);
        UxArm::for(1, self::NAME);

        $this->assertCount(1, $reads);
        $this->assertStringContainsString('`key` in (?, ?)', $reads[0]);
    }

    public function test_a_read_error_is_control_and_logged_and_is_not_asked_again_for_a_minute(): void
    {
        $this->publish([self::NAME => $this->running()]);
        Log::spy();

        // A table prefix points the read at a table that does not exist.
        DB::connection()->setTablePrefix('zz_');
        try {
            $this->assertSame('', UxArm::for(3, self::NAME));
        } finally {
            DB::connection()->setTablePrefix('');
        }

        Log::shouldHaveReceived('warning')->once();

        // The database is back, but the failure is remembered for the minute.
        $this->assertSame('', UxArm::for(3, self::NAME));
        Carbon::setTestNow('2026-10-20 10:01:00');
        $this->assertSame('items', UxArm::for(3, self::NAME));
    }

    // ---------------------------------------------------------------- pick

    public function test_pick_gives_the_value_for_the_arm_and_control_for_anything_else(): void
    {
        $by = ['control' => 'Your daily digest', 'items' => 'Items near you'];

        $this->assertSame('Items near you', UxArm::pick('items', $by));
        $this->assertSame('Your daily digest', UxArm::pick('control', $by));
        $this->assertSame('Your daily digest', UxArm::pick('', $by));
        $this->assertSame('Your daily digest', UxArm::pick(null, $by));
        $this->assertSame('Your daily digest', UxArm::pick('an-arm-the-caller-has-no-value-for', $by));
    }

    public function test_pick_without_a_control_value_is_a_mistake_in_the_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UxArm::pick('items', ['items' => 'Items near you']);
    }

    public function test_the_arm_is_a_pure_function_so_a_rebuilt_mail_gets_the_same_one(): void
    {
        $this->publish([self::NAME => $this->running()]);

        $first = array_map(fn ($id) => UxArm::for($id, self::NAME), range(1, 40));
        UxArm::flush();
        $second = array_map(fn ($id) => UxArm::for($id, self::NAME), range(1, 40));

        $this->assertSame($first, $second);
        $this->assertContains('items', $first);
        $this->assertContains('control', $first);
    }

    // ---------------------------------------------------------------- expose

    private function exposures(string $experiment = self::NAME): array
    {
        return DB::table('ux_experiment_exposures')->where('experiment', $experiment)->orderBy('id')->get()->all();
    }

    public function test_expose_writes_one_row_with_the_utc_time_and_ids_only(): void
    {
        UxArm::expose(7, self::NAME, 'items', 'promise', 31, 'in', ['msgid' => 555, 'hours' => 20]);

        $rows = $this->exposures();
        $this->assertCount(1, $rows);
        $this->assertSame(self::NAME, $rows[0]->experiment);
        $this->assertSame('items', $rows[0]->arm);
        $this->assertSame(7, (int) $rows[0]->userid);
        $this->assertSame('promise', $rows[0]->subject_type);
        $this->assertSame(31, (int) $rows[0]->subject_id);
        $this->assertSame('in', $rows[0]->stratum);
        $this->assertSame('2026-10-20 10:00:00', $rows[0]->exposed_at);
        $this->assertEquals(['msgid' => 555, 'hours' => 20], json_decode($rows[0]->detail, true));
    }

    public function test_expose_with_no_stratum_or_detail_stores_nulls(): void
    {
        UxArm::expose(7, self::NAME, 'control', 'email', 31);

        $row = $this->exposures()[0];
        $this->assertNull($row->stratum);
        $this->assertNull($row->detail);
        $this->assertSame('control', $row->arm, 'control is written too');
    }

    public function test_expose_twice_for_one_subject_gives_one_row_and_the_first_one_stays(): void
    {
        UxArm::expose(7, self::NAME, 'items', 'email', 31);
        UxArm::expose(7, self::NAME, 'items', 'email', 31);
        UxArm::expose(7, self::NAME, 'control', 'email', 31);

        $rows = $this->exposures();
        $this->assertCount(1, $rows);
        $this->assertSame('items', $rows[0]->arm);
    }

    public function test_expose_keeps_a_row_for_each_member_and_each_subject(): void
    {
        UxArm::expose(7, self::NAME, 'items', 'email', 31);
        UxArm::expose(8, self::NAME, 'items', 'email', 31);
        UxArm::expose(7, self::NAME, 'items', 'email', 32);
        UxArm::expose(7, self::NAME, 'items', 'push', 31);

        $this->assertCount(4, $this->exposures());
    }

    public function test_a_push_uses_the_day_as_its_subject_so_a_member_has_one_row_a_day(): void
    {
        $day = intdiv(Carbon::now()->getTimestamp(), 86400);
        UxArm::expose(7, self::NAME, 'items', 'push', $day);
        UxArm::expose(7, self::NAME, 'items', 'push', $day);
        UxArm::expose(7, self::NAME, 'items', 'push', $day + 1);

        $this->assertCount(2, $this->exposures());
    }

    public function test_expose_says_true_only_when_this_call_wrote_the_row(): void
    {
        $this->assertTrue(UxArm::expose(7, self::NAME, 'items', 'push', 31));
        $this->assertFalse(UxArm::expose(7, self::NAME, 'items', 'push', 31), 'the row was there already');
        $this->assertFalse(UxArm::expose(7, self::NAME, '', 'push', 32), 'no arm');
        $this->assertFalse(UxArm::expose(0, self::NAME, 'items', 'push', 32), 'nobody');
        $this->assertFalse(UxArm::expose(7, self::NAME, 'items', str_repeat('x', 17), 32), 'a value too long');
        $this->assertCount(1, $this->exposures());
    }

    public function test_retract_removes_the_row_of_that_member_and_subject_only(): void
    {
        UxArm::expose(7, self::NAME, 'items', 'push', 31);
        UxArm::expose(7, self::NAME, 'items', 'push', 32);
        UxArm::expose(8, self::NAME, 'items', 'push', 31);
        UxArm::expose(7, self::NAME, 'items', 'email', 31);

        UxArm::retract(7, self::NAME, 'push', 31);

        $left = array_map(fn ($r) => [(int) $r->userid, $r->subject_type, (int) $r->subject_id], $this->exposures());
        $this->assertSame([[7, 'push', 32], [8, 'push', 31], [7, 'email', 31]], $left);
    }

    public function test_expose_with_the_arm_empty_writes_nothing(): void
    {
        UxArm::expose(7, self::NAME, '', 'email', 31);

        $this->assertSame([], $this->exposures());
    }

    public function test_expose_for_nobody_writes_nothing(): void
    {
        UxArm::expose(0, self::NAME, 'items', 'email', 31);

        $this->assertSame([], $this->exposures());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: ?string}> */
    public static function tooLong(): array
    {
        $long = str_repeat('x', 17);

        return [
            'a type of 17' => [self::NAME, 'items', $long, null],
            'a stratum of 17' => [self::NAME, 'items', 'email', $long],
            'an arm of 17' => [self::NAME, $long, 'email', null],
            'a name of 65' => [str_repeat('n', 65), 'items', 'email', null],
        ];
    }

    #[DataProvider('tooLong')]
    public function test_a_value_longer_than_its_column_is_skipped_and_logged_not_cut(string $name, string $arm, string $type, ?string $stratum): void
    {
        Log::spy();

        UxArm::expose(7, $name, $arm, $type, 31, $stratum);

        $this->assertSame(0, DB::table('ux_experiment_exposures')->where('userid', 7)->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_value_of_exactly_sixteen_is_kept(): void
    {
        UxArm::expose(7, self::NAME, str_repeat('a', 16), str_repeat('t', 16), 31, str_repeat('s', 16));

        $this->assertCount(1, $this->exposures());
    }

    /** @return array<string, array{0: array<mixed>}> */
    public static function detailThatIsMoreThanIds(): array
    {
        return [
            'a string value' => [['address' => '1 High Street']],
            'a nested list' => [['ids' => [1, 2]]],
            'an object' => [['who' => new \stdClass]],
            'a key with capitals' => [['MsgId' => 5]],
            'a key that is a number' => [[5]],
            'a key that is too long' => [[str_repeat('k', 33) => 5]],
        ];
    }

    #[DataProvider('detailThatIsMoreThanIds')]
    public function test_detail_that_holds_more_than_ids_is_refused_and_logged(array $detail): void
    {
        Log::spy();

        UxArm::expose(7, self::NAME, 'items', 'email', 31, null, $detail);

        $this->assertSame([], $this->exposures());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_detail_may_hold_ids_numbers_booleans_and_null(): void
    {
        UxArm::expose(7, self::NAME, 'items', 'email', 31, null, ['msgid' => 5, 'rate' => 0.25, 'held' => true, 'none' => null]);

        $this->assertEquals(['msgid' => 5, 'rate' => 0.25, 'held' => true, 'none' => null], json_decode($this->exposures()[0]->detail, true));
    }

    public function test_a_database_failure_is_swallowed_and_logged(): void
    {
        Log::spy();

        DB::connection()->setTablePrefix('zz_');
        try {
            UxArm::expose(7, self::NAME, 'items', 'email', 31);
        } finally {
            DB::connection()->setTablePrefix('');
        }

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame([], $this->exposures());
    }

    public function test_a_missing_exposure_table_does_not_stop_the_caller(): void
    {
        Log::spy();

        $this->withoutExposureTable(function () {
            UxArm::expose(7, self::NAME, 'items', 'email', 31);
        });

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame([], $this->exposures(), 'and the table is back afterwards');
    }
}
