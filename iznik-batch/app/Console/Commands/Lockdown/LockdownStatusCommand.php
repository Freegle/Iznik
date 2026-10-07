<?php

namespace App\Console\Commands\Lockdown;

use App\Services\Lockdown\LockdownService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Print the lockdown switch's current state as the batch sees it: whether it is active,
 * which surfaces are held, and what has been counted against the incident so far (plan
 * 2026-09-27-lockdown-switch.md, section 11.4). Read-only.
 */
class LockdownStatusCommand extends Command
{
    protected $signature = 'lockdown:status';

    protected $description = 'Show the current lockdown state and incident counters';

    public function handle(LockdownService $lockdown): int
    {
        $row = $lockdown->current();

        if ($row === null) {
            $this->info('No lockdown has ever been pressed.');

            return self::SUCCESS;
        }

        $active = (int) $row->active === 1;
        $this->info($active ? 'LOCKDOWN ACTIVE' : 'No lockdown active (last incident closed)');
        $this->line("Incident id: {$row->incidentid}");
        $this->line('Reason: '.($row->reason ?? '(none)'));
        $this->line('Notice: '.($row->notice ?? '(none)'));
        $this->line('Started: '.($row->startedat ?? '(unknown)').' by user '.($row->startedby ?? '(unknown)'));

        if (!$active) {
            $this->line('Ended: '.($row->endedat ?? '(unknown)').' by user '.($row->endedby ?? '(unknown)'));
            $this->line('Close note: '.($row->endnote ?? '(none)'));
        }

        $surfaces = $lockdown->surfacesOf($row);
        $this->newLine();
        $this->table(
            ['Surface', 'Held'],
            array_map(
                fn (string $s) => [$s, ($surfaces[$s] ?? false) ? 'HELD' : 'open'],
                LockdownService::SURFACES
            )
        );

        $counters = DB::table('lockdown_counters')
            ->where('lockdownid', (int) $row->incidentid)
            ->orderByDesc('count')
            ->get(['kind', 'count']);

        $this->newLine();
        if ($counters->isEmpty()) {
            $this->line('Nothing counted against this incident yet.');
        } else {
            $this->table(['Counted', 'Count'], $counters->map(fn ($c) => [$c->kind, $c->count])->all());
        }

        return self::SUCCESS;
    }
}
