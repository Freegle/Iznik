<?php

namespace Tests\Feature\CommunityNews;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetCommunityNewsCommandTest extends TestCase
{
    private function setting(int $groupId): array
    {
        return json_decode(DB::table('groups')->where('id', $groupId)->value('settings') ?? '{}', true) ?: [];
    }

    public function test_on_then_off_toggles_the_setting(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('group:set-community-news', ['--group' => $group->nameshort, '--on' => true])
            ->assertSuccessful();
        $this->assertSame(1, $this->setting($group->id)['communitynews']);

        $this->artisan('group:set-community-news', ['--group' => $group->nameshort, '--off' => true])
            ->assertSuccessful();
        $this->assertSame(0, $this->setting($group->id)['communitynews']);
    }

    public function test_requires_exactly_one_of_on_or_off(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('group:set-community-news', ['--group' => $group->nameshort])
            ->assertFailed();

        $this->artisan('group:set-community-news', ['--group' => $group->nameshort, '--on' => true, '--off' => true])
            ->assertFailed();
    }

    public function test_unknown_group_fails(): void
    {
        $this->artisan('group:set-community-news', ['--group' => 'NoSuchGroup_xyz', '--on' => true])
            ->assertFailed();
    }
}
