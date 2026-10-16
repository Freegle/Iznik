<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentService;
use App\Services\Ux\UxIdeasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * Turning an idea into an experiment, and the commands a person (or the hourly loop) uses on ideas:
 * ux:idea list, show, schedule, reject and reopen. An idea becomes a draft experiment made by the
 * experiment engine, and the idea is queued, in one transaction.
 */
class UxIdeaScheduleTest extends TestCase
{
    use FakesUxLoki;

    private UxIdeasService $ideas;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->fakeLoki();
        foreach (['ux_ideas', 'ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        $this->ideas = app(UxIdeasService::class);
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
            'expected' => 'More people finish posting.', 'evidence' => json_encode(['change' => 'A plain "Add photos later" link.', 'expected_points' => 2.5]),
            'status' => 'open', 'score' => 2, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function row(int $idea): object
    {
        return DB::table('ux_ideas')->where('id', $idea)->first();
    }

    private function seedVolume(string $flow, int $startedPerDay, float $rate): void
    {
        for ($i = 1; $i <= 7; $i++) {
            DB::table('ux_flow_outcome_daily')->insert([
                'date' => Carbon::now()->subDays($i)->toDateString(), 'flow' => $flow,
                'started' => $startedPerDay, 'completed' => (int) round($startedPerDay * $rate),
            ]);
        }
    }

    // ---------------------------------------------------------------- the service

    public function test_schedule_creates_a_draft_copies_expected_points_and_queues_the_idea_in_one_transaction(): void
    {
        $this->seedVolume('give', 20000, 0.5);
        $idea = $this->idea();
        $level = DB::transactionLevel();

        $id = $this->ideas->scheduleExperiment($idea, [
            'name' => 'give-photos-skip', 'touches' => ['give', 'login'], 'pr_url' => 'https://github.com/Freegle/Iznik/pull/1',
        ]);

        $this->assertSame($level, DB::transactionLevel(), 'the transaction was closed');
        $exp = DB::table('ux_experiments')->where('id', $id)->first();
        $this->assertSame('draft', $exp->status);
        $this->assertSame('give', $exp->flow);
        $this->assertSame('give-photos-skip', $exp->name);
        // What the person did not give comes from the idea.
        $this->assertSame('Make skipping photos obvious', $exp->title);
        // The hypothesis is the reason, and the change is the idea's own change text.
        $this->assertSame('People stall at photos.', $exp->hypothesis);
        $this->assertSame('A plain "Add photos later" link.', $exp->change_description);
        $this->assertSame('More people finish posting.', $exp->expected);
        $this->assertSame('https://github.com/Freegle/Iznik/pull/1', $exp->pr_url);
        $design = json_decode($exp->design, true);
        $this->assertSame(['give', 'login'], $design['touches']);
        $this->assertSame(2.5, $design['expected_points']);

        $row = $this->row($idea);
        $this->assertSame('scheduled', $row->status);
        $this->assertSame($id, (int) $row->experiment_id);

        // The engine checks the effect the idea expects against a day of the funnel: a 2.5-point
        // idea gives a 2.5-point design, with the power worked out for it.
        $started = app(UxExperimentService::class)->start($id);
        $design = json_decode($started->design, true);
        $this->assertSame(2.5, $design['expected_points']);
        $this->assertGreaterThan(0.95, $design['power']);
    }

    public function test_what_the_person_gives_wins_over_the_idea_and_a_wild_guess_is_left_to_the_engine(): void
    {
        $idea = $this->idea(['evidence' => json_encode(['expected_points' => 5000000])]);

        $id = $this->ideas->scheduleExperiment($idea, [
            'name' => 'give-own', 'title' => 'Our own title', 'hypothesis' => 'Our hypothesis', 'change' => 'Our change', 'expected' => 'Our effect',
            'expected_points' => 3,
        ]);

        $exp = DB::table('ux_experiments')->where('id', $id)->first();
        $this->assertSame('Our own title', $exp->title);
        $this->assertSame('Our hypothesis', $exp->hypothesis);
        $this->assertSame('Our change', $exp->change_description);
        $this->assertSame('Our effect', $exp->expected);
        // JSON keeps a whole number whole, so this is compared by value.
        $this->assertEquals(3.0, json_decode($exp->design, true)['expected_points']);

        // A guess the engine would refuse is not passed on, so the idea can still be queued.
        $other = $this->idea(['title' => 'Another', 'evidence' => json_encode(['expected_points' => 5000000])]);
        $id = $this->ideas->scheduleExperiment($other, ['name' => 'give-wild']);
        $design = DB::table('ux_experiments')->where('id', $id)->value('design');
        $this->assertTrue($design === null || ! array_key_exists('expected_points', json_decode($design, true)));
    }

    public function test_the_change_falls_back_to_the_ideas_reason_when_the_generator_wrote_none(): void
    {
        $idea = $this->idea(['evidence' => json_encode(['expected_points' => 2])]);

        $id = $this->ideas->scheduleExperiment($idea, ['name' => 'give-no-change']);

        $this->assertSame('People stall at photos.', DB::table('ux_experiments')->where('id', $id)->value('change_description'));
    }

    public function test_schedule_refuses_an_idea_that_is_not_open_and_leaves_no_draft(): void
    {
        foreach (['scheduled', 'running', 'done', 'rejected'] as $status) {
            $idea = $this->idea(['title' => "Idea $status", 'status' => $status]);
            try {
                $this->ideas->scheduleExperiment($idea, ['name' => "exp-$status"]);
                $this->fail("a $status idea was scheduled");
            } catch (\RuntimeException $e) {
                $this->assertSame('That idea is not open: it is already scheduled, running or finished', $e->getMessage());
            }
            $this->assertSame($status, $this->row($idea)->status);
        }
        $this->assertSame(0, DB::table('ux_experiments')->count());
    }

    public function test_schedule_rolls_back_the_draft_when_the_link_fails(): void
    {
        $idea = $this->idea();
        // The idea stops being open after the draft is made and before it is linked. The change is on the
        // same connection, so it is inside the transaction and goes with the rest when the draft is rolled back.
        $fired = 0;
        DB::listen(function ($q) use ($idea, &$fired) {
            if ($fired === 0 && str_contains($q->sql, 'insert into `ux_experiments`')) {
                $fired++;
                DB::table('ux_ideas')->where('id', $idea)->update(['status' => 'rejected']);
            }
        });

        try {
            $this->ideas->scheduleExperiment($idea, ['name' => 'give-photos-skip']);
            $this->fail('the idea was queued although it changed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('changed while it was being queued', $e->getMessage());
        }

        $this->assertSame(1, $fired);
        $this->assertSame(0, DB::table('ux_experiments')->count(), 'the draft was rolled back');
        $row = $this->row($idea);
        $this->assertSame('open', $row->status, 'nothing of the failed attempt is left, the idea included');
        $this->assertNull($row->experiment_id);
    }

    public function test_schedule_needs_a_real_funnel_and_a_name(): void
    {
        $general = $this->idea(['title' => 'Old general idea', 'flow' => 'general']);
        $idea = $this->idea();

        foreach ([
            [$general, ['name' => 'exp-general'], \RuntimeException::class, 'not for one of the funnels'],
            [$idea, [], \InvalidArgumentException::class, 'name of the switch'],
            [$idea, ['name' => '  '], \InvalidArgumentException::class, 'name of the switch'],
            [$idea, ['name' => 'has spaces'], \InvalidArgumentException::class, 'name must be 1 to 64 letters'],
            [999999, ['name' => 'exp-none'], \RuntimeException::class, 'There is no idea 999999'],
        ] as [$id, $attrs, $class, $message]) {
            try {
                $this->ideas->scheduleExperiment($id, $attrs);
                $this->fail('scheduled with '.json_encode($attrs));
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('ux_experiments')->count());
        $this->assertSame('open', $this->row($idea)->status);
        $this->assertSame('open', $this->row($general)->status);
    }

    public function test_an_idea_with_nothing_expected_is_refused_by_the_engine_and_stays_open(): void
    {
        $idea = $this->idea(['expected' => '']);

        try {
            $this->ideas->scheduleExperiment($idea, ['name' => 'give-nothing']);
            $this->fail('queued with nothing expected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('expected is required', $e->getMessage());
        }

        $this->assertSame('open', $this->row($idea)->status);
        $this->assertSame(0, DB::table('ux_experiments')->count());
    }

    public function test_a_name_that_is_taken_is_refused_in_plain_words(): void
    {
        $this->ideas->scheduleExperiment($this->idea(), ['name' => 'give-photos-skip']);
        $second = $this->idea(['title' => 'Second idea']);

        try {
            $this->ideas->scheduleExperiment($second, ['name' => 'give-photos-skip']);
            $this->fail('the same name was used twice');
        } catch (\RuntimeException $e) {
            $this->assertSame('An experiment called give-photos-skip already exists. Choose another name.', $e->getMessage());
        }
        $this->assertSame('open', $this->row($second)->status);
    }

    // ---------------------------------------------------------------- reject and reopen

    public function test_reject_sets_a_waiting_idea_aside_and_refuses_any_other(): void
    {
        $idea = $this->idea();
        $this->ideas->reject($idea);
        $this->assertSame('rejected', $this->row($idea)->status);

        foreach (['scheduled' => 'queued', 'running' => 'being tried', 'done' => 'tried', 'rejected' => 'set aside'] as $status => $word) {
            $other = $this->idea(['title' => "Idea $status", 'status' => $status]);
            try {
                $this->ideas->reject($other);
                $this->fail("a $status idea was set aside");
            } catch (\RuntimeException $e) {
                $this->assertSame("Only a waiting idea can be set aside. That idea is $word.", $e->getMessage());
            }
            $this->assertSame($status, $this->row($other)->status);
        }

        $this->expectExceptionMessage('There is no idea 999999.');
        $this->ideas->reject(999999);
    }

    public function test_reopen_brings_a_set_aside_idea_back_to_waiting(): void
    {
        $idea = $this->idea(['status' => 'rejected']);

        $this->ideas->reopen($idea);

        $this->assertSame('open', $this->row($idea)->status);
    }

    public function test_reopen_frees_a_scheduled_idea_whose_experiment_is_still_a_draft(): void
    {
        $idea = $this->idea();
        $id = $this->ideas->scheduleExperiment($idea, ['name' => 'give-photos-skip']);
        $draft = (array) DB::table('ux_experiments')->where('id', $id)->first();

        $this->ideas->reopen($idea);

        $row = $this->row($idea);
        $this->assertSame('open', $row->status);
        $this->assertNull($row->experiment_id);
        $this->assertSame($draft, (array) DB::table('ux_experiments')->where('id', $id)->first(), 'the draft is left as it is');

        // It can be scheduled again under a new name; the old name is taken.
        try {
            $this->ideas->scheduleExperiment($idea, ['name' => 'give-photos-skip']);
            $this->fail('the old name was used again');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        $again = $this->ideas->scheduleExperiment($idea, ['name' => 'give-photos-skip-2']);
        $this->assertSame($again, (int) $this->row($idea)->experiment_id);
        $this->assertSame('scheduled', $this->row($idea)->status);
    }

    public function test_reopen_frees_a_scheduled_idea_whose_experiment_row_is_gone(): void
    {
        $idea = $this->idea();
        $id = $this->ideas->scheduleExperiment($idea, ['name' => 'give-photos-skip']);
        DB::table('ux_experiments')->where('id', $id)->delete();

        $this->ideas->reopen($idea);

        $row = $this->row($idea);
        $this->assertSame('open', $row->status);
        $this->assertNull($row->experiment_id);
    }

    public function test_reopen_refuses_a_scheduled_idea_whose_experiment_has_started(): void
    {
        foreach (['running', 'adopted', 'inconclusive'] as $status) {
            $idea = $this->idea(['title' => "Idea $status"]);
            $id = $this->ideas->scheduleExperiment($idea, ['name' => "exp-$status"]);
            DB::table('ux_experiments')->where('id', $id)->update(['status' => $status]);

            try {
                $this->ideas->reopen($idea);
                $this->fail("a $status experiment's idea was put back");
            } catch (\RuntimeException $e) {
                $this->assertSame("The experiment exp-$status for that idea has started, so the idea cannot be put back to waiting.", $e->getMessage());
            }
            $row = $this->row($idea);
            $this->assertSame('scheduled', $row->status);
            $this->assertSame($id, (int) $row->experiment_id);
        }
    }

    public function test_reopen_refuses_the_ideas_it_does_not_apply_to(): void
    {
        foreach (['open' => 'That idea is already waiting.',
            'running' => 'Only a set-aside idea, or a queued one whose experiment is still a draft, can be put back to waiting. That idea is being tried.',
            'done' => 'Only a set-aside idea, or a queued one whose experiment is still a draft, can be put back to waiting. That idea is tried.'] as $status => $message) {
            $idea = $this->idea(['title' => "Idea $status", 'status' => $status]);
            try {
                $this->ideas->reopen($idea);
                $this->fail("a $status idea was put back");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertSame($status, $this->row($idea)->status);
        }
    }

    // ---------------------------------------------------------------- the command

    private function ideaCommand(string $action, ?int $id = null, array $options = []): array
    {
        $code = Artisan::call('ux:idea', array_filter(['action' => $action, 'id' => $id], fn ($v) => $v !== null) + $options);

        return [$code, Artisan::output()];
    }

    public function test_the_idea_command_lists_shows_schedules_rejects_and_reopens(): void
    {
        // The lower score is made first, so it has the lower id: a list in the order of the ids would put
        // it first, and only a list by score puts the best idea first.
        $this->idea(['title' => 'Ask idea', 'flow' => 'ask', 'score' => 5]);
        $best = $this->idea(['title' => 'Best idea', 'score' => 9, 'type' => 'fix']);
        $this->idea(['title' => 'Done idea', 'status' => 'done', 'score' => 7]);
        // Two with one score: the lower id is listed first.
        $tieA = $this->idea(['title' => 'Tie a', 'score' => 3]);
        $tieB = $this->idea(['title' => 'Tie b', 'score' => 3]);
        $this->assertLessThan($tieB, $tieA);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });
        [$code, $out] = $this->ideaCommand('list', null, ['--status' => 'open']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Best idea', $out);
        $this->assertStringContainsString('Ask idea', $out);
        $this->assertStringNotContainsString('Done idea', $out);
        $this->assertLessThan(strpos($out, 'Ask idea'), strpos($out, 'Best idea'), 'by score');
        $this->assertLessThan(strpos($out, 'Tie b'), strpos($out, 'Tie a'), 'then by id');
        // Rows with one score come back in the order of their ids whether or not the query asks for it, so
        // the tie-break is proved in the query itself.
        $this->assertNotEmpty(array_filter($sql, fn ($s) => str_contains($s, 'order by `score` desc, `id` asc')), 'the list is ordered by score and then id');

        [, $out] = $this->ideaCommand('list', null, ['--flow' => 'ask']);
        $this->assertStringContainsString('Ask idea', $out);
        $this->assertStringNotContainsString('Best idea', $out);

        [$code, $out] = $this->ideaCommand('list', null, ['--status' => 'soon']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--status must be one of open, scheduled, running, done, rejected', $out);

        [$code, $out] = $this->ideaCommand('show', $best);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Idea $best: Best idea", $out);
        $this->assertStringContainsString('Funnel give, fix, score 9.00, status open', $out);
        $this->assertStringContainsString('Change: A plain "Add photos later" link.', $out);
        $this->assertStringContainsString('The experiment it became: (none)', $out);

        [$code, $out] = $this->ideaCommand('schedule', $best, ['--name' => 'give-photos-skip', '--touches' => 'give, login', '--expected-points' => '3']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Idea $best is queued as experiment give-photos-skip (draft). Start it with: ux:experiment start give-photos-skip", $out);
        $exp = DB::table('ux_experiments')->where('name', 'give-photos-skip')->first();
        $this->assertSame(['give', 'login'], json_decode($exp->design, true)['touches']);
        $this->assertEquals(3.0, json_decode($exp->design, true)['expected_points']);
        [, $out] = $this->ideaCommand('show', $best);
        $this->assertStringContainsString('The experiment it became: give-photos-skip, draft', $out);

        [$code, $out] = $this->ideaCommand('reopen', $best);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Idea $best is waiting again.", $out);
        $this->assertSame('open', $this->row($best)->status);

        [$code, $out] = $this->ideaCommand('reject', $best);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Idea $best is set aside.", $out);
        $this->assertSame('rejected', $this->row($best)->status);

        [$code, $out] = $this->ideaCommand('reopen', $best);
        $this->assertSame(0, $code);
        $this->assertSame('open', $this->row($best)->status);
    }

    public function test_the_idea_command_says_in_plain_words_what_it_cannot_do(): void
    {
        $idea = $this->idea(['status' => 'done']);

        foreach ([
            [['schedule', null, ['--name' => 'x']], 'Give the idea number.'],
            [['show', 999999, []], 'There is no idea 999999.'],
            [['schedule', $idea, []], 'Give the name of the switch for the experiment.'],
            [['schedule', $idea, ['--name' => 'x']], 'That idea is not open: it is already scheduled, running or finished'],
            [['reject', $idea, []], 'Only a waiting idea can be set aside. That idea is tried.'],
            [['mystery', null, []], 'action must be list, show, schedule, reject or reopen'],
        ] as [[$action, $id, $options], $message]) {
            [$code, $out] = $this->ideaCommand($action, $id, $options);
            $this->assertSame(1, $code, $action);
            $this->assertStringContainsString($message, $out, $action);
        }
    }

    public function test_the_idea_command_lists_nothing_politely(): void
    {
        [$code, $out] = $this->ideaCommand('list');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No ideas.', $out);
    }
}
