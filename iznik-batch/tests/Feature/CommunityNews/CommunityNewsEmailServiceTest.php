<?php

namespace Tests\Feature\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Services\CommunityNews\CommunityNewsEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommunityNewsEmailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function svc(): CommunityNewsEmailService
    {
        return app(CommunityNewsEmailService::class);
    }

    public function test_gated_by_feature_flag(): void
    {
        config(['freegle.mail.enabled_types' => '']); // disabled

        $area = CommunityNewsArea::create([
            'anchorgroupid' => 1, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [1], 'groupcount' => 1,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);

        $result = $this->svc()->sendWeekly();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_sends_to_deduplicated_opted_in_members_only(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $g2 = $this->createTestGroup(['lat' => 51.51, 'lng' => -0.11, 'settings' => ['communitynews' => 1]]);

        // In BOTH area groups -> must receive exactly one mail (dedup).
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->createMembership($u1, $g1);
        $this->createMembership($u1, $g2);

        // Opted out of newsletters -> no mail.
        $u2 = $this->createTestUser(['email_preferred' => 'u2@test.com', 'newslettersallowed' => 0, 'bouncing' => 0]);
        $this->createMembership($u2, $g1);

        // Bouncing -> no mail.
        $u3 = $this->createTestUser(['email_preferred' => 'u3@test.com', 'newslettersallowed' => 1, 'bouncing' => 1]);
        $this->createMembership($u3, $g1);

        // Normal member -> one mail.
        $u4 = $this->createTestUser(['email_preferred' => 'u4@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->createMembership($u4, $g1);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => min($g1->id, $g2->id), 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g1->id, $g2->id], 'groupcount' => 2,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Repair Café', 'snippet' => 'Fix stuff.',
            'url' => 'https://example.org/repair', 'source' => 'Library', 'researched_at' => now(),
        ]);

        $result = $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertSame(2, $result['sent']);
        $this->assertCount(2, $sent);
        $this->assertCount(1, $sent->filter(fn ($m) => $m->userId === $u1->id)); // deduped
        $this->assertTrue($sent->contains(fn ($m) => $m->userId === $u4->id));
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u2->id)); // opted out
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u3->id)); // bouncing

        // Bookkeeping: item marked emailed, area cadence stamped.
        $this->assertNotNull(CommunityNewsItem::where('areaid', $area->id)->first()->emailed_at);
        $this->assertNotNull($area->fresh()->lastemailed);
    }

    public function test_respects_weekly_cadence(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);
        config(['freegle.communitynews.email_min_days' => 7]);

        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->createMembership($u1, $g1);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => $g1->id, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g1->id], 'groupcount' => 1,
            'lastemailed' => now()->subDay(), // emailed yesterday
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);

        $result = $this->svc()->sendWeekly();

        $this->assertSame(0, $result['sent']); // too soon
        Mail::assertNothingSent();
    }
}
