<?php

namespace Tests\Unit\Support;

use App\Support\EeeAlarm;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The alarm's contract: every raise logs, but a key escalates to Sentry once
 * per process - a loop over hundreds of failing items must produce one event,
 * not hundreds. Capture is observed through the test seam, matching the
 * codebase rule of never asserting on the \Sentry global itself.
 */
class EeeAlarmTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EeeAlarm::reset();
    }

    protected function tearDown(): void
    {
        EeeAlarm::reset();
        parent::tearDown();
    }

    public function test_same_key_escalates_once_but_logs_every_time(): void
    {
        $captured = [];
        EeeAlarm::$captureWith = function (string $m) use (&$captured) {
            $captured[] = $m;
        };

        Log::shouldReceive('error')->twice()->with('[EEE] pipeline dark', []);

        EeeAlarm::raise('k1', 'pipeline dark');
        EeeAlarm::raise('k1', 'pipeline dark');

        $this->assertSame(['[EEE] pipeline dark'], $captured);
    }

    public function test_distinct_keys_each_escalate(): void
    {
        $captured = [];
        EeeAlarm::$captureWith = function (string $m) use (&$captured) {
            $captured[] = $m;
        };

        Log::shouldReceive('error')->twice();

        EeeAlarm::raise('a', 'first');
        EeeAlarm::raise('b', 'second');

        $this->assertSame(['[EEE] first', '[EEE] second'], $captured);
    }

    public function test_reset_forgets_sent_keys(): void
    {
        $captured = [];
        EeeAlarm::$captureWith = function (string $m) use (&$captured) {
            $captured[] = $m;
        };
        Log::shouldReceive('error')->twice();

        EeeAlarm::raise('k', 'msg');
        $seam = EeeAlarm::$captureWith;
        EeeAlarm::reset();
        EeeAlarm::$captureWith = $seam;
        EeeAlarm::raise('k', 'msg');

        $this->assertCount(2, $captured);
    }
}
