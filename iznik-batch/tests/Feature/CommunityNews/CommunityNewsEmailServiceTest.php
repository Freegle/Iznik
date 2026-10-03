<?php

namespace Tests\Feature\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\User;
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
     * Give an authority a square catchment polygon (half-width $delta degrees)
     * centred on (lat, lng), and return its id. Mirrors the fixture pattern in
     * CommunityNewsAreaServiceTest::authority(), with a configurable delta to
     * match this file's varied catchment sizes.
     */
    private function authority(int $id, string $name, float $lat, float $lng, float $delta = 0.05): int
    {
        $srid = (int) config('freegle.srid', 3857);
        $w = $lng - $delta;
        $e = $lng + $delta;
        $s = $lat - $delta;
        $n = $lat + $delta;

        DB::insert(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, ?))',
            [$id, $name, "POLYGON(($w $s, $e $s, $e $n, $w $n, $w $s))", $srid]
        );

        return $id;
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

        $authorityId = $this->authority(920001, 'Testville', 51.5, -0.12);
        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);

        $result = $this->svc()->sendWeekly();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_sends_to_opted_in_deliverable_members_only(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920002, 'Testville', 51.50, -0.12);

        // Lives inside the authority (via settings.mylocation) -> mailed.
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u1, 51.505, -0.115);

        // Opted out of newsletters -> no mail.
        $u2 = $this->createTestUser(['email_preferred' => 'u2@test.com', 'newslettersallowed' => 0, 'bouncing' => 0]);
        $this->locate($u2, 51.50, -0.12);

        // Bouncing -> no mail.
        $u3 = $this->createTestUser(['email_preferred' => 'u3@test.com', 'newslettersallowed' => 1, 'bouncing' => 1]);
        $this->locate($u3, 51.50, -0.12);

        // Normal member living in the catchment -> one mail.
        $u4 = $this->createTestUser(['email_preferred' => 'u4@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u4, 51.49, -0.13);

        // Dormant for over the digest inactivity threshold (182.5 days) -> no
        // mail. The 2026-08-15 send went to every member however inactive
        // (643,931 mails) and dead dormant mailboxes mass-deferred the relay.
        $u5 = $this->createTestUser(['email_preferred' => 'u5@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $u5->lastaccess = now()->subDays(200);
        $u5->save();
        $this->locate($u5, 51.50, -0.12);

        // Dormant but inside the threshold -> still mailed (the boundary's
        // other side).
        $u6 = $this->createTestUser(['email_preferred' => 'u6@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $u6->lastaccess = now()->subDays(100);
        $u6->save();
        $this->locate($u6, 51.50, -0.12);

        // Asked for no email whatsoever (simplemail None) -> no mail. The
        // hand-rolled activity check this gate replaced only looked at
        // lastaccess, so 2,198 members who had turned all mail off were still
        // getting Community News.
        $u7 = $this->createTestUser([
            'email_preferred' => 'u7@test.com',
            'newslettersallowed' => 1,
            'bouncing' => 0,
            'settings' => ['simplemail' => User::SIMPLE_MAIL_NONE],
        ]);
        $this->locate($u7, 51.50, -0.12);

        // On holiday -> no mail, as for every other mail we send.
        $u8 = $this->createTestUser(['email_preferred' => 'u8@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $u8->onholidaytill = now()->addDays(7);
        $u8->save();
        $this->locate($u8, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Repair Café', 'snippet' => 'Fix stuff.',
            'url' => 'https://example.org/repair', 'source' => 'Library', 'researched_at' => now(),
        ]);

        $result = $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertSame(3, $result['sent']);
        $this->assertCount(3, $sent);
        $this->assertTrue($sent->contains(fn ($m) => $m->userId === $u1->id));
        $this->assertTrue($sent->contains(fn ($m) => $m->userId === $u4->id));
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u2->id)); // opted out
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u3->id)); // bouncing
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u5->id)); // dormant >182.5d
        $this->assertTrue($sent->contains(fn ($m) => $m->userId === $u6->id));  // dormant 100d, inside threshold
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u7->id)); // wants no email at all
        $this->assertFalse($sent->contains(fn ($m) => $m->userId === $u8->id)); // on holiday

        // Bookkeeping: item marked emailed, area cadence stamped.
        $this->assertNotNull(CommunityNewsItem::where('areaid', $area->id)->first()->emailed_at);
        $this->assertNotNull($area->fresh()->lastemailed);
    }

    /**
     * While a provider is refusing our mail we stop generating it, rather than
     * rendering a weekly issue that can only sit in the spool. The count is
     * what ModTools shows the member; the catch-up policy then drops it,
     * because next week's issue beats a stale one.
     */
    public function test_skips_members_whose_provider_is_refusing_our_mail(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920003, 'Testville', 51.50, -0.12);

        $held = $this->createTestUser([
            'email_preferred' => 'held@suppressed-example.com',
            'newslettersallowed' => 1,
            'bouncing' => 0,
        ]);
        $this->locate($held, 51.50, -0.12);

        DB::table('mail_suppressions')->insert([
            'scope' => 'domain',
            'value' => 'suppressed-example.com',
            'reason' => '421 4.7.0 temporarily deferred',
            'provider' => 'Example',
            'deferred_since' => now()->subHour(),
            'first_seen' => now(),
            'last_seen' => now(),
            'message_count' => 100,
        ]);
        app(\App\Services\Mail\MailSuppressionService::class)->flushCache();

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Repair Café', 'snippet' => 'Fix stuff.',
            'url' => 'https://example.org/repair', 'source' => 'Library', 'researched_at' => now(),
        ]);

        $result = $this->svc()->sendWeekly();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $held->id,
            'emailtype' => 'communitynews',
        ]);
    }

    /**
     * Coverage is purely a member's location against the area's authority
     * polygon now — there is no membership row to fall back on, so a member
     * with no resolvable location, or one who lives well outside the
     * authority, simply never matches.
     */
    public function test_only_mails_members_whose_location_falls_within_the_areas_authority(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920004, 'Testville', 51.50, -0.12);

        // Lives inside the authority (via settings.mylocation) -> mailed.
        $inside = $this->createTestUser(['email_preferred' => 'inside@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($inside, 51.51, -0.13);

        // Lives far outside the authority's catchment -> NOT mailed.
        $outside = $this->createTestUser(['email_preferred' => 'outside@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($outside, 55.95, -3.19); // Edinburgh

        // No location at all -> cannot be matched to any authority -> NOT mailed.
        $nowhere = $this->createTestUser(['email_preferred' => 'nowhere@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);

        // No mylocation but users.lastlocation resolves inside -> mailed
        // (the "mylocation else lastlocation" fallback).
        $lastlocId = DB::table('locations')->insertGetId([
            'name' => 'SW1A 1AA', 'type' => 'Postcode', 'lat' => 51.49, 'lng' => -0.11,
        ]);
        $lastloc = $this->createTestUser(['email_preferred' => 'lastloc@test.com', 'newslettersallowed' => 1, 'bouncing' => 0, 'lastlocation' => $lastlocId]);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12,
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
        $this->assertCount(2, $sent);
    }

    public function test_respects_weekly_cadence(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);
        config(['freegle.communitynews.email_min_days' => 7]);

        $authorityId = $this->authority(920005, 'Testville', 51.50, -0.12);
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u1, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12,
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

    public function test_pick_story_uses_flags_window_and_ai(): void
    {
        $authorityId = $this->authority(920006, 'Testville', 51.50, -0.12);
        $author = $this->createTestUser(['email_preferred' => 'story@test.com', 'fullname' => 'Storyteller Sam']);
        $this->locate($author, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12,
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
        $story = $this->svc()->pickStory($area);
        $this->assertNotNull($story);
        $this->assertSame('A lovely give', $story['headline']);
        $this->assertSame('Storyteller Sam', $story['name']);

        // AI unconvinced (null) -> no story rather than an unvetted one.
        $this->geminiPicks(null);
        $this->assertNull($this->svc()->pickStory($area));
    }

    public function test_email_includes_story_when_picked(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920007, 'Testville', 51.50, -0.12);
        $u1 = $this->createTestUser(['email_preferred' => 'u1@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u1, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.5, 'lng' => -0.12,
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

        $authorityId = $this->authority(920008, 'Testville', 51.50, -0.12);
        $u = $this->createTestUser(['email_preferred' => 'past@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12,
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

    /**
     * The research model omits event_date on most items — including ones whose
     * own blurb names the day (the 2026-08-14 send mailed a food festival six
     * days gone). When event_date is NULL, the item's TEXT is the backstop.
     */
    public function test_leaves_out_undated_items_whose_text_says_they_are_over(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920009, 'Testville', 51.50, -0.12);
        $u = $this->createTestUser(['email_preferred' => 'textdate@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12,
        ]);

        // No event_date, but the blurb names a day six days gone - must not go out.
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Food festival',
            'snippet' => 'On Saturday '.now()->subDays(6)->format('j F Y').', the square fills with stalls.',
            'url' => 'https://example.org/foodfest', 'source' => 'Council',
            'researched_at' => now()->subDays(3),
        ]);
        // No event_date, blurb names a day still to come - must go out.
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'Family fun day',
            'snippet' => 'On Saturday '.now()->addDays(8)->format('j F Y').', the park hosts games and music.',
            'url' => 'https://example.org/funday', 'source' => 'Parks',
            'researched_at' => now()->subDays(3),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $titles = array_column($sent->first()->items, 'title');
        $this->assertContains('Family fun day', $titles);
        $this->assertNotContains('Food festival', $titles);
        $this->assertNull(CommunityNewsItem::where('title', 'Food festival')->first()->emailed_at);
    }

    /** Something on today is still worth telling people about - they can still go. */
    public function test_includes_an_event_happening_today(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920010, 'Testville', 51.50, -0.12);
        $u = $this->createTestUser(['email_preferred' => 'today@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12,
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

        $authorityId = $this->authority(920011, 'Testville', 51.50, -0.12);
        $u = $this->createTestUser(['email_preferred' => 'undated@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'A few nice things.',
            'lat' => 51.5, 'lng' => -0.12,
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

    /**
     * Intros are stored on the area and reused for a week, so a mixed-language
     * greeting written before the parse-time backstop existed (12 live Welsh
     * areas as of 2026-08-17) would keep going out until re-research. The send
     * path strips it too, so a stored "Shwmae, Wrecsam!" never reaches members.
     */
    public function test_strips_a_stored_welsh_greeting_intro_at_send_time(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920012, 'Testville', 51.50, -0.12);
        $u = $this->createTestUser(['email_preferred' => 'welsh@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville',
            'intro' => 'Shwmae, Testville! The balloons are inflating.',
            'lat' => 51.5, 'lng' => -0.12,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://example.org/x', 'source' => 'S', 'researched_at' => now(),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame('The balloons are inflating.', $sent->first()->intro);
    }

    /** A stored intro that is ONLY a greeting falls back to the stock line. */
    public function test_greeting_only_stored_intro_falls_back_to_default(): void
    {
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $authorityId = $this->authority(920013, 'Testville', 51.50, -0.12);
        $u = $this->createTestUser(['email_preferred' => 'croeso@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($u, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville',
            'intro' => 'Croeso i mid August',
            'lat' => 51.5, 'lng' => -0.12,
        ]);
        CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://example.org/x', 'source' => 'S', 'researched_at' => now(),
        ]);

        $this->svc()->sendWeekly();

        $sent = Mail::sent(CommunityNewsMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame("Here's a little round-up of what's going on around Testville.", $sent->first()->intro);
    }
}
