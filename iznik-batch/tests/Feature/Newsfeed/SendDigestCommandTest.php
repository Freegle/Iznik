<?php

namespace Tests\Feature\Newsfeed;

use App\Mail\Newsfeed\NewsfeedDigestMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for mail:newsfeed:digest (migration of
 * iznik-server cron/newsfeed_digest.php → Newsfeed::digest()).
 *
 * Uses the --user path for deterministic, isolated assertions.
 */
class SendDigestCommandTest extends TestCase
{
    private const LONG_MESSAGE = 'This is a chitchat post that is definitely long enough to pass the filter.';

    private function makeLocation(): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'TestLoc_' . uniqid('', true),
            'type' => 'Point',
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
    }

    /**
     * Eligible recipient: recent lastaccess, a location, approved Freegle membership.
     *
     * @return array{0: User, 1: Group}
     */
    private function makeEligibleUser(): array
    {
        $locId = $this->makeLocation();
        $user = $this->createTestUser(['lastaccess' => now(), 'lastlocation' => $locId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        return [$user, $group];
    }

    private function createPost(int $userId, int $groupId, array $attributes = []): int
    {
        return DB::table('newsfeed')->insertGetId(array_merge([
            'userid' => $userId,
            'groupid' => $groupId,
            'type' => 'Message',
            'message' => self::LONG_MESSAGE,
            'added' => now()->subHours(2),
            'timestamp' => now()->subHours(2),
            'position' => DB::raw("ST_GeomFromText('POINT(-0.1278 51.5074)', 3857)"),
        ], $attributes));
    }

    public function test_no_posts_sends_nothing(): void
    {
        Mail::fake();

        [$user] = $this->makeEligibleUser();

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])
            ->expectsOutputToContain('Sent 0 newsfeed digest(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_digest_for_eligible_user(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])
            ->expectsOutputToContain('Sent 1 newsfeed digest(s)')
            ->assertExitCode(0);

        Mail::assertSent(NewsfeedDigestMail::class, function (NewsfeedDigestMail $mail) use ($user) {
            return $mail->hasTo($user->email_preferred)
                && count($mail->items) === 1
                && str_contains($mail->envelope()->subject, 'from your neighbours');
        });
    }

    public function test_skips_posts_below_min_length(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id, ['message' => 'too short']);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_users_own_posts(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $this->createPost($user->id, $group->id);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_already_seen_posts(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $postId = $this->createPost($other->id, $group->id);

        // Marker already at/above the post id → nothing new.
        DB::table('newsfeed_users')->insert(['userid' => $user->id, 'newsfeedid' => $postId]);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_deleted_and_hidden_posts(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id, ['deleted' => now()]);
        $this->createPost($other->id, $group->id, ['hidden' => now()]);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_posts_older_than_window(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id, [
            'added' => now()->subDays(20),
            'timestamp' => now()->subDays(20),
        ]);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_user_without_location(): void
    {
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]); // no lastlocation
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_notificationmails_off(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        DB::table('users')->where('id', $user->id)
            ->update(['settings' => json_encode(['notificationmails' => false])]);
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_updates_seen_marker_after_send(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $postId = $this->createPost($other->id, $group->id);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id])->assertExitCode(0);

        $marker = DB::table('newsfeed_users')->where('userid', $user->id)->value('newsfeedid');
        $this->assertSame($postId, (int) $marker);
    }

    public function test_dry_run_sends_nothing_and_does_not_update_marker(): void
    {
        Mail::fake();

        [$user, $group] = $this->makeEligibleUser();
        $other = $this->createTestUser();
        $this->createPost($other->id, $group->id);

        $this->artisan('mail:newsfeed:digest', ['--user' => $user->id, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send 1 newsfeed digest(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertNull(DB::table('newsfeed_users')->where('userid', $user->id)->value('newsfeedid'));
    }
}
