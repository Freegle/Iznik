<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxIdeasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The operator's view of the UX ideas backlog, for the shell. This is what the hourly loop calls
 * (docs/developers/reference/ux-ideas.md). The Ideas section of the UX tab only reads.
 *
 *   ux:idea list [--flow=give] [--status=open]
 *   ux:idea show 12
 *   ux:idea schedule 12 --name=give-photos-skip [--title=... --hypothesis=... --change=... --expected=... --touches=give,login --expected-points=5 --pr=https://...]
 *   ux:idea reject 12      an idea that is wrong; the system never brings it back
 *   ux:idea reopen 12      a set-aside idea, or a queued idea whose experiment is still a draft
 */
#[AsCommand(name: 'ux:idea')]
class IdeaCommand extends Command
{
    protected $signature = 'ux:idea
        {action : list, show, schedule, reject or reopen}
        {id? : The idea number, for show, schedule, reject and reopen}
        {--flow= : list: only this funnel, one of give, ask, reply, login, chat_promise, chat_outcome, settings}
        {--status= : list: only this status, one of open, scheduled, running, done, rejected}
        {--name= : schedule: the switch name the client reads, letters, digits, _ and -}
        {--title= : schedule: a short title (default: the idea\'s title)}
        {--hypothesis= : schedule: what we think is wrong and why the change should help (default: the idea\'s reason)}
        {--change= : schedule: what the change does, in plain English (default: the change the idea generator wrote)}
        {--expected= : schedule: the effect we expect to see (default: the idea\'s)}
        {--touches= : schedule: comma separated funnels the change can affect, watched as guardrails (default: its own funnel and login)}
        {--expected-points= : schedule: the effect expected, in points of completion (default: the idea\'s guess)}
        {--pr= : schedule: the pull request that adds the switch}';

    protected $description = 'List, show, schedule, set aside or put back a UX idea';

    private const STATUSES = ['open', 'scheduled', 'running', 'done', 'rejected'];

    public function handle(UxIdeasService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'show' => $this->show($service),
                'schedule' => $this->schedule($service),
                'reject' => $this->reject($service),
                'reopen' => $this->reopen($service),
                default => $this->badAction('action must be list, show, schedule, reject or reopen'),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function needId(): int
    {
        $id = $this->argument('id');
        if ($id === null || ! ctype_digit((string) $id)) {
            throw new \RuntimeException('Give the idea number.');
        }

        return (int) $id;
    }

    private function list(): int
    {
        $status = $this->option('status');
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new \RuntimeException('--status must be one of '.implode(', ', self::STATUSES).", not '$status'.");
        }

        $q = DB::table('ux_ideas')->orderByDesc('score')->orderBy('id');
        if ($this->option('flow') !== null) {
            $q->where('flow', $this->option('flow'));
        }
        if ($status !== null) {
            $q->where('status', $status);
        }
        $rows = $q->limit(50)->get();
        if ($rows->isEmpty()) {
            $this->info('No ideas.');

            return self::SUCCESS;
        }
        $this->table(['id', 'funnel', 'type', 'score', 'status', 'title'], $rows->map(fn ($r) => [
            $r->id, $r->flow, $r->type, $r->score, $r->status, mb_substr($r->title, 0, 70),
        ])->all());

        return self::SUCCESS;
    }

    private function show(UxIdeasService $service): int
    {
        $idea = $service->find($this->needId());
        if (! $idea) {
            throw new \RuntimeException('There is no idea '.$this->argument('id').'.');
        }
        $evidence = json_decode((string) $idea->evidence, true) ?: [];
        $exp = $service->experimentFor($idea);

        $this->line("Idea {$idea->id}: {$idea->title}");
        $this->line("Funnel {$idea->flow}, {$idea->type}, score {$idea->score}, status {$idea->status}, tried {$idea->attempts} time(s).");
        $this->line('Why: '.$idea->rationale);
        $this->line('What we expect: '.$idea->expected);
        $this->line('Change: '.($evidence['change'] ?? '(none)'));
        $this->line(sprintf('Evidence: category %s, expected points %s, confidence %s, locality %s, risk %s.',
            $evidence['category'] ?? '-', $evidence['expected_points'] ?? '-', $evidence['confidence'] ?? '-', $evidence['locality'] ?? '-', $evidence['risk'] ?? '-'));
        if (! empty($evidence['files'])) {
            $this->line('Files: '.implode(', ', (array) $evidence['files']));
        }
        $problem = $idea->problem_title ?: ($evidence['problem']['where'] ?? null);
        $this->line('The problem it answers: '.($problem ?: '(none)').($idea->problem_id ? " (member problem {$idea->problem_id})" : ''));
        $this->line('The experiment it became: '.($exp ? "{$exp->name}, {$exp->status}" : '(none)'));

        return self::SUCCESS;
    }

    private function schedule(UxIdeasService $service): int
    {
        $id = $this->needId();
        $touches = $this->option('touches') !== null
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('touches')))))
            : null;
        $name = (string) $this->option('name');

        $service->scheduleExperiment($id, [
            'name' => $name,
            'title' => $this->option('title'),
            'hypothesis' => $this->option('hypothesis'),
            'change' => $this->option('change'),
            'expected' => $this->option('expected'),
            'touches' => $touches,
            'expected_points' => $this->option('expected-points'),
            'pr_url' => $this->option('pr'),
        ]);
        $this->info("Idea $id is queued as experiment $name (draft). Start it with: ux:experiment start $name");

        return self::SUCCESS;
    }

    private function reject(UxIdeasService $service): int
    {
        $id = $this->needId();
        $service->reject($id);
        $this->info("Idea $id is set aside.");

        return self::SUCCESS;
    }

    private function reopen(UxIdeasService $service): int
    {
        $id = $this->needId();
        $service->reopen($id);
        $this->info("Idea $id is waiting again.");

        return self::SUCCESS;
    }

    private function badAction(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
