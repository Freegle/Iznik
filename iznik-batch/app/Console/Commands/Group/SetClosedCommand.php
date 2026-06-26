<?php

namespace App\Console\Commands\Group;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetClosedCommand extends Command
{
    protected $signature = 'group:set-closed
                            {--group= : Short name of the group (required)}
                            {--closed= : 1 to close the group, 0 to reopen it (required)}';

    protected $description = 'Open or close a Freegle group';

    public function handle(): int
    {
        $groupName = $this->option('group');
        $closed = $this->option('closed');

        if (!$groupName) {
            $this->error('--group is required');
            return self::FAILURE;
        }

        if ($closed === null) {
            $this->error('--closed is required (1 = closed, 0 = open)');
            return self::FAILURE;
        }

        $group = DB::table('groups')->where('nameshort', $groupName)->first();
        if (!$group) {
            $this->error("No group found with short name: {$groupName}");
            return self::FAILURE;
        }

        $settings = json_decode($group->settings ?? '{}', true) ?: [];
        $settings['closed'] = (int) $closed;

        DB::table('groups')->where('id', $group->id)->update(['settings' => json_encode($settings)]);

        $state = (int) $closed ? 'closed' : 'open';
        $this->info("Group {$groupName} (#{$group->id}) is now {$state}");

        return self::SUCCESS;
    }
}
