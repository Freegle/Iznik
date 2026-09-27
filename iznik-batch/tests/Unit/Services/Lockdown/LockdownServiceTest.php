<?php

namespace Tests\Unit\Services\Lockdown;

use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LockdownServiceTest extends TestCase
{
    private LockdownService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        $this->service = new LockdownService();
    }

    public function test_no_rows_means_open(): void
    {
        $this->assertFalse($this->service->active());
        foreach (LockdownService::SURFACES as $surface) {
            $this->assertFalse($this->service->held($surface));
        }
    }

    public function test_press_holds_every_surface_hard(): void
    {
        $user = $this->createTestUser();
        $id = $this->service->press($user->id, 'Voucher wave', 'security');

        $this->assertTrue($this->service->active());
        foreach (LockdownService::SURFACES as $surface) {
            $this->assertTrue($this->service->held($surface), $surface);
        }
        $this->assertSame('hard', $this->service->chatMode());

        $row = DB::table('lockdowns')->find($id);
        $this->assertEquals($id, $row->incidentid);
        $this->assertEquals($user->id, $row->startedby);
        $this->assertNotNull($row->startedat);
        $this->assertSame('security', $row->notice);
        $this->assertSame($id, $this->service->incidentId());
    }

    public function test_press_when_active_throws(): void
    {
        $this->service->press(null, 'first');
        $this->expectException(\RuntimeException::class);
        $this->service->press(null, 'second');
    }

    public function test_set_surfaces_appends_a_row_carrying_the_incident(): void
    {
        $first = $this->service->press(null, 'wave');
        $second = $this->service->setSurfaces(['mods' => false, 'chat_mode' => 'soft'], null);

        $this->assertGreaterThan($first, $second);
        $this->assertSame(2, DB::table('lockdowns')->count());
        $this->assertFalse($this->service->held('mods'));
        $this->assertTrue($this->service->held('chat'));
        $this->assertSame('soft', $this->service->chatMode());

        $row = DB::table('lockdowns')->find($second);
        $this->assertEquals($first, $row->incidentid);
        $this->assertSame('wave', $row->reason);
        $this->assertNotNull($row->startedat);
    }

    public function test_unknown_surface_rejected(): void
    {
        $this->service->press(null, 'wave');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setSurfaces(['everything' => false], null);
    }

    public function test_close_ends_the_incident(): void
    {
        $this->service->press(null, 'wave');
        $this->service->setPhrases(['Voucher ', 'voucher', ''], null);
        $this->assertSame(['voucher'], $this->service->phrases());

        $id = $this->service->close(null, 'drill over');

        $this->assertFalse($this->service->active());
        $this->assertFalse($this->service->held('chat'));
        $row = DB::table('lockdowns')->find($id);
        $this->assertSame('drill over', $row->endnote);
        $this->assertNotNull($row->endedat);
        $this->assertSame([], json_decode($row->phrases, true));
        $this->assertSame([], $this->service->phrases());
    }

    public function test_state_is_read_fresh_each_call(): void
    {
        $this->assertFalse($this->service->held('push'));
        (new LockdownService())->press(null, 'from another process');
        $this->assertTrue($this->service->held('push'));
    }

    public function test_failed_read_keeps_last_state(): void
    {
        $this->service->press(null, 'wave');

        $broken = new class extends LockdownService {
            public bool $fail = false;

            protected function readNewest(): ?object
            {
                if ($this->fail) {
                    throw new \RuntimeException('db gone');
                }

                return parent::readNewest();
            }
        };
        $this->assertTrue($broken->held('email'));
        $broken->fail = true;
        $this->assertTrue($broken->held('email'));
    }

    public function test_never_read_is_open(): void
    {
        $broken = new class extends LockdownService {
            protected function readNewest(): ?object
            {
                throw new \RuntimeException('db gone');
            }
        };
        $this->assertFalse($broken->held('chat'));
    }

    public function test_count_increments(): void
    {
        $id = $this->service->press(null, 'wave');
        $this->service->count('push');
        $this->service->count('push');
        $this->service->count('email:digest');

        $this->assertEquals(2, DB::table('lockdown_counters')->where(['lockdownid' => $id, 'kind' => 'push'])->value('count'));
        $this->assertEquals(1, DB::table('lockdown_counters')->where(['lockdownid' => $id, 'kind' => 'email:digest'])->value('count'));
    }

    public function test_count_does_nothing_when_open(): void
    {
        DB::table('lockdown_counters')->delete();
        $this->service->count('push');
        $this->assertSame(0, DB::table('lockdown_counters')->count());
    }

    public function test_notice_text(): void
    {
        $this->assertStringContainsString('vouchers', LockdownService::noticeText('security'));
        $this->assertStringContainsString('running slowly', LockdownService::noticeText('delay'));
        $this->assertNull(LockdownService::noticeText(null));
    }

    public function test_unknown_notice_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->press(null, 'wave', 'panic');
    }
}
