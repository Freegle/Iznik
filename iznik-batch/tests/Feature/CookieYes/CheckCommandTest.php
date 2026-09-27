<?php

namespace Tests\Feature\CookieYes;

use App\Mail\Housekeeper\HousekeeperResultsMail;
use App\Services\CookieYes\CookieYesCheckResult;
use App\Services\CookieYes\CookieYesWatchdogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['freegle.mail.geeks_addr' => 'geeks@example.org']);
        DB::table('housekeeper_tasks')->where('task_key', 'cookieyes')->delete();
    }

    private function watchdogReturns(CookieYesCheckResult $result): void
    {
        $this->mock(CookieYesWatchdogService::class)->shouldReceive('run')->once()->andReturn($result);
    }

    public function test_a_pass_is_recorded_without_email(): void
    {
        $this->watchdogReturns(new CookieYesCheckResult(true, 'ilovefreegle.org: banner live, all 43 cookies categorised, scanned 7 days ago', ['detail']));

        $this->artisan('cookieyes:check')->assertSuccessful();

        $row = DB::table('housekeeper_tasks')->where('task_key', 'cookieyes')->first();
        $this->assertSame('success', $row->last_status);
        $this->assertSame('ilovefreegle.org: banner live, all 43 cookies categorised, scanned 7 days ago', $row->last_summary);
        $this->assertSame('detail', $row->last_log);
        $this->assertEquals(1, $row->enabled);
        $this->assertEquals(0, $row->placeholder);
        $this->assertEquals(192, $row->interval_hours);
        Mail::assertNothingSent();
    }

    public function test_a_failure_is_recorded_and_emailed_to_geeks(): void
    {
        $this->watchdogReturns(new CookieYesCheckResult(false, 'ilovefreegle.org: 6 uncategorised cookies (categorise in Cookie Manager)', []));

        $this->artisan('cookieyes:check')->assertSuccessful();

        $this->assertSame('failure', DB::table('housekeeper_tasks')->where('task_key', 'cookieyes')->value('last_status'));
        Mail::assertSent(HousekeeperResultsMail::class, function (HousekeeperResultsMail $mail) {
            return $mail->hasTo('geeks@example.org')
                && $mail->task === 'cookieyes'
                && $mail->status === 'failure'
                && str_contains($mail->summary, '6 uncategorised cookies');
        });
    }

    public function test_a_crash_is_recorded_and_emailed_rather_than_lost(): void
    {
        $this->mock(CookieYesWatchdogService::class)->shouldReceive('run')->once()->andThrow(new \RuntimeException('boom'));

        $this->artisan('cookieyes:check')->assertSuccessful();

        $row = DB::table('housekeeper_tasks')->where('task_key', 'cookieyes')->first();
        $this->assertSame('failure', $row->last_status);
        $this->assertStringContainsString('boom', $row->last_summary);
        Mail::assertSent(HousekeeperResultsMail::class, fn (HousekeeperResultsMail $mail) => str_contains($mail->summary, 'boom'));
    }

    public function test_it_takes_over_the_row_the_extension_used_to_own(): void
    {
        DB::table('housekeeper_tasks')->insert([
            'task_key' => 'cookieyes',
            'name' => 'CookieYes Management',
            'interval_hours' => 720,
            'enabled' => 0,
            'placeholder' => 1,
            'updated_at' => now(),
        ]);
        $this->watchdogReturns(new CookieYesCheckResult(true, 'fine', []));

        $this->artisan('cookieyes:check')->assertSuccessful();

        $rows = DB::table('housekeeper_tasks')->where('task_key', 'cookieyes')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('CookieYes watchdog', $rows[0]->name);
        $this->assertEquals(1, $rows[0]->enabled);
        $this->assertEquals(0, $rows[0]->placeholder);
    }
}
