<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxExperimentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The operator's view of UX experiments, for the shell. The experiment routes
 * (/modtools/ux/experiments) do the same through the API.
 *
 *   ux:experiment list
 *   ux:experiment create --flow=give --name=give-photos-skip --title=... --hypothesis=... --change=... --expected=... [--touches=give,login --expected-points=5]
 *   ux:experiment create --flow=reply --name=digest-subject ... --design='{"channel":"email","type":"UnifiedDigestDaily","source":"email_click","window_hours":24,"expected_points":0.5,"copy":{"control":"...","items":"..."}}'
 *   ux:experiment start give-photos-skip
 *   ux:experiment stop give-photos-skip --reason="..."
 *   ux:experiment close give-photos-skip --cleanup-pr=https://...
 *   ux:experiment config        (print the JSON the client reads, rebuilt from the rows)
 */
#[AsCommand(name: 'ux:experiment')]
class ExperimentCommand extends Command
{
    protected $signature = 'ux:experiment
        {action : list, create, start, stop, close or config}
        {name? : The experiment name (or id) for start, stop and close}
        {--flow= : create: the funnel, one of give, ask, reply, login, chat_promise, chat_outcome, settings}
        {--name= : create: the switch key the client reads, letters, digits, _ and -}
        {--title= : create: a short title}
        {--hypothesis= : create: what we think is wrong and why the change should help}
        {--change= : create: what the change does, in plain English, for the people who read it}
        {--expected= : create: the effect we expect to see}
        {--arms= : create: JSON arms, default {"control":[0,49],"b":[50,99]}}
        {--pr= : create: the pull request that adds the switch}
        {--touches= : create: comma separated funnels the change can affect, whose completion is watched as a guardrail (default: its own funnel and login)}
        {--expected-points= : create: the effect expected, in points of completion, used to check a day of the funnel could show it (default 5)}
        {--design= : create: JSON design keys for an experiment decided on the server (mail, push or server code): channel, type, source, window_hours, intake_hours, max_hours, salt, slot, copy. --touches and --expected-points win over the same keys here}
        {--reason= : stop: why}
        {--cleanup-pr= : close: the pull request that removed the switch}';

    protected $description = 'List, create, start, stop or close a UX experiment';

    public function handle(UxExperimentService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'create' => $this->create($service),
                'start' => $this->show($service->start($this->needName())),
                'stop' => $this->show($service->stop($this->needName(), $this->option('reason') ?: 'Stopped by hand.')),
                'close' => $this->show($service->close($this->needName(), $this->option('cleanup-pr'))),
                'config' => $this->config($service),
                default => $this->badAction('action must be list, create, start, stop, close or config'),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function needName(): string
    {
        $name = (string) $this->argument('name');
        if ($name === '') {
            throw new \RuntimeException('Give the experiment name or id.');
        }

        return $name;
    }

    private function list(): int
    {
        $rows = DB::table('ux_experiments')->orderByDesc('id')->limit(50)->get();
        if ($rows->isEmpty()) {
            $this->info('No experiments.');

            return self::SUCCESS;
        }
        // The end time is the time the run is due to end, so it is shown only while the experiment runs.
        $this->table(['id', 'funnel', 'name', 'status', 'started', 'ends', 'title'], $rows->map(fn ($r) => [
            $r->id, $r->flow, $r->name, $r->status, $r->started_at ?? '', $r->status === UxExperimentService::STATUS_RUNNING ? ($r->ends_at ?? '') : '', mb_substr($r->title, 0, 50),
        ])->all());

        return self::SUCCESS;
    }

    private function create(UxExperimentService $service): int
    {
        $id = $service->create([
            'flow' => $this->option('flow'),
            'name' => $this->option('name'),
            'title' => $this->option('title'),
            'hypothesis' => $this->option('hypothesis'),
            'change' => $this->option('change'),
            'expected' => $this->option('expected'),
            'arms' => $this->option('arms'),
            'pr_url' => $this->option('pr'),
            'design' => array_merge($this->designOption(), array_filter([
                'touches' => $this->option('touches') !== null ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('touches'))))) : null,
                'expected_points' => $this->option('expected-points') !== null ? $this->option('expected-points') : null,
            ], fn ($v) => $v !== null)),
        ]);
        $this->info("Created experiment $id (".$this->option('name').'), draft. Start it with: ux:experiment start '.$this->option('name'));

        return self::SUCCESS;
    }

    /**
     * The keys of --design. A value that is not a JSON object is refused in plain words, so a typo
     * does not quietly create an experiment with no design.
     *
     * @return array<string, mixed>
     */
    private function designOption(): array
    {
        $raw = $this->option('design');
        if ($raw === null || $raw === '') {
            return [];
        }
        $design = json_decode((string) $raw, true);
        if (! is_array($design) || array_is_list($design)) {
            throw new \InvalidArgumentException('--design must be a JSON object, for example {"channel":"email","type":"UnifiedDigestDaily"}');
        }

        return $design;
    }

    private function show(object $exp): int
    {
        // An experiment that has stopped or been decided has no end ahead of it, so only a running one prints one.
        $ends = $exp->status === UxExperimentService::STATUS_RUNNING && $exp->ends_at ? ", ends {$exp->ends_at}" : '';
        $this->info("{$exp->name}: {$exp->status}".$ends.($exp->decision ? " - {$exp->decision}" : ''));

        return self::SUCCESS;
    }

    private function config(UxExperimentService $service): int
    {
        $this->line($service->rebuildConfig());

        return self::SUCCESS;
    }

    private function badAction(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
