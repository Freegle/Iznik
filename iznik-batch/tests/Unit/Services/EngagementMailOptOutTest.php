<?php

namespace Tests\Unit\Services;

use App\Models\User;
use Tests\TestCase;

/**
 * The "Encouragement emails" toggle in Settings writes users.settings.engagement, and
 * nothing in batch read it - so turning it off did nothing and members kept getting
 * donation asks and re-engagement mail. It is also the switch the `engagement`
 * unsubscribe category turns off, so it has to be honoured for that to mean anything.
 */
class EngagementMailOptOutTest extends TestCase
{
    public function test_absent_setting_means_they_want_them(): void
    {
        $user = $this->createTestUser();
        $user->settings = ['simplemail' => 'Full'];
        $user->save();

        $this->assertTrue($user->fresh()->wantsEngagementMail());
    }

    public function test_setting_true_means_they_want_them(): void
    {
        $user = $this->createTestUser();
        $user->settings = ['engagement' => true];
        $user->save();

        $this->assertTrue($user->fresh()->wantsEngagementMail());
    }

    public function test_setting_false_opts_them_out(): void
    {
        $user = $this->createTestUser();
        $user->settings = ['engagement' => false];
        $user->save();

        $this->assertFalse($user->fresh()->wantsEngagementMail());
    }

    public function test_null_settings_means_they_want_them(): void
    {
        $user = $this->createTestUser();
        $user->settings = null;
        $user->save();

        $this->assertTrue($user->fresh()->wantsEngagementMail());
    }

    public function test_reengage_candidate_query_excludes_opted_out_members(): void
    {
        // ReengageService filters in SQL rather than per-user, so the JSON predicate has
        // to agree with wantsEngagementMail().
        $optedOut = $this->createTestUser();
        $optedOut->settings = ['engagement' => false];
        $optedOut->save();

        $optedIn = $this->createTestUser();
        $optedIn->settings = ['engagement' => true];
        $optedIn->save();

        $absent = $this->createTestUser();
        $absent->settings = ['simplemail' => 'Full'];
        $absent->save();

        $matched = User::query()
            ->whereIn('id', [$optedOut->id, $optedIn->id, $absent->id])
            ->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(users.settings, '$.engagement') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(users.settings, '$.engagement') != CAST('false' AS JSON)");
            })
            ->pluck('id')
            ->all();

        $this->assertContains($optedIn->id, $matched);
        $this->assertContains($absent->id, $matched, 'Absent means on');
        $this->assertNotContains($optedOut->id, $matched);
    }
}
