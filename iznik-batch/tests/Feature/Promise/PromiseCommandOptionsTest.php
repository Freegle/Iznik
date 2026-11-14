<?php

namespace Tests\Feature\Promise;

use Tests\TestCase;

/**
 * Option validation for promise:extract and promise:train. Every case is refused
 * before the command reads the database or the CSV.
 */
class PromiseCommandOptionsTest extends TestCase
{
    public function test_extract_rejects_zero_window(): void
    {
        $this->artisan('promise:extract', ['--window' => 0, '--out' => sys_get_temp_dir() . '/promise-opts/out.csv'])
            ->assertExitCode(1);
    }

    public function test_extract_rejects_negative_tolerance(): void
    {
        $this->artisan('promise:extract', ['--tolerance' => -1, '--out' => sys_get_temp_dir() . '/promise-opts/out.csv'])
            ->assertExitCode(1);
    }

    public function test_extract_rejects_zero_negatives_per_room(): void
    {
        $this->artisan('promise:extract', ['--negatives-per-room' => 0, '--out' => sys_get_temp_dir() . '/promise-opts/out.csv'])
            ->assertExitCode(1);
    }

    public function test_train_rejects_test_fraction_outside_zero_and_one(): void
    {
        foreach ([0, 1, 1.5, -0.2] as $fraction) {
            $this->artisan('promise:train', ['--test-fraction' => $fraction, '--out' => sys_get_temp_dir() . '/promise-opts'])
                ->assertExitCode(1);
        }
    }
}
