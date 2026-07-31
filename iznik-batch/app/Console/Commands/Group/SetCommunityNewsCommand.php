<?php

namespace App\Console\Commands\Group;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetCommunityNewsCommand extends Command
{
    protected $signature = 'group:set-community-news
                            {--group= : Short name of the group (required)}
                            {--on : Enable Community News for the group}
                            {--off : Disable Community News for the group}';

    protected $description = 'Enable or disable Community News (area digest + ChitChat trial) for a Freegle group';

    public function handle(): int
    {
        $groupName = $this->option('group');
        $on = (bool) $this->option('on');
        $off = (bool) $this->option('off');

        if (!$groupName) {
            $this->error('--group is required');
            return self::FAILURE;
        }

        if ($on === $off) {
            $this->error('Specify exactly one of --on or --off');
            return self::FAILURE;
        }

        $group = DB::table('groups')->where('nameshort', $groupName)->first();
        if (!$group) {
            $this->error("No group found with short name: {$groupName}");
            return self::FAILURE;
        }

        $settings = json_decode($group->settings ?? '{}', true) ?: [];
        $settings['communitynews'] = $on ? 1 : 0;

        DB::table('groups')->where('id', $group->id)->update(['settings' => json_encode($settings)]);

        $state = $on ? 'enabled' : 'disabled';
        $this->info("Community News {$state} for {$groupName} (#{$group->id})");

        return self::SUCCESS;
    }
}
