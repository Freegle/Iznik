<?php

namespace Tests\Feature\Noticeboard;

use App\Mail\Noticeboard\NoticeboardThankMail;
use App\Models\User;
use App\Services\NoticeboardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NoticeboardThankCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createNoticeboard(User $user, array $attributes = []): int
    {
        return DB::table('noticeboards')->insertGetId(array_merge([
            'addedby' => $user->id,
            'active' => 1,
            'name' => 'Test Noticeboard',
            'lat' => 51.5,
            'lng' => -0.1,
            'thanked' => null,
        ], $attributes));
    }

    public function test_command_runs_cleanly_with_no_noticeboards(): void
    {
        $this->artisan('noticeboards:thank-users')
            ->assertExitCode(0);
    }

    public function test_dry_run_is_accepted(): void
    {
        $this->artisan('noticeboards:thank-users', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_sends_thank_email_for_unthanked_noticeboard(): void
    {
        $user = $this->createTestUser();
        $this->createNoticeboard($user);

        (new NoticeboardService())->thankUsers(false);

        Mail::assertSent(NoticeboardThankMail::class, 1);
    }

    public function test_sends_one_email_per_user_not_per_noticeboard(): void
    {
        $user = $this->createTestUser();
        $this->createNoticeboard($user);
        $this->createNoticeboard($user, ['name' => 'Second Board']);

        (new NoticeboardService())->thankUsers(false);

        // Two noticeboards, same user — only one email
        Mail::assertSent(NoticeboardThankMail::class, 1);
    }

    public function test_sends_separate_emails_for_different_users(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $this->createNoticeboard($user1);
        $this->createNoticeboard($user2);

        (new NoticeboardService())->thankUsers(false);

        Mail::assertSent(NoticeboardThankMail::class, 2);
    }

    public function test_skips_already_thanked_noticeboard(): void
    {
        $user = $this->createTestUser();
        $this->createNoticeboard($user, ['thanked' => now()]);

        (new NoticeboardService())->thankUsers(false);

        Mail::assertNothingSent();
    }

    public function test_skips_inactive_noticeboard(): void
    {
        $user = $this->createTestUser();
        $this->createNoticeboard($user, ['active' => 0]);

        (new NoticeboardService())->thankUsers(false);

        Mail::assertNothingSent();
    }

    public function test_skips_noticeboard_with_no_name(): void
    {
        $user = $this->createTestUser();
        $this->createNoticeboard($user, ['name' => null]);

        (new NoticeboardService())->thankUsers(false);

        Mail::assertNothingSent();
    }

    public function test_marks_noticeboard_as_thanked_after_sending(): void
    {
        $user = $this->createTestUser();
        $boardId = $this->createNoticeboard($user);

        (new NoticeboardService())->thankUsers(false);

        $this->assertNotNull(DB::table('noticeboards')->where('id', $boardId)->value('thanked'));
    }

    public function test_dry_run_does_not_send_emails(): void
    {
        $user = $this->createTestUser();
        $this->createNoticeboard($user);

        (new NoticeboardService())->thankUsers(true);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_update_database(): void
    {
        $user = $this->createTestUser();
        $boardId = $this->createNoticeboard($user);

        (new NoticeboardService())->thankUsers(true);

        $this->assertNull(DB::table('noticeboards')->where('id', $boardId)->value('thanked'));
    }

    public function test_returns_count_of_users_thanked(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $this->createNoticeboard($user1);
        $this->createNoticeboard($user2);

        $count = (new NoticeboardService())->thankUsers(false);

        $this->assertSame(2, $count);
    }
}
