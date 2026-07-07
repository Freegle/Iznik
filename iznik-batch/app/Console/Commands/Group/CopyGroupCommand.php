<?php

namespace App\Console\Commands\Group;

use App\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CopyGroupCommand extends Command
{
    protected $signature = 'group:copy
                            {--from= : Short name of the source group (required)}
                            {--to= : Short name for the new group (required)}';

    protected $description = 'Copy a group\'s settings into a new unpublished group';

    private const COPIED_COLUMNS = ['settings', 'type', 'region', 'lat', 'lng', 'poly', 'polyofficial', 'tagline', 'description', 'welcomemail'];

    public function handle(): int
    {
        $fromName = $this->option('from');
        $toName = $this->option('to');

        if (!$fromName || !$toName) {
            $this->error('--from and --to are required');
            return self::FAILURE;
        }

        $source = DB::table('groups')->where('nameshort', $fromName)->first();
        if (!$source) {
            $this->error("Source group not found: {$fromName}");
            return self::FAILURE;
        }

        $existing = DB::table('groups')->where('nameshort', $toName)->first();
        if ($existing) {
            $this->error("Destination group already exists: {$toName}");
            return self::FAILURE;
        }

        $attrs = ['nameshort' => $toName, 'publish' => 0, 'onmap' => 0, 'listable' => 0, 'onhere' => 1];
        foreach (self::COPIED_COLUMNS as $col) {
            if (isset($source->$col)) {
                $attrs[$col] = $source->$col;
            }
        }

        $newGroup = Group::create($attrs);
        $this->info("Created group {$toName} (#{$newGroup->id}) from {$fromName} — publish=0, onmap=0");

        return self::SUCCESS;
    }
}
