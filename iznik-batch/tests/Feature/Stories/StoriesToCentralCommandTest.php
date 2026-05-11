<?php

namespace Tests\Feature\Stories;

use App\Mail\Stories\StoriesToCentralMail;
use App\Models\Group;
use App\Services\StoriesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoriesToCentralCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
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

    public function test_command_runs_cleanly_with_no_stories(): void
    {
        $this->artisan('stories:send-to-central')
            ->assertExitCode(0);
    }

    public function test_dry_run_is_accepted(): void
    {
        $this->artisan('stories:send-to-central', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_sends_email_for_eligible_story(): void
    {
        $this->createStory();

        (new StoriesService())->sendToCentral(false);

        Mail::assertSent(StoriesToCentralMail::class, 1);
    }

    public function test_sends_one_email_for_multiple_stories(): void
    {
        $this->createStory();
        $this->createStory(['headline' => 'Another story']);

        (new StoriesService())->sendToCentral(false);

        // All stories go in one email to central
        Mail::assertSent(StoriesToCentralMail::class, 1);
    }

    public function test_email_contains_all_stories(): void
    {
        $this->createStory(['headline' => 'Story One']);
        $this->createStory(['headline' => 'Story Two']);

        (new StoriesService())->sendToCentral(false);

        Mail::assertSent(StoriesToCentralMail::class, function (StoriesToCentralMail $mail) {
            return count($mail->stories) === 2;
        });
    }

    public function test_skips_already_mailed_to_central(): void
    {
        $this->createStory(['mailedtocentral' => 1]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertNothingSent();
    }

    public function test_skips_already_mailed_to_members(): void
    {
        $this->createStory(['mailedtomembers' => 1]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertNothingSent();
    }

    public function test_skips_story_not_newsletter_reviewed(): void
    {
        $this->createStory(['newsletterreviewed' => 0]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertNothingSent();
    }

    public function test_skips_story_not_newsletter_flagged(): void
    {
        $this->createStory(['newsletter' => 0]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertNothingSent();
    }

    public function test_skips_non_public_story(): void
    {
        $this->createStory(['public' => 0]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertNothingSent();
    }

    public function test_marks_story_as_mailed_to_central(): void
    {
        $storyId = $this->createStory();

        (new StoriesService())->sendToCentral(false);

        $this->assertSame(1, (int) DB::table('users_stories')->where('id', $storyId)->value('mailedtocentral'));
    }

    public function test_dry_run_does_not_send_email(): void
    {
        $this->createStory();

        (new StoriesService())->sendToCentral(true);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_update_database(): void
    {
        $storyId = $this->createStory();

        (new StoriesService())->sendToCentral(true);

        $this->assertSame(0, (int) DB::table('users_stories')->where('id', $storyId)->value('mailedtocentral'));
    }

    public function test_returns_count_of_stories(): void
    {
        $this->createStory();
        $this->createStory(['headline' => 'Another']);

        $count = (new StoriesService())->sendToCentral(false);

        $this->assertSame(2, $count);
    }

    public function test_includes_groupname_from_membership(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(['onmap' => true]);
        $this->createMembership($user, $group);
        $storyId = $this->createStory(['userid' => $user->id]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertSent(StoriesToCentralMail::class, function (StoriesToCentralMail $mail) use ($group) {
            return $mail->stories[0]['groupname'] === $group->namefull;
        });
    }

    public function test_groupname_is_null_for_story_with_no_membership(): void
    {
        $storyId = $this->createStory(['userid' => null]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertSent(StoriesToCentralMail::class, function (StoriesToCentralMail $mail) {
            return $mail->stories[0]['groupname'] === null;
        });
    }

    public function test_includes_photo_url_for_story_with_image(): void
    {
        $storyId = $this->createStory();
        $imageId = DB::table('users_stories_images')->insertGetId([
            'storyid' => $storyId,
            'contenttype' => 'image/jpeg',
        ]);

        (new StoriesService())->sendToCentral(false);

        Mail::assertSent(StoriesToCentralMail::class, function (StoriesToCentralMail $mail) use ($imageId) {
            return str_contains($mail->stories[0]['photo'] ?? '', "simg_{$imageId}.jpg");
        });
    }

    public function test_photo_is_null_for_story_without_image(): void
    {
        $this->createStory();

        (new StoriesService())->sendToCentral(false);

        Mail::assertSent(StoriesToCentralMail::class, function (StoriesToCentralMail $mail) {
            return $mail->stories[0]['photo'] === null;
        });
    }
}
