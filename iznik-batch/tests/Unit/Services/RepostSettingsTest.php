<?php

namespace Tests\Unit\Services;

use App\Services\RepostSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RepostSettingsTest extends TestCase
{
    private function setRow(string $value): void
    {
        DB::table('config')->updateOrInsert(['key' => RepostSettings::CONFIG_KEY], ['value' => $value]);
    }

    public function test_reads_the_config_row(): void
    {
        $this->setRow('{"offer":4,"wanted":9,"max":2,"chaseups":6}');

        $this->assertSame(['offer' => 4, 'wanted' => 9, 'max' => 2, 'chaseups' => 6], RepostSettings::get());
    }

    public function test_the_migration_row_is_three_seven_three_and_chaseups_five(): void
    {
        // The row the migration inserts, which the test database has had since setup.
        $this->assertSame(['offer' => 3, 'wanted' => 7, 'max' => 3, 'chaseups' => 5], RepostSettings::get());
    }

    public function test_zero_is_kept_because_it_turns_reposting_off(): void
    {
        $this->setRow('{"offer":0,"wanted":7,"max":0,"chaseups":5}');

        $settings = RepostSettings::get();

        $this->assertSame(0, $settings['offer']);
        $this->assertSame(0, $settings['max']);
    }

    public function test_a_missing_row_gives_the_fallback_and_warns(): void
    {
        DB::table('config')->where('key', RepostSettings::CONFIG_KEY)->delete();
        Log::spy();

        $this->assertSame(RepostSettings::FALLBACK, RepostSettings::get());

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_row_that_is_not_json_gives_the_fallback_and_warns(): void
    {
        $this->setRow('not json');
        Log::spy();

        $this->assertSame(RepostSettings::FALLBACK, RepostSettings::get());

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_bad_value_falls_back_for_that_key_only(): void
    {
        $this->setRow('{"offer":"soon","wanted":9,"max":-1,"chaseups":2.5}');
        Log::spy();

        $this->assertSame(
            ['offer' => 3, 'wanted' => 9, 'max' => 3, 'chaseups' => 5],
            RepostSettings::get()
        );

        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_whole_numbers_as_strings_or_floats_are_accepted(): void
    {
        $this->setRow('{"offer":"5","wanted":8.0,"max":"2","chaseups":4}');

        $this->assertSame(['offer' => 5, 'wanted' => 8, 'max' => 2, 'chaseups' => 4], RepostSettings::get());
    }

    public function test_the_migration_inserts_the_row_only_when_absent(): void
    {
        $migration = require base_path('database/migrations/2026_11_10_000001_add_reposts_config_row.php');

        // Absent: inserted.
        DB::table('config')->where('key', 'reposts')->delete();
        $migration->up();
        $this->assertSame(
            '{"offer":3,"wanted":7,"max":3,"chaseups":5}',
            DB::table('config')->where('key', 'reposts')->value('value')
        );

        // Present: a value set by hand is kept.
        $this->setRow('{"offer":5,"wanted":9,"max":2,"chaseups":6}');
        $migration->up();
        $this->assertSame(
            '{"offer":5,"wanted":9,"max":2,"chaseups":6}',
            DB::table('config')->where('key', 'reposts')->value('value')
        );
        $this->assertSame(1, DB::table('config')->where('key', 'reposts')->count());
    }
}
