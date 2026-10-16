<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentService;
use App\Services\Ux\UxIdeasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * ux:ideas-sync: an idea's status follows its experiment, read hourly. The experiment engine knows
 * nothing of ideas, so these tests make an experiment the way a person would (queue an idea, start
 * it, let the engine's tick decide it) and then run the sync.
 */
class UxIdeasSyncTest extends TestCase
{
    use FakesUxLoki;

    /** The one query the engine reads an experiment with. */
    private const HOURLY = '/sum by \(arm, flow, action_name, logged_in, start_logged_in, ok\)/';

    private UxIdeasService $ideas;

    private UxExperimentService $experiments;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->fakeLoki();
        foreach (['ux_ideas', 'ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        config()->set('freegle.ux.experiments.min_starts_per_arm', 50);
        config()->set('freegle.ux.ideas.max_attempts', 2);
        $this->ideas = app(UxIdeasService::class);
        $this->experiments = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function idea(array $over = []): int
    {
        $now = Carbon::now();

        return DB::table('ux_ideas')->insertGetId($over + [
            'flow' => 'give', 'title' => 'Make skipping photos obvious', 'rationale' => 'People stall at photos.',
            'expected' => 'More people finish.', 'evidence' => json_encode(['change' => 'A plain skip link.', 'expected_points' => 4]),
            'status' => 'open', 'score' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Queue an idea as a draft experiment, the way `ux:idea schedule` does. */
    private function queue(int $idea, string $name): int
    {
        return $this->ideas->scheduleExperiment($idea, ['name' => $name]);
    }

    private function row(int $idea): object
    {
        return DB::table('ux_ideas')->where('id', $idea)->first();
    }

    private function setExperiment(int $id, string $status): void
    {
        DB::table('ux_experiments')->where('id', $id)->update(['status' => $status]);
    }

    private function labels(string $arm, string $flow, string $event, string $loggedIn = 'false', string $ok = ''): array
    {
        return ['arm' => $arm, 'flow' => $flow, 'action_name' => $event, 'logged_in' => $loggedIn, 'start_logged_in' => $loggedIn, 'ok' => $ok];
    }

    /** Feed Loki with counts by arm for the give funnel. */
    private function lokiArms(array $arms): void
    {
        $series = [];
        foreach ($arms as $arm => $c) {
            $series[] = [$this->labels($arm, 'give', 'flow_start'), $c['starts']];
            $series[] = [$this->labels($arm, 'give', 'flow_complete'), $c['completes']];
        }
        $this->lokiMatrix(self::HOURLY, $series);
    }

    /**
     * Queue the idea, start the experiment, let it run its 24 hours with arms too close to call,
     * and let the engine's tick end it. The experiment ends inconclusive.
     */
    private function inconclusiveRun(int $idea, string $name, string $startAt): int
    {
        Carbon::setTestNow($startAt);
        $id = $this->queue($idea, $name);
        $this->experiments->start($id);
        Carbon::setTestNow(Carbon::parse($startAt, 'UTC')->addHours(24)->addMinutes(17));
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 200], 'b' => ['starts' => 400, 'completes' => 203]]);
        $this->experiments->tick();

        return $id;
    }

    public function test_a_running_experiment_makes_its_idea_running(): void
    {
        $idea = $this->idea();
        $id = $this->queue($idea, 'give-photos-skip');
        $this->experiments->start($id);

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(['running' => 1, 'done' => 0, 'back_to_open' => 0], $out);
        $row = $this->row($idea);
        $this->assertSame('running', $row->status);
        $this->assertSame($id, (int) $row->experiment_id);
    }

    public function test_a_queued_idea_with_a_draft_experiment_stays_queued(): void
    {
        $idea = $this->idea();
        $id = $this->queue($idea, 'give-photos-skip');

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 0], $out);
        $row = $this->row($idea);
        $this->assertSame('scheduled', $row->status);
        $this->assertSame($id, (int) $row->experiment_id);
    }

    public function test_adopted_closed_reverted_and_stopped_make_the_idea_done(): void
    {
        $ideas = [];
        foreach (['adopted', 'closed', 'reverted', 'stopped'] as $status) {
            $idea = $this->idea(['title' => "Idea $status"]);
            $this->setExperiment($this->queue($idea, "exp-$status"), $status);
            $ideas[$status] = $idea;
        }

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(4, $out['done']);
        foreach ($ideas as $status => $idea) {
            $row = $this->row($idea);
            $this->assertSame('done', $row->status, $status);
            $this->assertNotNull($row->experiment_id, "$status: a finished idea keeps its experiment");
            $this->assertSame(0, (int) $row->attempts, "$status is a decision, not a failed try");
        }
    }

    public function test_a_running_idea_follows_its_experiment_to_the_end(): void
    {
        $idea = $this->idea();
        $id = $this->queue($idea, 'give-photos-skip');
        $this->experiments->start($id);
        $this->ideas->syncWithExperiments();
        $this->assertSame('running', $this->row($idea)->status);

        $this->setExperiment($id, 'adopted');
        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(1, $out['done']);
        $this->assertSame('done', $this->row($idea)->status);
    }

    public function test_an_inconclusive_test_returns_the_idea_twice_then_uses_it_up(): void
    {
        $idea = $this->idea();

        $first = $this->inconclusiveRun($idea, 'first-try', '2026-10-14 10:00:00');
        $this->assertSame('inconclusive', $this->experiments->find($first)->status);
        // The engine does not touch ideas, so until the sync the idea still says queued.
        $this->assertSame('scheduled', $this->row($idea)->status);

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 1], $out);
        $row = $this->row($idea);
        $this->assertSame('open', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNull($row->experiment_id);

        $second = $this->inconclusiveRun($idea, 'second-try', '2026-10-16 10:00:00');
        $this->assertSame('inconclusive', $this->experiments->find($second)->status);

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(['running' => 0, 'done' => 1, 'back_to_open' => 0], $out);
        $row = $this->row($idea);
        $this->assertSame('done', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame($second, (int) $row->experiment_id, 'a used-up idea keeps its last experiment');
    }

    public function test_the_number_of_tries_comes_from_config(): void
    {
        config()->set('freegle.ux.ideas.max_attempts', 1);
        $idea = $this->idea();
        $this->inconclusiveRun($idea, 'only-try', '2026-10-14 10:00:00');

        $this->ideas->syncWithExperiments();

        $this->assertSame('done', $this->row($idea)->status);
        $this->assertSame(1, (int) $this->row($idea)->attempts);
    }

    public function test_a_rejected_idea_is_never_touched(): void
    {
        // A set-aside idea that still points at an experiment which has ended: the sync does not read it.
        $rejected = $this->idea(['title' => 'Set aside', 'status' => 'rejected']);
        $id = $this->queue($this->idea(['title' => 'Other']), 'other');
        DB::table('ux_ideas')->where('id', $rejected)->update(['experiment_id' => $id]);
        $this->setExperiment($id, 'inconclusive');
        $before = (array) $this->row($rejected);

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 1], $out, 'only the other idea moved');
        $this->assertSame($before, (array) $this->row($rejected));
    }

    public function test_an_experiments_end_is_counted_once_however_often_the_sync_runs(): void
    {
        $idea = $this->idea();
        $this->inconclusiveRun($idea, 'first-try', '2026-10-14 10:00:00');

        $this->ideas->syncWithExperiments();
        $again = [$this->ideas->syncWithExperiments(), $this->ideas->syncWithExperiments(), $this->ideas->syncWithExperiments()];

        foreach ($again as $out) {
            $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 0], $out);
        }
        $this->assertSame(1, (int) $this->row($idea)->attempts);
    }

    public function test_a_change_made_between_the_read_and_the_update_is_not_overwritten(): void
    {
        $idea = $this->idea();
        $id = $this->queue($idea, 'give-photos-skip');
        $this->setExperiment($id, 'adopted');
        // Someone sets the idea aside just after the sync reads its experiment: the update names the
        // status it read, so it changes nothing.
        $fired = 0;
        DB::listen(function ($q) use ($idea, &$fired) {
            if ($fired === 0 && str_contains($q->sql, 'from `ux_experiments` where `id`')) {
                $fired++;
                DB::table('ux_ideas')->where('id', $idea)->update(['status' => 'rejected']);
            }
        });

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(1, $fired, 'the change was made between the read and the update');
        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 0], $out);
        $this->assertSame('rejected', $this->row($idea)->status);
    }

    /**
     * Run $during once, just after the sync has read an idea's experiment and before it writes. It returns
     * how many times it ran, by reference, so a test can check that the change really came in between.
     */
    private function afterTheExperimentIsRead(callable $during, ?int &$fired = null): void
    {
        $fired = 0;
        DB::listen(function ($q) use ($during, &$fired) {
            if ($fired === 0 && str_contains($q->sql, 'from `ux_experiments` where `id`')) {
                $fired++;
                $during();
            }
        });
    }

    public function test_an_idea_linked_to_another_experiment_between_the_read_and_the_update_is_not_overwritten(): void
    {
        // Experiment A has been adopted, so the sync means to mark the idea done. Between its read of A and
        // its update the idea is linked to experiment B and keeps the status it had. The status alone cannot
        // tell the sync that anything changed, so the update must name the experiment it read.
        $idea = $this->idea();
        $a = $this->queue($idea, 'exp-a');
        $this->setExperiment($a, 'adopted');
        $b = $this->queue($this->idea(['title' => 'Another idea']), 'exp-b');
        $this->afterTheExperimentIsRead(function () use ($idea, $b) {
            DB::table('ux_ideas')->where('id', $idea)->update(['experiment_id' => $b]);
        }, $fired);

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(1, $fired, 'the change was made between the read and the update');
        $row = $this->row($idea);
        $this->assertSame('scheduled', $row->status, "A's end is not applied to an idea that now follows B");
        $this->assertSame($b, (int) $row->experiment_id);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame(0, $out['done']);
    }

    public function test_an_idea_put_back_and_queued_again_between_the_read_and_the_update_keeps_its_new_draft(): void
    {
        // Experiment A is gone, so the sync means to send the idea back to waiting. Between its read and its
        // update a person puts the idea back (allowed, as A is gone) and queues it again as draft B. The idea
        // says queued again, as it did when the sync read it, but it follows B now.
        $idea = $this->idea();
        $a = $this->queue($idea, 'exp-a');
        DB::table('ux_experiments')->where('id', $a)->delete();
        $b = null;
        $this->afterTheExperimentIsRead(function () use ($idea, &$b) {
            $this->ideas->reopen($idea);
            $b = $this->queue($idea, 'exp-b');
        }, $fired);

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(1, $fired, 'the person acted between the read and the update');
        $row = $this->row($idea);
        $this->assertSame('scheduled', $row->status, 'still queued, on the new draft');
        $this->assertSame($b, (int) $row->experiment_id);
        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 0], $out);
    }

    public function test_an_idea_whose_experiment_is_gone_goes_back_to_open(): void
    {
        $idea = $this->idea();
        $id = $this->queue($idea, 'give-photos-skip');
        DB::table('ux_experiments')->where('id', $id)->delete();

        $out = $this->ideas->syncWithExperiments();

        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 1], $out);
        $row = $this->row($idea);
        $this->assertSame('open', $row->status);
        $this->assertNull($row->experiment_id);
        $this->assertSame(0, (int) $row->attempts, 'a missing experiment is not a try');
    }

    public function test_with_no_experiment_table_the_sync_changes_nothing(): void
    {
        $idea = $this->idea();
        $this->setExperiment($this->queue($idea, 'give-photos-skip'), 'adopted');
        $service = new class extends UxIdeasService
        {
            protected function tableExists(string $table): bool
            {
                return $table !== 'ux_experiments' && parent::tableExists($table);
            }
        };

        $this->assertSame(['running' => 0, 'done' => 0, 'back_to_open' => 0], $service->syncWithExperiments());
        $this->assertSame('scheduled', $this->row($idea)->status);
    }

    public function test_the_sync_command_prints_counts(): void
    {
        $running = $this->idea(['title' => 'Running one']);
        $this->experiments->start($this->queue($running, 'run-one'));
        $done = $this->idea(['title' => 'Done one', 'flow' => 'ask']);
        $this->setExperiment($this->queue($done, 'done-one'), 'adopted');
        $gone = $this->idea(['title' => 'Gone one', 'flow' => 'reply']);
        DB::table('ux_experiments')->where('id', $this->queue($gone, 'gone-one'))->delete();

        $this->artisan('ux:ideas-sync')
            ->expectsOutput('Ideas: 1 running, 1 done, 1 back to open.')
            ->assertExitCode(0);

        $this->artisan('ux:ideas-sync')
            ->expectsOutput('Ideas: 0 running, 0 done, 0 back to open.')
            ->assertExitCode(0);
    }
}
