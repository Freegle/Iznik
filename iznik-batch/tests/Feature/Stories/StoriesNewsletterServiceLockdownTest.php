<?php

namespace Tests\Feature\Stories;

use App\Mail\Stories\StoriesNewsletterMail;
use App\Services\Lockdown\LockdownService;
use App\Services\StoriesNewsletterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * StoriesNewsletterService::generateAndSend() under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.7). Ordinary (no lockdown) behaviour is
 * covered by StoriesNewsletterCommandTest; this file is only the lockdown branch.
 *
 * The watermark here is the whole newsletter round, not a per-recipient pointer: the
 * `newsletters` row and every selected story's `mailedtomembers` flag are committed once,
 * before the per-member send loop starts, so the check has to happen BEFORE that commit
 * (checking only in the per-member loop would still let the batch of stories be consumed
 * with nobody mailed - the same trap a per-group digest watermark has to avoid).
 */
class StoriesNewsletterServiceLockdownTest extends TestCase
{
    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['newsletters', 'users_stories_likes', 'users_stories_images', 'users_stories', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
    }

    private function createStory(array $attributes = []): int
    {
        return DB::table('users_stories')->insertGetId(array_merge([
            'headline' => 'A great story',
            'story' => 'Something wonderful happened.',
            'public' => 1,
            'reviewed' => 1,
            'newsletterreviewed' => 1,
            'newsletter' => 1,
            'mailedtomembers' => 0,
            'mailedtocentral' => 0,
        ], $attributes));
    }

    private function minStories(): void
    {
        $this->createStory(['headline' => 'Story A']);
        $this->createStory(['headline' => 'Story B']);
        $this->createStory(['headline' => 'Story C']);
    }

    private function createEligibleUser(): \App\Models\User
    {
        $group = $this->createTestGroup(['publish' => 1]);
        $user = $this->createTestUser(['newslettersallowed' => 1, 'bouncing' => 0]);
        $this->createMembership($user, $group);

        return $user->fresh();
    }

    public function test_held_round_sends_nothing_and_consumes_no_stories(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        $this->lockdown->press(null, 'test lockdown');

        $result = (new StoriesNewsletterService())->generateAndSend();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();

        // Nothing committed: the exact trap this check exists to avoid - stories
        // consumed (marked mailed) with nobody actually mailed.
        $this->assertSame(3, DB::table('users_stories')->where('mailedtomembers', 0)->count());
        $this->assertSame(0, DB::table('newsletters')->where('type', 'Stories')->count());

        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:stories')->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter->count);

        $ack = DB::table('lockdown_acks')->where('loop', 'mail-loops')->first();
        $this->assertNotNull($ack);
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_a_round_with_too_few_stories_is_not_deferred(): void
    {
        // Below MIN_STORIES, generateAndSend() returns before it would ever reach the
        // lockdown check - there was never a round here to defer.
        $this->createStory();
        $this->createEligibleUser();

        $this->lockdown->press(null, 'test lockdown');

        $result = (new StoriesNewsletterService())->generateAndSend();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, DB::table('lockdown_counters')->where('kind', 'deferred:stories')->count());
    }

    public function test_lifting_email_sends_the_round_that_was_held(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        $this->lockdown->press(null, 'test lockdown');
        $held = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $held['sent']);

        $this->lockdown->setSurfaces(['email' => false], null);

        $sent = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(1, $sent['sent']);
        Mail::assertSent(StoriesNewsletterMail::class, 1);
        $this->assertSame(3, DB::table('users_stories')->where('mailedtomembers', 1)->count());
    }
}
