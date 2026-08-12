<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\GroupRippleOptOut;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:opt-out — switch rippling off (or back on) for a community, in either direction.
 *
 * Deliberately NOT a moderator setting. This is a central decision about phantom and
 * moderator-training communities, not something a community configures for itself, so the
 * only way to set it is this command. It writes groups.settings.rippling, which
 * App\Services\Ripple\GroupRippleOptOut reads and ExpandService enforces:
 *
 *   out = 0  posts made on the community never start rippling. Anything already rippling
 *            there is retracted on the next ripple:expand run (its reach row is dropped and
 *            the copies already delivered are pulled).
 *   in  = 0  the community is never a crosspost target for anyone else's posts.
 *
 * Absent means on, so switching a direction back on REMOVES the key rather than writing a 1.
 *
 *   php artisan ripple:opt-out FreeglePlayground             # both directions off
 *   php artisan ripple:opt-out OuterHebridesFreegle --direction=out
 *   php artisan ripple:opt-out FreegleFreshers2 --on         # undo, both directions
 *   php artisan ripple:opt-out --list
 *
 * Read-modify-writes the whole settings blob in PHP rather than using MySQL JSON_SET, because
 * JSON_SET will not create `$.rippling.out` when `$.rippling` is missing (or is a scalar), so
 * the SQL form silently does nothing on exactly the communities that have never had it set.
 */
class OptOutCommand extends Command
{
    protected $signature = 'ripple:opt-out
                            {group?* : Community nameshort or id (repeatable)}
                            {--direction=both : Which direction to change - out, in or both}
                            {--on : Switch rippling back ON for these communities instead of off}
                            {--list : List the communities that currently have rippling switched off, then exit}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Switch rippling off (or back on) for a community - phantom and training communities only';

    public function handle(GroupRippleOptOut $optOut): int
    {
        if ($this->option('list')) {
            return $this->listOptOuts($optOut);
        }

        $directions = $this->directions();
        if ($directions === null) {
            $this->error('--direction must be one of: out, in, both');

            return Command::INVALID;
        }

        $names = (array) $this->argument('group');
        if (empty($names)) {
            $this->error('Name at least one community, or pass --list. See --help for examples.');

            return Command::INVALID;
        }

        $groups = [];
        foreach ($names as $name) {
            $group = $this->resolveGroup((string) $name);
            if ($group === null) {
                $this->error("No community matches '$name' - give its exact nameshort or its id.");

                return Command::FAILURE;
            }
            $groups[] = $group;
        }

        $switchOn = (bool) $this->option('on');
        $dryRun = (bool) $this->option('dry-run');

        foreach ($groups as $group) {
            $settings = json_decode((string) $group->settings, true);
            if (!is_array($settings)) {
                // Empty, null or unparseable - start a fresh object rather than lose the write.
                $settings = [];
            }
            $rippling = (isset($settings['rippling']) && is_array($settings['rippling']))
                ? $settings['rippling']
                : [];

            foreach ($directions as $direction) {
                if ($switchOn) {
                    // Absent means on, so remove the key rather than storing a 1. That also lets
                    // the community drop out of GroupRippleOptOut's `settings LIKE '%rippling%'`
                    // prefilter once both directions are back on.
                    unset($rippling[$direction]);
                } else {
                    $rippling[$direction] = 0;
                }
            }

            if (empty($rippling)) {
                unset($settings['rippling']);
            } else {
                $settings['rippling'] = $rippling;
            }

            $encoded = json_encode($settings);
            $verb = $switchOn ? 'ON' : 'OFF';
            $what = implode(' and ', $directions);

            if ($dryRun) {
                $this->line("Would switch rippling $verb ($what) for {$group->nameshort} (#{$group->id}): $encoded");
                continue;
            }

            DB::table('groups')->where('id', $group->id)->update(['settings' => $encoded]);
            $this->info("Rippling $verb ($what) for {$group->nameshort} (#{$group->id}).");
            Log::info("ripple: rippling switched $verb ($what) for group {$group->id} ({$group->nameshort}) by ripple:opt-out");
        }

        if (!$dryRun && !$switchOn) {
            $this->newLine();
            $this->line('Posts already rippling from these communities are retracted on the next');
            $this->line('ripple:expand run - their reach rows are dropped and delivered copies pulled.');
        }

        return Command::SUCCESS;
    }

    /**
     * The directions this invocation changes.
     *
     * @return list<string>|null null when --direction is not a recognised value
     */
    private function directions(): ?array
    {
        return match ((string) $this->option('direction')) {
            'both' => [GroupRippleOptOut::DIRECTION_OUT, GroupRippleOptOut::DIRECTION_IN],
            'out' => [GroupRippleOptOut::DIRECTION_OUT],
            'in' => [GroupRippleOptOut::DIRECTION_IN],
            default => null,
        };
    }

    /** Exact nameshort (the identifier moderators use) or a numeric id. */
    private function resolveGroup(string $name): ?object
    {
        $q = DB::table('groups')->select('id', 'nameshort', 'settings');

        return ctype_digit($name)
            ? $q->where('id', (int) $name)->first()
            : $q->where('nameshort', $name)->first();
    }

    private function listOptOuts(GroupRippleOptOut $optOut): int
    {
        $out = $optOut->excludedGroupIds(GroupRippleOptOut::DIRECTION_OUT);
        $in = $optOut->excludedGroupIds(GroupRippleOptOut::DIRECTION_IN);
        $ids = array_unique(array_merge($out, $in));

        if (empty($ids)) {
            $this->info('Every community ripples in both directions - nothing has opted out.');

            return Command::SUCCESS;
        }

        $names = DB::table('groups')->whereIn('id', $ids)->orderBy('nameshort')->pluck('nameshort', 'id');

        $rows = [];
        foreach ($names as $id => $nameshort) {
            $rows[] = [
                $id,
                $nameshort,
                in_array((int) $id, $out, true) ? 'OFF' : 'on',
                in_array((int) $id, $in, true) ? 'OFF' : 'on',
            ];
        }

        $this->table(['id', 'nameshort', 'ripple out', 'ripple in'], $rows);

        return Command::SUCCESS;
    }
}
