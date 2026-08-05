<?php

namespace Tests\Unit\Services;

use App\Models\Membership;
use App\Models\User;
use App\Services\UnsubscribeService;
use Tests\TestCase;

class UnsubscribeServiceTest extends TestCase
{
    private UnsubscribeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnsubscribeService;
    }

    private function memberWithGroups(int $count = 2): User
    {
        $user = $this->createTestUser();

        for ($i = 0; $i < $count; $i++) {
            $group = $this->createTestGroup();
            Membership::create([
                'userid' => $user->id,
                'groupid' => $group->id,
                'role' => Membership::ROLE_MEMBER,
                'collection' => Membership::COLLECTION_APPROVED,
                'emailfrequency' => 24,
                'eventsallowed' => 1,
                'volunteeringallowed' => 1,
            ]);
        }

        return $user->fresh();
    }

    public function test_digest_unsubscribe_covers_every_community(): void
    {
        // A unified digest spans all their communities, so turning it off for only one
        // would leave the same email still arriving and look like unsubscribe is broken.
        $user = $this->memberWithGroups(3);

        $changed = $this->service->apply($user, UnsubscribeService::TYPE_DIGEST);

        $this->assertSame([UnsubscribeService::TYPE_DIGEST], $changed);
        $this->assertSame(
            0,
            Membership::where('userid', $user->id)->where('emailfrequency', '!=', 0)->count(),
            'Every membership should be at emailfrequency 0'
        );
    }

    public function test_digest_unsubscribe_leaves_other_categories_alone(): void
    {
        $user = $this->memberWithGroups();

        $this->service->apply($user, UnsubscribeService::TYPE_DIGEST);
        $user->refresh();

        $this->assertEquals(1, $user->relevantallowed);
        $this->assertEquals(1, $user->newslettersallowed);
        $this->assertSame(
            2,
            Membership::where('userid', $user->id)->where('eventsallowed', 1)->count(),
            'Events should be untouched by a digest unsubscribe'
        );
    }

    public function test_newsletter_and_relevant_unsubscribe_clear_the_user_columns(): void
    {
        $user = $this->memberWithGroups();

        $this->service->apply($user, UnsubscribeService::TYPE_NEWSLETTER);
        $this->service->apply($user, UnsubscribeService::TYPE_RELEVANT);
        $user->refresh();

        $this->assertEquals(0, $user->newslettersallowed);
        $this->assertEquals(0, $user->relevantallowed);
    }

    public function test_chat_unsubscribe_writes_the_nested_setting_without_losing_others(): void
    {
        $user = $this->memberWithGroups();
        $user->settings = [
            'simplemail' => 'Full',
            'notifications' => ['email' => true, 'push' => true],
        ];
        $user->save();

        $changed = $this->service->apply($user, UnsubscribeService::TYPE_CHAT);
        $user->refresh();

        $this->assertSame([UnsubscribeService::TYPE_CHAT], $changed);
        $this->assertFalse($user->settings['notifications']['email']);
        $this->assertTrue($user->settings['notifications']['push'], 'Push must be untouched');
        $this->assertSame('Full', $user->settings['simplemail'], 'Other settings must survive');
    }

    public function test_setting_is_written_even_when_the_key_is_absent(): void
    {
        // Absent means "on" for these settings, so an absent key still has to be written -
        // otherwise the unsubscribe silently does nothing.
        $user = $this->memberWithGroups();
        $user->settings = ['simplemail' => 'Full'];
        $user->save();

        $changed = $this->service->apply($user, UnsubscribeService::TYPE_ENGAGEMENT);
        $user->refresh();

        $this->assertSame([UnsubscribeService::TYPE_ENGAGEMENT], $changed);
        $this->assertFalse($user->settings['engagement']);
        $this->assertFalse($user->wantsEngagementMail());
    }

    public function test_all_turns_off_every_category(): void
    {
        $user = $this->memberWithGroups();

        $changed = $this->service->apply($user, UnsubscribeService::TYPE_ALL);
        $user->refresh();

        $this->assertNotContains(UnsubscribeService::TYPE_ALL, $changed);
        $this->assertSame([], $this->service->stillOn($user), 'Nothing should be left on');
        $this->assertEquals(0, $user->newslettersallowed);
        $this->assertEquals(0, $user->relevantallowed);
        $this->assertFalse($user->settings['notificationmails']);
    }

    public function test_apply_reports_nothing_changed_when_already_off(): void
    {
        // The acknowledgement email has to be honest about this, rather than claiming to
        // have turned off something that was already off.
        $user = $this->memberWithGroups();
        $this->service->apply($user, UnsubscribeService::TYPE_RELEVANT);

        $changed = $this->service->apply($user->fresh(), UnsubscribeService::TYPE_RELEVANT);

        $this->assertSame([], $changed);
    }

    public function test_still_on_lists_the_remaining_categories(): void
    {
        $user = $this->memberWithGroups();

        $this->service->apply($user, UnsubscribeService::TYPE_DIGEST);
        $stillOn = $this->service->stillOn($user->fresh());

        $this->assertNotContains(UnsubscribeService::TYPE_DIGEST, $stillOn);
        $this->assertContains(UnsubscribeService::TYPE_RELEVANT, $stillOn);
        $this->assertContains(UnsubscribeService::TYPE_CHAT, $stillOn);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $user = $this->memberWithGroups();

        $this->assertFalse(UnsubscribeService::isValidType('nonsense'));
        $this->assertFalse(UnsubscribeService::isValidType(null));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->apply($user, 'nonsense');
    }

    public function test_every_type_has_a_member_facing_description(): void
    {
        foreach (UnsubscribeService::TYPES as $type) {
            $this->assertArrayHasKey($type, UnsubscribeService::DESCRIPTIONS, "No description for $type");
            $this->assertNotSame('these emails', UnsubscribeService::describe($type));
        }
    }

    public function test_category_list_is_what_the_go_api_also_implements(): void
    {
        // The Go API implements the same map for the HTTPS one-click arm, and apiv2 and
        // batch-prod are on different hosts so neither can call the other. Neither test
        // container can see the other language's tree, so the actual cross-language diff
        // lives in scripts/check-unsubscribe-categories.mjs; this pins the PHP side so a
        // change here is deliberate and shows up in review next to the Go one.
        $this->assertSame([
            'digest',
            'events',
            'volunteering',
            'newsletter',
            'relevant',
            'chat',
            'notifications',
            'engagement',
            'all',
        ], UnsubscribeService::TYPES);
    }
}
