<?php

namespace Tests\Feature\Stories;

use App\Mail\Stories\StoriesNewsletterMail;
use App\Models\Group;
use App\Models\User;
use App\Services\StoriesNewsletterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoriesNewsletterCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Other tests' users/groups can slip through DatabaseTransactions isolation.
        // Delete inside the current transaction so leaked rows are hidden without
        // affecting other test classes (the DELETE is rolled back with this test's transaction).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['newsletters', 'users_stories_likes', 'users_stories_images', 'users_stories', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createStory(array $attributes = []): int
    {
        return DB::table('users_stories')->insertGetId(array_merge([
            'headline'             => 'A great story',
            'story'                => 'Something wonderful happened.',
            'public'               => 1,
            'reviewed'             => 1,
            'newsletterreviewed'   => 1,
            'newsletter'           => 1,
            'mailedtomembers'      => 0,
            'mailedtocentral'      => 0,
        ], $attributes));
    }

    private function createEligibleUser(): User
    {
        $group = $this->createTestGroup(['publish' => 1]);
        $user  = $this->createTestUser(['newslettersallowed' => 1, 'bouncing' => 0]);
        $this->createMembership($user, $group);
        return $user->fresh();
    }

    private function minStories(): void
    {
        $this->createStory(['headline' => 'Story A']);
        $this->createStory(['headline' => 'Story B']);
        $this->createStory(['headline' => 'Story C']);
    }

    // ── Threshold tests ───────────────────────────────────────────────────────

    public function test_returns_zero_when_no_stories(): void
    {
        $this->createEligibleUser();
        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_below_minimum_threshold(): void
    {
        $this->createStory();
        $this->createStory(['headline' => 'Second']);
        $this->createEligibleUser();

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    // ── Sending tests ─────────────────────────────────────────────────────────

    public function test_sends_with_minimum_three_stories(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(1, $result['sent']);
        Mail::assertSent(StoriesNewsletterMail::class, 1);
    }

    public function test_sends_one_email_per_eligible_user(): void
    {
        $this->minStories();
        $this->createEligibleUser();
        $this->createEligibleUser();
        $this->createEligibleUser();

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(3, $result['sent']);
        Mail::assertSent(StoriesNewsletterMail::class, 3);
    }

    public function test_email_sent_to_correct_address(): void
    {
        $this->minStories();
        $group = $this->createTestGroup(['publish' => 1]);
        $user  = $this->createTestUser([
            'newslettersallowed' => 1,
            'bouncing'           => 0,
            'email_preferred'    => 'recipient@example.com',
        ]);
        $this->createMembership($user, $group);

        (new StoriesNewsletterService())->generateAndSend();

        Mail::assertSent(StoriesNewsletterMail::class, fn ($m) =>
            $m->recipientEmail === 'recipient@example.com'
        );
    }

    public function test_email_contains_story_data(): void
    {
        $this->createStory(['headline' => 'First tale', 'story' => 'It was great.']);
        $this->createStory(['headline' => 'Second tale', 'story' => 'Even better.']);
        $this->createStory(['headline' => 'Third tale', 'story' => 'The best yet.']);
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend();

        Mail::assertSent(StoriesNewsletterMail::class, function (StoriesNewsletterMail $mail) {
            $headlines = array_column($mail->stories, 'headline');
            return in_array('First tale', $headlines)
                && in_array('Second tale', $headlines)
                && in_array('Third tale', $headlines);
        });
    }

    // ── Database side-effects ─────────────────────────────────────────────────

    public function test_marks_stories_as_mailed_to_members(): void
    {
        $storyId = $this->createStory();
        $this->createStory(['headline' => 'B']);
        $this->createStory(['headline' => 'C']);
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend();

        $this->assertSame(1, (int) DB::table('users_stories')->where('id', $storyId)->value('mailedtomembers'));
    }

    public function test_creates_newsletter_record_with_stories_type(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend();

        $newsletter = DB::table('newsletters')->where('type', 'Stories')->first();
        $this->assertNotNull($newsletter);
        $this->assertSame('Lovely stories from other freeglers!', $newsletter->subject);
    }

    public function test_skips_stories_already_mailed_to_members(): void
    {
        $this->createStory(['mailedtomembers' => 1]);
        $this->createStory(['headline' => 'B', 'mailedtomembers' => 1]);
        $this->createStory(['headline' => 'C', 'mailedtomembers' => 1]);
        $this->createEligibleUser();

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_skips_stories_older_than_last_newsletter(): void
    {
        // Create old newsletter record dated now
        DB::table('newsletters')->insert([
            'subject'  => 'Old newsletter',
            'textbody' => 'old',
            'type'     => 'Stories',
            'created'  => now(),
        ]);

        // Create stories with updated = 1 hour ago (before last newsletter)
        DB::table('users_stories')->insertGetId([
            'headline'           => 'Old story A',
            'story'              => 'text',
            'public'             => 1,
            'reviewed'           => 1,
            'newsletterreviewed' => 1,
            'newsletter'         => 1,
            'mailedtomembers'    => 0,
            'mailedtocentral'    => 0,
            'updated'            => now()->subHour(),
        ]);
        DB::table('users_stories')->insertGetId([
            'headline'           => 'Old story B',
            'story'              => 'text',
            'public'             => 1,
            'reviewed'           => 1,
            'newsletterreviewed' => 1,
            'newsletter'         => 1,
            'mailedtomembers'    => 0,
            'mailedtocentral'    => 0,
            'updated'            => now()->subHour(),
        ]);
        DB::table('users_stories')->insertGetId([
            'headline'           => 'Old story C',
            'story'              => 'text',
            'public'             => 1,
            'reviewed'           => 1,
            'newsletterreviewed' => 1,
            'newsletter'         => 1,
            'mailedtomembers'    => 0,
            'mailedtocentral'    => 0,
            'updated'            => now()->subHour(),
        ]);
        $this->createEligibleUser();

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    // ── Recipient filtering ───────────────────────────────────────────────────

    public function test_skips_users_with_newsletters_disabled(): void
    {
        $this->minStories();
        $group = $this->createTestGroup(['publish' => 1]);
        $user  = $this->createTestUser(['newslettersallowed' => 0]);
        $this->createMembership($user, $group);

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_skips_users_in_non_freegle_groups(): void
    {
        $this->minStories();
        $group = $this->createTestGroup(['type' => 'Other', 'publish' => 1]);
        $user  = $this->createTestUser(['newslettersallowed' => 1]);
        $this->createMembership($user, $group);

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_skips_users_in_unpublished_groups(): void
    {
        $this->minStories();
        $group = $this->createTestGroup(['publish' => 0]);
        $user  = $this->createTestUser(['newslettersallowed' => 1]);
        $this->createMembership($user, $group);

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_skips_bouncing_users(): void
    {
        $this->minStories();
        $group = $this->createTestGroup(['publish' => 1]);
        $user  = $this->createTestUser(['newslettersallowed' => 1, 'bouncing' => 1]);
        $this->createMembership($user, $group);

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_skips_groups_with_newsletter_disabled_in_settings(): void
    {
        $this->minStories();
        $group = $this->createTestGroup(['publish' => 1, 'settings' => json_encode(['newsletter' => 0])]);
        $user  = $this->createTestUser(['newslettersallowed' => 1]);
        $this->createMembership($user, $group);

        $result = (new StoriesNewsletterService())->generateAndSend();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    // ── Photo support ─────────────────────────────────────────────────────────

    public function test_includes_photo_url_for_story_with_image(): void
    {
        $storyId  = $this->createStory();
        $imageId  = DB::table('users_stories_images')->insertGetId([
            'storyid'     => $storyId,
            'contenttype' => 'image/jpeg',
        ]);
        $this->createStory(['headline' => 'B']);
        $this->createStory(['headline' => 'C']);
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend();

        Mail::assertSent(StoriesNewsletterMail::class, function (StoriesNewsletterMail $mail) use ($imageId) {
            foreach ($mail->stories as $story) {
                if ($story['photo'] !== null && str_contains($story['photo'], "simg_{$imageId}.jpg")) {
                    return true;
                }
            }
            return false;
        });
    }

    public function test_photo_is_null_for_story_without_image(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend();

        Mail::assertSent(StoriesNewsletterMail::class, function (StoriesNewsletterMail $mail) {
            return $mail->stories[0]['photo'] === null;
        });
    }

    // ── Dry run ───────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_send_emails(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        $result = (new StoriesNewsletterService())->generateAndSend(dryRun: true);
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_mark_stories_as_mailed(): void
    {
        $storyId = $this->createStory();
        $this->createStory(['headline' => 'B']);
        $this->createStory(['headline' => 'C']);
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend(dryRun: true);

        $this->assertSame(0, (int) DB::table('users_stories')->where('id', $storyId)->value('mailedtomembers'));
    }

    public function test_dry_run_does_not_create_newsletter_record(): void
    {
        $this->minStories();
        $this->createEligibleUser();

        (new StoriesNewsletterService())->generateAndSend(dryRun: true);

        $this->assertSame(0, (int) DB::table('newsletters')->where('type', 'Stories')->count());
    }

    // ── Artisan command ───────────────────────────────────────────────────────

    public function test_command_runs_cleanly_with_no_stories(): void
    {
        $this->artisan('stories:newsletter')->assertExitCode(0);
    }

    public function test_command_dry_run_flag_accepted(): void
    {
        $this->artisan('stories:newsletter', ['--dry-run' => true])->assertExitCode(0);
    }
}
