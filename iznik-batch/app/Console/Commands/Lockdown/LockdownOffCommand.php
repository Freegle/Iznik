<?php

namespace App\Console\Commands\Lockdown;

use App\Services\Lockdown\LockdownFilterSpoolService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Console\Command;

/**
 * Lift some or all surfaces, or close the incident outright (plan
 * 2026-09-27-lockdown-switch.md, section 11.4). "Lifting releases held messages at a paced
 * rate" (11.5) - that pacing lives in the same loops that created the holds
 * (ContentCheckService, ChatProcessService, LockdownHoldsService::releaseChitChatHolds via
 * lockdown:tick), not here; this command only flips the surface switch that those loops
 * read.
 *
 * Email works differently (decided 27 September, plan sections 11.7-11.8): while held,
 * member mail is deferred rather than generated, so there is nothing to catch up and no
 * `mail:spool:purge-spammers` to build. But the send queue still holds whatever was
 * rendered in the seconds before the mail loops saw the press, some of it about content
 * Support has since removed. So lifting email here first runs lockdown:filter-spool
 * (LockdownFilterSpoolService) to remove any waiting file whose member content is no
 * longer fit to send, and refuses to lift email at all if that filter reports an error -
 * left held a little longer beats resuming against an unchecked queue. `--close` lifts
 * every surface including email, so it runs the same check first. Mail the relay already
 * accepted from a marked actor before the press remains the manual runbook step (section
 * 10.14: postsuper on the relay host).
 */
class LockdownOffCommand extends Command
{
    protected $signature = 'lockdown:off
                            {--surface=* : One or more surfaces to lift (mods, chat, posts, chitchat, events, push, email, export)}
                            {--all : Lift every surface}
                            {--close : End the incident outright (implies lifting every surface)}
                            {--note= : Note recorded against the close (with --close)}
                            {--by= : User id of whoever made the change, if known}';

    protected $description = 'Lift held surfaces, or close the lockdown incident';

    public function handle(LockdownService $lockdown, LockdownFilterSpoolService $filterSpool): int
    {
        $by = $this->option('by') !== null ? (int) $this->option('by') : null;

        if ($lockdown->current() === null || !$lockdown->active()) {
            $this->error('No lockdown is active.');

            return self::FAILURE;
        }

        if ($this->option('close')) {
            if (!$this->filterSpoolBeforeLiftingEmail($filterSpool)) {
                return self::FAILURE;
            }

            $id = $lockdown->close($by, $this->option('note'));
            $this->info("Lockdown closed: id {$id}. Every surface lifted, member notice cleared.");

            return self::SUCCESS;
        }

        $surfaces = $this->option('surface');
        $all = (bool) $this->option('all');

        if (!$all && empty($surfaces)) {
            $this->error('Specify --surface=<name> (repeatable), --all, or --close.');

            return self::FAILURE;
        }

        $toLift = $all ? LockdownService::SURFACES : $surfaces;

        $unknown = array_diff($toLift, LockdownService::SURFACES);
        if (!empty($unknown)) {
            $this->error('Unknown surface(s): '.implode(', ', $unknown).'. Valid: '.implode(', ', LockdownService::SURFACES));

            return self::FAILURE;
        }

        if (in_array('email', $toLift, true) && !$this->filterSpoolBeforeLiftingEmail($filterSpool)) {
            return self::FAILURE;
        }

        $id = $lockdown->setSurfaces(array_fill_keys($toLift, false), $by);
        $this->info("Lifted: ".implode(', ', $toLift)." (row id {$id}).");

        return self::SUCCESS;
    }

    /**
     * Filters the send queue against current state before email is allowed to resume
     * (plan section 11.8). Refuses only when the filter itself reports an error - a plain
     * removal is the expected, healthy case and does not block lifting.
     */
    private function filterSpoolBeforeLiftingEmail(LockdownFilterSpoolService $filterSpool): bool
    {
        $stats = $filterSpool->filter();

        $this->info(sprintf(
            'Filtered spool before lifting email: checked %d, removed %d.',
            $stats['checked'],
            $stats['removed']
        ));

        if ($stats['errors'] > 0) {
            $this->error("lockdown:filter-spool reported {$stats['errors']} error(s) - refusing to lift email until this is resolved.");

            return false;
        }

        return true;
    }
}
