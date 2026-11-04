<?php

namespace App\Console\Commands\TrashNothing;

use App\Models\Location;
use App\Models\Message;
use App\Support\SubjectParser;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Give every post the TN API ingested the location bracket Freegle builds, "(Area OUTWARD)"
 * from the post's resolved postcode, in place of the bracket TN's title carried (the member's
 * own words for where they are, which can be a whole postcode or a street). Ingestion does
 * this for every post it creates now (GroupPostIngestionService::createMessage); this is the
 * backfill for the posts created before it did. DRY RUN unless --apply. Safe to run again: a
 * subject that already has the right bracket is left alone.
 */
#[AsCommand(name: 'tn:rewrite-subjects')]
class TNRewriteSubjectsCommand extends Command
{
    protected $signature = 'tn:rewrite-subjects
                            {--apply : Write the subjects (default: report what would change)}
                            {--since= : Only posts that arrived on or after this date (default: all TN API posts)}
                            {--show=20 : How many before/after examples to print}';

    protected $description = 'Rebuild the location bracket on subjects of posts ingested from the TN API';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $show  = max(0, (int) $this->option('show'));

        if (!$apply) {
            $this->info('DRY RUN - nothing will be written. Pass --apply to write.');
        }

        $query = Message::query()
            ->where('sourceheader', 'TN-API')
            ->whereNotNull('tnpostid')
            ->orderBy('id');

        if ($this->option('since')) {
            $query->where('arrival', '>=', $this->option('since'));
        }

        $scanned = 0;
        $changed = 0;
        $noLocation = 0;
        $examples = [];

        $query->chunkById(500, function ($messages) use ($apply, $show, &$scanned, &$changed, &$noLocation, &$examples) {
            foreach ($messages as $message) {
                $scanned++;

                $area = $message->locationid ? Location::subjectArea((int) $message->locationid) : null;
                if ($area === null) {
                    $noLocation++;
                }

                $new = SubjectParser::withLocation((string) $message->subject, $area);
                if ($new === (string) $message->subject) {
                    continue;
                }

                $changed++;
                if (count($examples) < $show) {
                    $examples[] = [$message->id, $message->subject, $new];
                }

                if ($apply) {
                    if ($message->suggestedsubject === $message->subject) {
                        $message->suggestedsubject = $new;
                    }
                    $message->subject = $new;
                    $message->save();
                }
            }
        });

        if ($examples) {
            $this->table(['Message', 'Before', 'After'], $examples);
        }

        $verb = $apply ? 'Rewrote' : '[DRY RUN] Would rewrite';
        $this->info("{$verb} {$changed} of {$scanned} TN API post subjects ({$noLocation} with no resolved location, bracket dropped).");

        return Command::SUCCESS;
    }
}
