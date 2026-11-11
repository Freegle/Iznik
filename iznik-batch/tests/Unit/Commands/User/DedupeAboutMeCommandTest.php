<?php

namespace Tests\Unit\Commands\User;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * users_aboutme keeps one row per change. Rows that repeat the previous text for
 * the same member are removed; real changes, including going back to earlier
 * text, stay.
 */
class DedupeAboutMeCommandTest extends TestCase
{
    private function history(int $userid, array $texts): void
    {
        foreach ($texts as $i => $text) {
            DB::table('users_aboutme')->insert([
                'userid' => $userid,
                'text' => $text,
                'timestamp' => now()->subDays(count($texts) - $i),
            ]);
        }
    }

    private function texts(int $userid): array
    {
        return DB::table('users_aboutme')->where('userid', $userid)
            ->orderBy('timestamp')->orderBy('id')->pluck('text')->all();
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $user = $this->createTestUser();
        $this->history($user->id, ['a', 'a', 'a']);

        $exit = Artisan::call('users:dedupe-aboutme', ['--pause' => 0]);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(['a', 'a', 'a'], $this->texts($user->id));
    }

    public function test_apply_keeps_each_change_and_drops_the_repeats(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $this->history($user->id, ['a', 'a', 'b', 'b', 'b', 'a', null, null, '']);
        $this->history($other->id, ['x', 'x']);

        $exit = Artisan::call('users:dedupe-aboutme', ['--apply' => true, '--pause' => 0, '--users' => 1]);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(['a', 'b', 'a', null, ''], $this->texts($user->id));
        $this->assertSame(['x'], $this->texts($other->id));
    }

    public function test_a_change_of_case_or_trailing_space_is_a_change(): void
    {
        // The comparison is on the exact bytes, not the column's case-insensitive collation.
        $user = $this->createTestUser();
        $this->history($user->id, ['Hello', 'hello', 'hello ', 'hello ']);

        Artisan::call('users:dedupe-aboutme', ['--apply' => true, '--pause' => 0]);

        $this->assertSame(['Hello', 'hello', 'hello '], $this->texts($user->id));
    }

    public function test_the_earliest_row_of_a_run_is_the_one_kept(): void
    {
        $user = $this->createTestUser();
        $this->history($user->id, ['a', 'a']);
        $first = DB::table('users_aboutme')->where('userid', $user->id)->orderBy('timestamp')->value('id');

        Artisan::call('users:dedupe-aboutme', ['--apply' => true, '--pause' => 0]);

        $this->assertSame([$first], DB::table('users_aboutme')->where('userid', $user->id)->pluck('id')->all());
    }
}
