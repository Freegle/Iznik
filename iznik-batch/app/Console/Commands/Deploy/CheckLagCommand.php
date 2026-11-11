<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Keeps the "Code Deployed" row on the SysAdmin Cron Jobs tab honest: it goes overdue when
 * master has gone green and the result has not been deployed within a week.
 *
 * Green master is read from the production branch. CI's mark-ci-passed job merges each green
 * master commit into production as "Auto-merge master to production - <subject>", so a merge's
 * second parent is a master commit that passed, and the merge's own date is when it passed.
 * Deployed is config.deploy.laravel_commit, which deploy:record-commit keeps at the Docker
 * host's checkout; a deploy always starts by pulling that.
 *
 * The row's last_run_at is the time the oldest undeployed green commit went green, or now when
 * nothing green is waiting, so the tab's usual arithmetic (last run plus a 7-day interval) does
 * the rest. When GitHub cannot be read the row is left alone and this command fails, so a long
 * outage still ends up overdue rather than looking healthy.
 */
#[AsCommand(name: 'deploy:check-lag')]
class CheckLagCommand extends Command
{
    // The registry entry in iznik-server-go/housekeeper/housekeeper.go uses this name.
    public const JOB = 'deploy:green-master';

    private const API = 'https://api.github.com/repos/Freegle/Iznik';

    private const AUTO_MERGE = 'Auto-merge master to production';

    protected $signature = 'deploy:check-lag';

    protected $description = 'Record whether green master has been deployed, for the SysAdmin Cron Jobs tab';

    public function handle(): int
    {
        $deployed = DB::table('config')->where('key', 'deploy.laravel_commit')->value('value');

        if (! $deployed) {
            $this->error('No deploy.laravel_commit recorded yet.');

            return Command::FAILURE;
        }

        $production = $this->get('/commits', ['sha' => 'production', 'per_page' => 100]);

        if ($production === null) {
            return Command::FAILURE;
        }

        $green = $this->greenCommits($production);

        if (! $green) {
            $this->error('No auto-merge from master found in the last 100 production commits.');

            return Command::FAILURE;
        }

        // Master commits the deployed checkout does not have yet, newest green tip first.
        $compare = $this->get('/compare/'.$deployed.'...'.$green[0]['sha']);

        if ($compare === null) {
            return Command::FAILURE;
        }

        $undeployed = array_flip(array_column($compare['commits'] ?? [], 'sha'));

        // Walk back from the newest green commit while it is still undeployed; the last one
        // reached is the oldest green commit waiting, and its merge time is the clock to beat.
        $oldestWaiting = null;

        foreach ($green as $g) {
            if (! isset($undeployed[$g['sha']])) {
                break;
            }

            $oldestWaiting = $g;
        }

        $short = substr($deployed, 0, 10);

        if ($oldestWaiting === null) {
            $this->record(now(), "Deployed {$short} includes the latest green master ".substr($green[0]['sha'], 0, 10).'.');
        } else {
            $this->record(
                $oldestWaiting['green_at'],
                "Deployed {$short} is behind green master by ".count($undeployed).' commit(s). The oldest waiting went green at '
                    .$oldestWaiting['green_at']->toDateTimeString().' UTC ('.substr($oldestWaiting['sha'], 0, 10).').'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * The master commits production took in, newest first, each with the time it went green.
     *
     * @return array<int, array{sha: string, green_at: Carbon}>
     */
    private function greenCommits(array $production): array
    {
        $green = [];

        foreach ($production as $c) {
            $message = $c['commit']['message'] ?? '';
            $parents = $c['parents'] ?? [];

            if (str_starts_with($message, self::AUTO_MERGE) && count($parents) === 2) {
                $green[] = [
                    'sha' => $parents[1]['sha'],
                    'green_at' => Carbon::parse($c['commit']['committer']['date'])->utc(),
                ];
            }
        }

        return $green;
    }

    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'freegle-batch'])
                ->get(self::API.$path, $query);
        } catch (\Throwable $e) {
            $this->error("GitHub {$path}: ".$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->error("GitHub {$path}: HTTP {$response->status()}");

            return null;
        }

        return $response->json();
    }

    private function record(Carbon $at, string $summary): void
    {
        DB::table('cron_job_status')->updateOrInsert(
            ['command' => self::JOB],
            [
                'last_run_at' => $at,
                'last_finished_at' => now(),
                'last_exit_code' => 0,
                'last_output' => $summary,
                'updated_at' => now(),
            ]
        );

        $this->info($summary);
    }
}
