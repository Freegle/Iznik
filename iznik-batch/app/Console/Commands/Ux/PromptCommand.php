<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\PlainWords;
use App\Services\Ux\UxPromptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The questions microvolunteers are asked about Freegle: a survey of its parts shown four at a
 * time, awareness questions ("Do you know how to promise an item to someone in a chat?") and
 * free-text questions that collect problems and what members value. A prompt is a row, so a new
 * question needs no code or schema change. Each question fits one phone screen, so the words have
 * limits (UxPromptService). docs/developers/reference/ux-member-surveys.md.
 *
 *   ux:prompt list
 *   ux:prompt survey                      (open the standard survey of Freegle's parts)
 *   ux:prompt awareness --flow=chat_promise --action="promise an item to someone in a chat"
 *   ux:prompt awareness --flow=give --action="pay to put your offer first" --decoy --reveal="Freegle is free, and nobody can pay to have their offer shown first."
 *       (asks "Do you know how to <action>?" and shows nothing afterwards: it is a question, not a tip. A decoy asks about something Freegle does not do, so its right answer is no, and its --reveal gives the reason only, because the card adds "Freegle does not do this."; make about one in five a decoy. Otherwise --reveal and --link are optional.)
 *   ux:prompt open --preset=problems|values|improve   (a free-text question, at most 300 answers)
 *   ux:prompt analyse <id>                (code an open prompt's answers into grouped problems, values and suggestions; needs UX_PRIVACY_CLEARED)
 *   ux:prompt regroup <id>                (re-assign the groups of an analysed prompt's problems, after the group list changed)
 *   ux:prompt followup <id>               (ask other members which of the top 8 problems found in prompt <id> is the biggest for them, three at a time; needs 100 analysed answers, lists the titles with their reading grades, then needs --confirm)
 *   ux:prompt close <id>
 */
#[AsCommand(name: 'ux:prompt')]
class PromptCommand extends Command
{
    protected $signature = 'ux:prompt
        {action : list, survey, awareness, open, analyse, regroup, followup or close}
        {id? : close, analyse, regroup, followup: the prompt id}
        {--preset= : open: problems, values or improve}
        {--confirm : followup: the titles have been read: none names a person or place, and each is one short sentence in plain words}
        {--skip= : followup: comma separated ids of problems to leave out, for example a title that is too hard to read or that names a person}
        {--force : analyse: replace the themes of a prompt that was analysed before}
        {--flow= : awareness: the funnel or feature the question is about}
        {--action= : awareness: what is asked about, in plain words, at most 50 characters; the whole question is at most 14 words, such as "promise an item to someone in a chat"}
        {--reveal= : awareness: one or two sentences shown after the answer, at most 160 characters (optional; required with --decoy, where it gives the reason only)}
        {--link= : awareness: a path on this site shown after the answer, such as /settings}
        {--decoy : awareness: the question is about something Freegle does not do (the right answer is no)}
        {--max= : how many answers to collect before the prompt closes itself (default 200; an open prompt defaults to and is capped at 300)}';

    protected $description = 'List, open, analyse or close the survey, awareness and open questions microvolunteers are asked';

    public function handle(UxPromptService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'survey' => $this->opened($service->openSurvey($this->max(200))),
                'awareness' => $this->opened($service->openAwareness(
                    (string) $this->option('flow'), (string) $this->option('action'), $this->option('reveal') ?: null,
                    $this->option('link') ?: null, (bool) $this->option('decoy'), $this->max(200))),
                'open' => $this->opened($service->openOpen((string) $this->option('preset'), $this->max(UxPromptService::OPEN_MAX))),
                'analyse' => $this->analyse($service),
                'regroup' => $this->regroup($service),
                'followup' => $this->opened($service->followup($this->needId(), $this->max(200), (bool) $this->option('confirm'), $this->skipIds())),
                'close' => $this->close($service),
                default => $this->bad('action must be list, survey, awareness, open, analyse, regroup, followup or close'),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(): int
    {
        $rows = DB::table('ux_prompts')->orderByDesc('id')->limit(50)->get();
        if ($rows->isEmpty()) {
            $this->info('No prompts.');

            return self::SUCCESS;
        }
        $this->table(['id', 'kind', 'funnel', 'status', 'answers', 'question'], $rows->map(fn ($r) => [
            $r->id, $r->kind, $r->flow ?? '', $r->status,
            DB::table('ux_prompt_responses')->where('prompt_id', $r->id)->count(),
            mb_substr($r->question, 0, 60),
        ])->all());

        return self::SUCCESS;
    }

    private function max(int $default): int
    {
        return $this->option('max') !== null && $this->option('max') !== '' ? (int) $this->option('max') : $default;
    }

    private function needId(): int
    {
        $id = (int) $this->argument('id');
        if ($id <= 0) {
            throw new \RuntimeException('Give the prompt id.');
        }

        return $id;
    }

    /** @return int[] the ids named in --skip */
    private function skipIds(): array
    {
        $raw = trim((string) $this->option('skip'));
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (! ctype_digit($part) || (int) $part <= 0) {
                throw new \RuntimeException("--skip takes problem ids separated by commas, not \"$part\".");
            }
            $ids[] = (int) $part;
        }

        return $ids;
    }

    private function analyse(UxPromptService $service): int
    {
        $id = $this->needId();
        $c = $service->analyse($id, (bool) $this->option('force'));
        $this->info(sprintf('Analysed %d answers: %d problems, %d values, %d suggestions, %d unclear.',
            $c['answers'], $c['problems'], $c['values'], $c['solutions'], $c['unclear']));

        // Each problem with its reading grade, so a person can check the words members will read.
        $starred = false;
        $problems = DB::table('ux_problems')->where('prompt_id', $id)->where('kind', 'problem')
            ->orderByDesc('mentions')->orderBy('id')->get(['id', 'title', 'evidence']);
        foreach ($problems as $p) {
            $grade = json_decode((string) $p->evidence, true)['grade'] ?? round(PlainWords::grade($p->title), 2);
            $hard = $grade > UxPromptService::TITLE_MAX_GRADE;
            $starred = $starred || $hard;
            $this->line(sprintf('  [%d] grade %s%s %s', $p->id, number_format((float) $grade, 1, '.', ''), $hard ? ' *' : '', $p->title));
        }
        if ($starred) {
            $this->line('A * marks a grade above '.UxPromptService::TITLE_MAX_GRADE.'. Check that its words are plain.');
        }
        if ($c['suggestions_dropped'] > 0) {
            $this->line(sprintf('%d suggestion(s) were not stored because their problem was not filed (fewer than two members raised it, or no quote could be checked).', $c['suggestions_dropped']));
        }

        return self::SUCCESS;
    }

    private function regroup(UxPromptService $service): int
    {
        $r = $service->regroup($this->needId());
        $this->info(sprintf('Regrouped: %d of %d problem(s) changed group.', $r['changed'], $r['problems']));

        return self::SUCCESS;
    }

    private function opened(int $id): int
    {
        $this->info("Opened prompt $id.");

        return self::SUCCESS;
    }

    private function close(UxPromptService $service): int
    {
        $id = $this->needId();
        $service->close($id);
        $this->info("Closed prompt $id.");

        return self::SUCCESS;
    }

    private function bad(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
