<?php

namespace Tests\Feature\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Services\CommunityNews\CommunityNewsEmailService;
use App\Services\CommunityNews\CommunityNewsImageService;
use App\Services\GeminiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommunityNewsEmailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Network-bound collaborators are stubbed by default: no item images,
        // and the AI story filter picks nothing. Tests opt in via geminiPicks().
        $this->mock(CommunityNewsImageService::class, function ($mock) {
            $mock->shouldReceive('uploadItemImage')->andReturnNull();
            $mock->shouldReceive('deliveryUrl')->andReturnNull();
        });
        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('generateJson')->andReturnNull()->byDefault();
        });
    }

    private function geminiPicks(?int $choice): void
    {
        $this->mock(GeminiService::class, function ($mock) use ($choice) {
            $mock->shouldReceive('generateJson')->andReturn(['choice' => $choice]);
        });
    }

    private function svc(): CommunityNewsEmailService
    {
        return app(CommunityNewsEmailService::class);
    }

    /**
     * Give a group a square catchment polygon (polyindex) centred on its lat/lng.
     * Group::boot() only sets a POINT, which can never ST_Contains a member.
     */
    private function catchment($group, float $delta = 0.05): void
    {
        $srid = (int) config('freegle.srid', 3857);
        $lat = (float) $group->lat;
        $lng = (float) $group->lng;
        $w = $lng - $delta;
        $e = $lng + $delta;
        $s = $lat - $delta;
        $n = $lat + $delta;
        DB::statement(
            'UPDATE `groups` SET polyindex = ST_GeomFromText(?, ?) WHERE id = ?',
            ["POLYGON(($w $s, $e $s, $e $n, $w $n, $w $s))", $srid, $group->id]
        );
    }

    /** Put the member somewhere via the settings.mylocation route. */
    private function locate($user, float $lat, float $lng): void
    {
        $settings = $user->settings ?? [];
        $settings['mylocation'] = ['lat' => $lat, 'lng' => $lng];
        $user->settings = $settings;
        $user->save();
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

        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $g2 = $this->createTestGroup(['lat' => 51.51, 'lng' => -0.11, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g1);
        $this->catchment($g2);

        // In BOTH area groups (and living in both catchments) -> exactly one mail (dedup).
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u1, 51.505, -0.115);
        $this->createMembership($u1, $g1);
        $this->createMembership($u1, $g2);

        // Opted out of newsletters -> no mail.
        $u2 = $this->createTestUser(['email_preferred' => 'u2@test.com', 'newslettersallowed' => 0, 'bouncing' => 0]);
        $this->locate($u2, 51.50, -0.12);
        $this->createMembership($u2, $g1);

        // Bouncing -> no mail.
        $u3 = $this->createTestUser(['email_preferred' => 'u3@test.com', 'newslettersallowed' => 1, 'bouncing' => 1]);
        $this->locate($u3, 51.50, -0.12);
        $this->createMembership($u3, $g1);

        // Normal member living in the catchment -> one mail.
        $u4 = $this->createTestUser(['email_preferred' => 'u4@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u4, 51.49, -0.13);
        $this->createMembership($u4, $g1);

        // Dormant for over the digest inactivity threshold (182.5 days) -> no
        // mail. The 2026-08-15 send went to every member however inactive
        // (643,931 mails) and dead dormant mailboxes mass-deferred the relay.
        $u5 = $this->createTestUser(['email_preferred' => 'u5@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $u5->lastaccess = now()->subDays(200);
        $u5->save();
        $this->locate($u5, 51.50, -0.12);
        $this->createMembership($u5, $g1);

        // NULL lastaccess (never logged in, e.g. brand-new member) -> still
        // mailed, matching the digest convention.
        $u6 = $this->createTestUser(['email_preferred' => 'u6@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $u6->lastaccess = null;
        $u6->save();
        $this->locate($u6, 51.50, -0.12);
        $this->createMembership($u6, $g1);

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
        $this->assertSame(3, $result['sent']);
        $this->assertCount(3, $sent);
        $this->assertCount(1, $sent->filter(fn ($m) => $m->userId === $u1->id)); // deduped
        $this->assertTrue($sent->contains(fn ($m) => $m->userId === $u4->id));
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u2->id)); // opted out
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u3->id)); // bouncing
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u5->id)); // dormant >182.5d
        $this->assertTrue($sent->contains(fn ($m) => $m->userId === $u6->id));  // never logged in

        // Bookkeeping: item marked emailed, area cadence stamped.
        $this->assertNotNull(CommunityNewsItem::where('areaid', $area->id)->first()->emailed_at);
        $this->assertNotNull($area->fresh()->lastemailed);
    }

    public function test_only_mails_members_their_home_group_covers(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        // Two area groups with separate catchments a safe distance apart.
        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $g2 = $this->createTestGroup(['lat' => 51.70, 'lng' => -0.40, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g1);
        $this->catchment($g2);

        // Lives inside g1's catchment (via settings.mylocation) -> mailed.
        $inside = $this->createTestUser(['email_preferred' => 'inside@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($inside, 51.51, -0.13);
        $this->createMembership($inside, $g1);

        // Member of g1 but lives far outside its catchment -> NOT mailed,
        // even though the membership row exists (the far-flung-join case).
        $outside = $this->createTestUser(['email_preferred' => 'outside@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($outside, 55.95, -3.19); // Edinburgh
        $this->createMembership($outside, $g1);

        // No location at all -> cannot verify a home group -> NOT mailed.
        $nowhere = $this->createTestUser(['email_preferred' => 'nowhere@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->createMembership($nowhere, $g1);

        // No mylocation but users.lastlocation resolves inside -> mailed
        // (the "mylocation else lastlocation" fallback).
        $lastlocId = DB::table('locations')->insertGetId([
            'name' => 'SW1A 1AA', 'type' => 'Postcode', 'lat' => 51.49, 'lng' => -0.11,
        ]);
        $lastloc = $this->createTestUser(['email_preferred' => 'lastloc@test.com', 'newslettersallowed' => 1, 'bouncing' => 0, 'lastlocation' => $lastlocId]);
        $this->createMembership($lastloc, $g1);

        // Lives inside g1's catchment but is only a member of g2: g2 is the
        // group they'd be mailed for, and it does not cover them -> NOT mailed.
        // Membership and coverage must be of the SAME group (their home group).
        $wrongGroup = $this->createTestUser(['email_preferred' => 'wronggroup@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($wrongGroup, 51.50, -0.12);
        $this->createMembership($wrongGroup, $g2);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => min($g1->id, $g2->id), 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g1->id, $g2->id], 'groupcount' => 2,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $ids = $sent->map(fn ($m) => $m->userId)->all();
        $this->assertContains($inside->id, $ids);
        $this->assertContains($lastloc->id, $ids);
        $this->assertNotContains($outside->id, $ids);
        $this->assertNotContains($nowhere->id, $ids);
        $this->assertNotContains($wrongGroup->id, $ids);
        $this->assertCount(2, $sent);
    }

    public function test_respects_weekly_cadence(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);
        config(['freegle.communitynews.email_min_days' => 7]);

        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
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

    public function test_group_newsletter_toggle_defaults_off(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        // The ModTools "Send newsletters to members?" toggle gates the email
        // and DEFAULTS OFF for Community News: only g3 (explicitly on) mails.
        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 0]]);
        $g2 = $this->createTestGroup(['lat' => 51.51, 'lng' => -0.11, 'settings' => ['communitynews' => 1]]);
        $g3 = $this->createTestGroup(['lat' => 51.52, 'lng' => -0.10, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g1, 0.004);
        $this->catchment($g2, 0.004);
        $this->catchment($g3, 0.004);

        $u1 = $this->createTestUser(['email_preferred' => 'off@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u1, 51.50, -0.12);
        $this->createMembership($u1, $g1);
        $u2 = $this->createTestUser(['email_preferred' => 'unset@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u2, 51.51, -0.11);
        $this->createMembership($u2, $g2);
        $u3 = $this->createTestUser(['email_preferred' => 'on@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u3, 51.52, -0.10);
        $this->createMembership($u3, $g3);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => min($g1->id, $g2->id, $g3->id), 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g1->id, $g2->id, $g3->id], 'groupcount' => 3,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame($u3->id, $sent->first()->userId);
    }

    public function test_pick_story_uses_flags_window_and_ai(): void
    {
        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $author = $this->createTestUser(['email_preferred' => 'story@test.com', 'fullname' => 'Storyteller Sam']);
        $this->createMembership($author, $g1);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => $g1->id, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g1->id], 'groupcount' => 1,
        ]);

        $mk = function (array $attrs) use ($author) {
            return DB::table('users_stories')->insertGetId(array_merge([
                'userid' => $author->id,
                'date' => now()->subDays(2),
                'public' => 1,
                'reviewed' => 1,
                'newsletterreviewed' => 1,
                'newsletter' => 1,
                'headline' => 'A lovely give',
                'story' => 'Someone collected my old sofa and was thrilled.',
            ], $attrs));
        };

        // Candidate that qualifies on every flag.
        $mk([]);
        // Not newsletter-flagged -> never a candidate.
        $mk(['newsletter' => 0, 'headline' => 'Not for newsletter']);
        // Too old (before the window) -> never a candidate.
        $mk(['date' => now()->subDays(30), 'headline' => 'Ancient story']);

        // AI picks candidate 1.
        $this->geminiPicks(1);
        $story = $this->svc()->pickStory([$g1->id], $area);
        $this->assertNotNull($story);
        $this->assertSame('A lovely give', $story['headline']);
        $this->assertSame('Storyteller Sam', $story['name']);

        // AI unconvinced (null) -> no story rather than an unvetted one.
        $this->geminiPicks(null);
        $this->assertNull($this->svc()->pickStory([$g1->id], $area));
    }

    public function test_email_includes_story_when_picked(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $g1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g1);
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u1, 51.50, -0.12);
        $this->createMembership($u1, $g1);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => $g1->id, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g1->id], 'groupcount' => 1,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);
        DB::table('users_stories')->insert([
            'userid' => $u1->id, 'date' => now()->subDay(),
            'public' => 1, 'reviewed' => 1, 'newsletterreviewed' => 1, 'newsletter' => 1,
            'headline' => 'Sofa so good', 'story' => 'Gave away a sofa, made a friend.',
        ]);
        $this->geminiPicks(1);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame('Sofa so good', $sent->first()->story['headline']);
    }

    /**
     * Research runs hourly; this email goes out weekly, and an item stays
     * eligible for days after it was found. A jumble sale researched on Monday
     * and held on Wednesday was therefore still "fresh" on Friday and went out
     * inviting people to something that had already happened.
     */
    public function test_leaves_out_events_that_have_already_happened(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $g = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g);
        $u = $this->createTestUser(['email_preferred' => 'past@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);
        $this->createMembership($u, $g);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => $g->id, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g->id], 'groupcount' => 1,
        ]);

        // Over and done with - must not go out.
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Yesterday jumble sale', 'snippet' => 'Gone.',
            'url' => 'https://example.org/past', 'source' => 'Hall',
            'event_date' => now()->subDay()->toDateString(), 'researched_at' => now()->subDays(3),
        ]);
        // Still to come - must go out.
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Repair cafe next week', 'snippet' => 'Fix stuff.',
            'url' => 'https://example.org/future', 'source' => 'Library',
            'event_date' => now()->addWeek()->toDateString(), 'researched_at' => now()->subDays(3),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $titles = array_column($sent->first()->items, 'title');
        $this->assertContains('Repair cafe next week', $titles);
        $this->assertNotContains('Yesterday jumble sale', $titles);

        // The past item stays unemailed, so it is never silently consumed.
        $this->assertNull(CommunityNewsItem::where('title', 'Yesterday jumble sale')->first()->emailed_at);
    }

    /** Something on today is still worth telling people about - they can still go. */
    public function test_includes_an_event_happening_today(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $g = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g);
        $u = $this->createTestUser(['email_preferred' => 'today@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);
        $this->createMembership($u, $g);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => $g->id, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g->id], 'groupcount' => 1,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Coffee morning today', 'snippet' => 'Come along.',
            'url' => 'https://example.org/today', 'source' => 'Hall',
            'event_date' => now()->toDateString(), 'researched_at' => now()->subDays(2),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $this->assertContains('Coffee morning today', array_column($sent->first()->items, 'title'));
    }

    /**
     * Most items are not dated events at all - a new cycle path, a refurbished
     * library. Those carry no event_date and must keep flowing through.
     */
    public function test_undated_items_are_unaffected(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $g = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1, 'newsletter' => 1]]);
        $this->catchment($g);
        $u = $this->createTestUser(['email_preferred' => 'undated@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);
        $this->createMembership($u, $g);

        $area = CommunityNewsArea::create([
            'anchorgroupid' => $g->id, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12, 'groupids' => [$g->id], 'groupcount' => 1,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'New cycle path opens', 'snippet' => 'Ride it.',
            'url' => 'https://example.org/path', 'source' => 'Council',
            'event_date' => null, 'researched_at' => now()->subDays(3),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $this->assertContains('New cycle path opens', array_column($sent->first()->items, 'title'));
    }
}
