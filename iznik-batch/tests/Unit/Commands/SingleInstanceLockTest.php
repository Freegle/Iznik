<?php

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The four pile-prone commands share App\Traits\SingleInstanceLock because
 * withoutOverlapping() cannot stop runInBackground() jobs overlapping (the
 * mutex is freed when the foreground tick forks). On 2026-08-27 that hole let
 * embeddings:generate stack 11 deep and firstreply:maxreach 7 deep, swapping
 * the host to a standstill. These tests prove each command skips cleanly while
 * another run holds its lock, and that a finished run leaves the lock free.
 * The skip path never spawns an embedder or touches the routing server, so it
 * is safe to exercise for all four.
 */
class SingleInstanceLockTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function guardedCommands(): array
    {
        return [
            'firstreply:maxreach' => ['firstreply:maxreach', 'firstreply:maxreach:run', []],
            'ripple:proximity-notes' => ['ripple:proximity-notes', 'ripple:proximity-notes:run', []],
            'embeddings:generate' => ['embeddings:generate', 'embeddings:generate:run', ['--limit' => 1]],
            'embeddings:searches' => ['embeddings:searches', 'embeddings:searches:run', ['--limit' => 1]],
        ];
    }

    /**
     * @dataProvider guardedCommands
     *
     * @param  array<string, mixed>  $options
     */
    public function test_run_exits_without_working_while_the_lock_is_held(string $command, string $lockName, array $options): void
    {
        Http::fake();
        config(['freegle.ripple.proximity_notes' => true]);

        $held = Cache::lock($lockName, 30);
        $this->assertTrue($held->get(), "precondition: acquire {$lockName} as another run");

        try {
            $this->artisan($command, $options)
                ->expectsOutputToContain('Another run is in progress')
                ->assertExitCode(0);
        } finally {
            $held->release();
        }
    }

    /**
     * ripple:proximity-notes gates on config BEFORE taking the lock, so a
     * disabled run must not block on - or consume - the lock at all.
     */
    public function test_proximity_notes_config_gate_precedes_the_lock(): void
    {
        config(['freegle.ripple.proximity_notes' => false]);

        $held = Cache::lock('ripple:proximity-notes:run', 30);
        $this->assertTrue($held->get(), 'precondition: hold the lock');

        try {
            $this->artisan('ripple:proximity-notes')
                ->doesntExpectOutputToContain('Another run is in progress')
                ->assertExitCode(0);
        } finally {
            $held->release();
        }
    }

    /**
     * A normal run releases its lock on the way out, so the next scheduled run
     * can acquire it. maxreach with empty tables is a cheap real run.
     */
    public function test_normal_run_releases_the_lock_when_it_finishes(): void
    {
        Http::fake();

        $this->artisan('firstreply:maxreach', ['--limit' => 1])->assertExitCode(0);

        $after = Cache::lock('firstreply:maxreach:run', 30);
        $this->assertTrue(
            $after->get(),
            'lock must be free after a normal run completes (released in finally)'
        );
        $after->release();
    }
}
