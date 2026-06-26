<?php

namespace Tests\Unit\Mail;

use App\Mail\Contracts\RetryableMailable;
use App\Mail\Welcome\GroupWelcomeMail;
use Tests\TestCase;

/**
 * Verifies GroupWelcomeMail's RetryableMailable contract.
 *
 * MembershipsProcessingService marks the memberships_history row processed
 * unconditionally after attempting the welcome, so a render/build failure on
 * the cron hot path would otherwise drop the welcome with no retry (the
 * 2026-06-06 MJML worker-pool outage dropped 5 group welcomes this way).
 * These tests pin the descriptor round-trip and the null-cancellation guards
 * that EmailSpoolerService relies on for durable retry.
 */
class GroupWelcomeMailRetryTest extends TestCase
{
    public function test_is_a_retryable_mailable(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(['welcomemail' => 'Welcome aboard']);

        $this->assertInstanceOf(
            RetryableMailable::class,
            new GroupWelcomeMail($user, $group)
        );
    }

    public function test_descriptor_captures_recipient_and_group(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(['welcomemail' => 'Welcome aboard']);

        $descriptor = (new GroupWelcomeMail($user, $group))->mailDescriptor();

        $this->assertSame($user->id, $descriptor['userid']);
        $this->assertSame($group->id, $descriptor['groupid']);
    }

    public function test_rebuild_from_descriptor_reconstructs_equivalent_welcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(['welcomemail' => 'Welcome aboard']);

        $rebuilt = GroupWelcomeMail::rebuildFromDescriptor(
            (new GroupWelcomeMail($user, $group))->mailDescriptor()
        );

        $this->assertInstanceOf(GroupWelcomeMail::class, $rebuilt);
        $this->assertSame($user->id, $rebuilt->user->id);
        $this->assertSame($group->id, $rebuilt->group->id);
    }

    public function test_rebuild_returns_null_for_unknown_recipient(): void
    {
        $group = $this->createTestGroup(['welcomemail' => 'Welcome aboard']);

        $this->assertNull(GroupWelcomeMail::rebuildFromDescriptor([
            'userid' => 999999999,
            'groupid' => $group->id,
        ]));
    }

    public function test_rebuild_returns_null_for_unknown_group(): void
    {
        $user = $this->createTestUser();

        $this->assertNull(GroupWelcomeMail::rebuildFromDescriptor([
            'userid' => $user->id,
            'groupid' => 999999999,
        ]));
    }

    public function test_rebuild_returns_null_when_group_has_no_welcome_text(): void
    {
        $user = $this->createTestUser();
        // onhere defaults to 1 in createTestGroup; no welcomemail set.
        $group = $this->createTestGroup();

        $this->assertNull(GroupWelcomeMail::rebuildFromDescriptor([
            'userid' => $user->id,
            'groupid' => $group->id,
        ]));
    }

    public function test_rebuild_returns_null_when_group_not_onhere(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'welcomemail' => 'Welcome aboard',
            'onhere' => 0,
        ]);

        $this->assertNull(GroupWelcomeMail::rebuildFromDescriptor([
            'userid' => $user->id,
            'groupid' => $group->id,
        ]));
    }
}
