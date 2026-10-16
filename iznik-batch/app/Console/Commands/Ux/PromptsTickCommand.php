<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxPromptService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Hourly upkeep of the member surveys: close the prompts that have collected their answers, turn
 * the follow-up picks into a weight on each problem, and blank member words 90 days after they
 * were written (the answers' text and comments, and the quotes kept in the problems).
 * docs/developers/reference/ux-member-surveys.md.
 */
#[AsCommand(name: 'ux:prompts-tick')]
class PromptsTickCommand extends Command
{
    protected $signature = 'ux:prompts-tick';

    protected $description = 'Close full survey prompts, weigh the follow-up picks and blank old member words';

    public function handle(UxPromptService $prompts): int
    {
        $now = Carbon::now('UTC');
        $days = UxPromptService::KEEP_ANSWERS_DAYS;

        $full = $prompts->closeFull();
        if ($full) {
            $this->info("Closed $full prompt(s) that had collected their answers.");
        }
        $weighed = $prompts->weighFollowups();
        if ($weighed) {
            $this->info("Weighed $weighed problem(s) from the follow-up rankings.");
        }
        $blanked = $prompts->blankOldAnswers($now, $days);
        if ($blanked) {
            $this->info("Removed the words of $blanked answer(s) written over $days days ago.");
        }
        $quotes = $prompts->blankOldQuotes($now, $days);
        if ($quotes) {
            $this->info("Removed the quoted words from $quotes theme(s) whose answers are over $days days old or gone.");
        }

        return self::SUCCESS;
    }
}
