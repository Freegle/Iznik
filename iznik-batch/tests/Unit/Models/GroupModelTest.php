<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use App\Models\Membership;
use Tests\TestCase;

class GroupModelTest extends TestCase
{
    /**
     * Generate a unique group name for parallel test safety.
     */
    private function uniqueName(string $prefix): string
    {
        return $prefix . '_' . uniqid();
    }

    public function test_freegle_scope_filters_freegle_groups(): void
    {
        // Create Freegle group.
        $freegleGroup = $this->createTestGroup();

        // Create non-Freegle group with unique name.
        $nonFreegle = Group::create([
            'nameshort' => $this->uniqueName('NonFreegle'),
            'namefull' => $this->uniqueName('Non Freegle Group'),
            'type' => 'Other',
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        // Filter to just our test groups to avoid interference from other parallel tests.
        $freegleGroups = Group::freegle()->whereIn('id', [$freegleGroup->id, $nonFreegle->id])->get();

        $this->assertEquals(1, $freegleGroups->count());
        $this->assertEquals($freegleGroup->id, $freegleGroups->first()->id);
    }

    public function test_is_closed_detects_closed_groups(): void
    {
        $closedGroup = Group::create([
            'nameshort' => $this->uniqueName('ClosedGroup'),
            'namefull' => $this->uniqueName('Closed Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['closed' => true],
        ]);

        $openGroup = $this->createTestGroup();

        $this->assertTrue($closedGroup->isClosed());
        $this->assertFalse($openGroup->isClosed());
    }

    public function test_active_freegle_scope_excludes_closed(): void
    {
        // Create open group.
        $openGroup = $this->createTestGroup();

        // Create closed group.
        $closedGroup = Group::create([
            'nameshort' => $this->uniqueName('ClosedGroup'),
            'namefull' => $this->uniqueName('Closed Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'onhere' => 1,
            'publish' => 1,
            'settings' => ['closed' => true],
        ]);

        // Filter to just our test groups to avoid interference from other parallel tests.
        $activeGroups = Group::activeFreegle()->whereIn('id', [$openGroup->id, $closedGroup->id])->get();

        // Only open group should be returned.
        $this->assertEquals(1, $activeGroups->count());
        $this->assertEquals($openGroup->id, $activeGroups->first()->id);
    }

    public function test_group_has_memberships_relationship(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $this->assertEquals(1, $group->memberships()->count());
    }

    public function test_group_settings_are_json_cast(): void
    {
        $group = Group::create([
            'nameshort' => $this->uniqueName('TestGroup'),
            'namefull' => $this->uniqueName('Test Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['repost' => 7, 'maxage' => 14],
        ]);

        $settings = $group->settings;

        $this->assertIsArray($settings);
        $this->assertEquals(7, $settings['repost']);
        $this->assertEquals(14, $settings['maxage']);
    }

    public function test_on_here_scope(): void
    {
        $onHere = Group::create([
            'nameshort' => $this->uniqueName('OnHereGroup'),
            'namefull' => $this->uniqueName('On Here Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'onhere' => 1,
        ]);

        $notOnHere = Group::create([
            'nameshort' => $this->uniqueName('NotOnHereGroup'),
            'namefull' => $this->uniqueName('Not On Here Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'onhere' => 0,
        ]);

        // Filter to just our test groups to avoid interference from other parallel tests.
        $results = Group::onHere()->whereIn('id', [$onHere->id, $notOnHere->id])->get();

        $this->assertTrue($results->contains('id', $onHere->id));
        $this->assertFalse($results->contains('id', $notOnHere->id));
    }

    public function test_published_scope(): void
    {
        $published = Group::create([
            'nameshort' => $this->uniqueName('PublishedGroup'),
            'namefull' => $this->uniqueName('Published Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'publish' => 1,
        ]);

        $notPublished = Group::create([
            'nameshort' => $this->uniqueName('NotPublishedGroup'),
            'namefull' => $this->uniqueName('Not Published Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'publish' => 0,
        ]);

        // Filter to just our test groups to avoid interference from other parallel tests.
        $results = Group::published()->whereIn('id', [$published->id, $notPublished->id])->get();

        $this->assertTrue($results->contains('id', $published->id));
        $this->assertFalse($results->contains('id', $notPublished->id));
    }

    public function test_not_closed_scope(): void
    {
        $open = Group::create([
            'nameshort' => $this->uniqueName('OpenGroup'),
            'namefull' => $this->uniqueName('Open Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $closed = Group::create([
            'nameshort' => $this->uniqueName('ClosedGroup2'),
            'namefull' => $this->uniqueName('Closed Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['closed' => true],
        ]);

        // Filter to just our test groups to avoid interference from other parallel tests.
        $results = Group::notClosed()->whereIn('id', [$open->id, $closed->id])->get();

        $this->assertTrue($results->contains('id', $open->id));
        $this->assertFalse($results->contains('id', $closed->id));
    }

    public function test_get_setting_returns_value(): void
    {
        $group = Group::create([
            'nameshort' => $this->uniqueName('SettingsGroup'),
            'namefull' => $this->uniqueName('Settings Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['maxage' => 30, 'repost' => 7],
        ]);

        $this->assertEquals(30, $group->getSetting('maxage'));
        $this->assertEquals(7, $group->getSetting('repost'));
    }

    public function test_get_setting_returns_default_for_missing(): void
    {
        $group = Group::create([
            'nameshort' => $this->uniqueName('NoSettingsGroup'),
            'namefull' => $this->uniqueName('No Settings Group'),
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $this->assertNull($group->getSetting('nonexistent'));
        $this->assertEquals('default', $group->getSetting('nonexistent', 'default'));
    }

    public function test_approved_members(): void
    {
        $group = $this->createTestGroup();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->createMembership($user1, $group, ['collection' => Membership::COLLECTION_APPROVED]);
        Membership::create([
            'userid' => $user2->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_PENDING,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $this->assertEquals(1, $group->approvedMembers()->count());
    }

    public function test_moderators(): void
    {
        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $owner = $this->createTestUser();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($owner, $group, ['role' => Membership::ROLE_OWNER]);

        $moderators = $group->moderators()->get();

        $this->assertEquals(2, $moderators->count());
        $this->assertTrue($moderators->contains('userid', $mod->id));
        $this->assertTrue($moderators->contains('userid', $owner->id));
        $this->assertFalse($moderators->contains('userid', $member->id));
    }

    public function test_type_constants(): void
    {
        $this->assertEquals('Freegle', Group::TYPE_FREEGLE);
        $this->assertEquals('Reuse', Group::TYPE_REUSE);
        $this->assertEquals('Other', Group::TYPE_OTHER);
    }

    public function test_messages_relationship(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);
        $this->createTestMessage($user, $group);

        $this->assertEquals(1, $group->messages()->count());
    }
}
