<?php

namespace App\Console\Commands\Message;

use App\Services\AutoSplit\GeminiJson;
use App\Services\AutoSplit\PhotoSplitter;
use App\Services\AutoSplit\TextSplitter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Run the auto-splitter's text and photo steps over recorded posts and write what it decided, one
 * JSON line per post, without touching the database. This is how the prompts and thresholds were
 * tuned, and how to check a change to either before it ships.
 *
 * The posts file has one {"id","subject","body"} per line; the attachments file one
 * {"id","msgid","externaluid","externalmods","primary"} per line. Photos are fetched through the
 * configured delivery service; --delivery and --uploads point it at another one (production's,
 * for posts recorded there). docs/developers/reference/auto-split.md.
 */
#[AsCommand(name: 'autosplit:backtest')]
class AutoSplitBacktestCommand extends Command
{
    protected $signature = 'autosplit:backtest
        {posts : JSONL file of posts}
        {--attachments= : JSONL file of their photos; without it only the text step runs}
        {--out= : Where to write the results (default: stdout)}
        {--offset=0 : Skip this many posts}
        {--limit=0 : Stop after this many posts (0: all)}
        {--delivery= : Delivery service base URL to fetch photos through}
        {--uploads= : Uploads base URL the delivery service fetches from}';

    protected $description = 'Run the auto-splitter over recorded posts and report its decisions, writing nothing';

    public function handle(TextSplitter $text, PhotoSplitter $photos, GeminiJson $gemini): int
    {
        if (! $gemini->isConfigured()) {
            $this->error('No Gemini key (GOOGLE_GEMINI_API_KEY).');

            return self::FAILURE;
        }
        if ($this->option('delivery')) {
            config(['freegle.delivery.base_url' => $this->option('delivery')]);
        }
        if ($this->option('uploads')) {
            config(['freegle.tus_uploader' => $this->option('uploads')]);
        }

        $attachments = [];
        if ($file = $this->option('attachments')) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $a = (object) json_decode($line, true);
                $attachments[(int) $a->msgid][] = $a;
            }
            foreach ($attachments as &$list) {
                usort($list, fn ($a, $b) => [(int) ($b->primary ?? 0), $a->id] <=> [(int) ($a->primary ?? 0), $b->id]);
            }
            unset($list);
        }

        $out = $this->option('out') ? fopen($this->option('out'), 'w') : STDOUT;
        $offset = max(0, (int) $this->option('offset'));
        $limit = max(0, (int) $this->option('limit'));
        $seen = 0;
        $tally = ['posts' => 0, 'split' => 0, 'retry' => 0, 'photos' => 0, 'whole' => 0, 'crop' => 0, 'shared' => 0, 'tokensin' => 0, 'tokensout' => 0];

        foreach (file($this->argument('posts'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $i => $line) {
            if ($i < $offset) {
                continue;
            }
            if ($limit && $seen >= $limit) {
                break;
            }
            $seen++;
            $post = json_decode($line, true);
            $result = ['id' => $post['id'], 'subject' => $post['subject']];
            $tally['posts']++;

            $decision = $text->split((string) $post['subject'], $post['body'] ?? null);
            if ($decision === null) {
                $tally['retry']++;
                fwrite($out, json_encode($result + ['retry' => 'text'])."\n");

                continue;
            }
            [$in, $o] = $text->usage();
            $tally['tokensin'] += $in;
            $tally['tokensout'] += $o;
            $result += $decision;

            if ($decision['split']) {
                $tally['split']++;
                $list = array_slice(array_values(array_filter($attachments[(int) $post['id']] ?? [], [PhotoSplitter::class, 'eligible'])), 0, (int) config('freegle.autosplit.max_photos', 8));
                if ($list) {
                    $p = $photos->split($decision['items'], $list);
                    if ($p === null) {
                        $tally['retry']++;
                        $result['retry'] = 'photos';
                    } else {
                        [$in, $o] = $photos->usage();
                        $tally['tokensin'] += $in;
                        $tally['tokensout'] += $o;
                        foreach ($p['photos'] as &$ph) {
                            $tally['photos']++;
                            $tally[$ph['action']]++;
                            $src = collect($list)->firstWhere('id', $ph['attachmentid']);
                            $ph['externaluid'] = $src->externaluid ?? null;
                            $ph['externalmods'] = $src->externalmods ?? null;
                        }
                        unset($ph);
                        $result['photos'] = $p['photos'];
                    }
                }
            }

            fwrite($out, json_encode($result)."\n");
        }

        $this->info(json_encode($tally));

        return self::SUCCESS;
    }
}
