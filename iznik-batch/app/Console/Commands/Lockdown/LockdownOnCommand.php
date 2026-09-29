<?php

namespace App\Console\Commands\Lockdown;

use App\Services\Lockdown\LockdownService;
use Illuminate\Console\Command;

/**
 * Press the lockdown switch: every surface held, chat hard (plan
 * 2026-09-27-lockdown-switch.md, section 11.4). Support normally does this from the
 * ModTools Support tab, which requires typing LOCKDOWN to confirm; this command is the
 * manual/scripted fallback, so a reason is required here too rather than trusting a caller
 * to have gone through that dialog.
 *
 * The announce mail to geeks@ is not sent here - lockdown:tick picks up the new row
 * (announcedat IS NULL) on its next run and sends it, the same path every later change
 * (a lift, a close) is announced through.
 */
class LockdownOnCommand extends Command
{
    protected $signature = 'lockdown:on
                            {--reason= : Why the switch is being pressed (required)}
                            {--notice= : Member notice text (leave out for no notice)}
                            {--by= : User id of whoever pressed it, if known}';

    protected $description = 'Press the lockdown switch: hold every surface';

    public function handle(LockdownService $lockdown): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required: say why the switch is being pressed.');

            return self::FAILURE;
        }

        $notice = $this->option('notice');
        $by = $this->option('by') !== null ? (int) $this->option('by') : null;

        try {
            $id = $lockdown->press($by, $reason, $notice);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Lockdown pressed: id {$id}. Every surface is now held, chat is hard.");
        $this->info('geeks@ will be emailed by the next lockdown:tick run (within a minute).');

        return self::SUCCESS;
    }
}
