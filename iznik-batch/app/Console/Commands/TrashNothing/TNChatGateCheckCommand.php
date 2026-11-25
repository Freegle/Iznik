<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\Mail\Incoming\IncomingArchiveReader;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\TrashNothing\Verify\ChatEmailInventory;
use App\Services\TrashNothing\Verify\TnEmailRoutingGate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runs the gate's TN chat predicate over real mail, to show before the cutover that it takes
 * exactly TN members' chat mail and nothing else. See plans/2026-11-12-tn-api-chat-ingestion.md
 * 5.6: a false match is a member's mail the cutover would silently stop routing.
 *
 * Only mail from a trashnothing.com address is read: the predicate requires a From at
 * user.trashnothing.com, so nothing else can match, and the rest of the mail is most of it.
 *
 *   --source=db       messages on yesterday: about two days of chat mail (each linked to the chat
 *                     message it became) and 30 of group posts. Mail the email path dropped left no
 *                     row, so only what was routed is seen.
 *   --source=archive  the incoming archive on batch-prod: 48 hours of everything, with outcomes.
 *
 * A false match is mail the predicate takes that the email path did not make a chat message of:
 * a group post, or anything else it routed elsewhere. It fails the run. Read-only, and prints
 * message ids, kinds and outcomes, never addresses or text.
 */
#[AsCommand(name: 'tn:chat-gate-check')]
class TNChatGateCheckCommand extends Command
{
    private const LISTED = 50;

    protected $signature = 'tn:chat-gate-check
                            {--source=db : "db" (messages, on yesterday) or "archive" (the incoming archive, on batch-prod)}
                            {--date-min= : Start (ISO-8601, UTC). Default: two days before the end}
                            {--date-max= : End (ISO-8601, UTC). Default: the newest mail}';

    protected $description = 'Check that the TN chat routing gate matches exactly TN members\' chat mail, on real mail.';

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var array<string, list<array>> */
    private array $listed = [];

    public function handle(TnEmailRoutingGate $gate, MailParserService $parser, IncomingArchiveReader $archive, ChatEmailInventory $inventory): int
    {
        $source = (string) $this->option('source');
        if (!in_array($source, ['db', 'archive'], true)) {
            $this->error('--source must be "db" or "archive".');

            return Command::FAILURE;
        }

        [$from, $to] = $this->window($source);
        $this->info("TN chat gate check, {$source}, {$from->toIso8601ZuluString()} to {$to->toIso8601ZuluString()}");

        if ($source === 'db') {
            $this->fromDatabase($gate, $parser, $from, $to);
        } else {
            if (!$archive->exists()) {
                $this->error('The incoming archive is missing.');

                return Command::FAILURE;
            }
            $this->fromArchive($gate, $archive, $from, $to);
        }

        $this->report();

        // No chat mail seen is not a pass: the gate was never tried on any. Most often the window is
        // outside the two days of chat mail a snapshot keeps (its group posts are kept for 30).
        $chat = array_sum($this->counts['matched'] ?? []) + array_sum($this->counts['matched_dropped'] ?? [])
            + array_sum($this->counts['left_to_email'] ?? []) + array_sum($this->counts['false_match'] ?? []);
        if ($chat === 0) {
            $range = $source === 'db' ? $inventory->databaseChatMailRange() : null;
            $this->error('No TN chat mail in this window, so the gate was not tried on any.'
                . ($range !== null ? " The database keeps chat mail from {$range[0]} to {$range[1]} (UTC)." : ''));

            return Command::FAILURE;
        }

        return ($this->listed['false_match'] ?? []) === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function fromDatabase(TnEmailRoutingGate $gate, MailParserService $parser, CarbonImmutable $from, CarbonImmutable $to): void
    {
        // One raw mail can be linked to more than one chat message, and its rows can straddle chunks.
        $seen = [];
        DB::table('messages as m')
            ->leftJoin('chat_messages_byemail as cme', 'cme.msgid', '=', 'm.id')
            ->whereBetween('m.arrival', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->where('m.fromaddr', 'LIKE', '%trashnothing.com')
            ->where('m.message', '!=', '')
            ->select('m.id', 'm.message', 'm.envelopefrom', 'm.envelopeto', 'cme.chatmsgid')
            ->orderBy('m.id')
            ->chunk(500, function ($rows) use ($gate, $parser, &$seen) {
                foreach ($rows as $row) {
                    if (isset($seen[$row->id])) {
                        continue;
                    }
                    $seen[$row->id] = true;

                    try {
                        $email = $parser->parse((string) $row->message, (string) $row->envelopefrom, (string) $row->envelopeto);
                    } catch (\Throwable) {
                        $this->bump('unparseable', 'all');
                        continue;
                    }

                    $this->classify($gate, $email, $row->chatmsgid !== null, ['message_id' => (int) $row->id]);
                }
            });
    }

    private function fromArchive(TnEmailRoutingGate $gate, IncomingArchiveReader $archive, CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach ($archive->recordsBetween($from, $to) as [$path, $record]) {
            $raw = $archive->rawEmail($record);
            if ($raw === null || stripos($raw, 'trashnothing.com') === false) {
                continue;
            }

            try {
                $email = $archive->parse($raw, $record);
            } catch (\Throwable) {
                $this->bump('unparseable', 'all');
                continue;
            }

            if (!str_contains(strtolower((string) $email->fromAddress), 'trashnothing.com')) {
                continue;
            }

            $outcome = (string) ($record['routing_outcome'] ?? 'unknown');
            $madeChat = in_array($outcome, ['ToUser', 'ToVolunteers'], true);
            $this->classify($gate, $email, $madeChat, ['file' => basename($path), 'outcome' => $outcome]);
        }
    }

    /**
     * @param  bool  $madeChat  Whether the email path made a chat message of it.
     */
    private function classify(TnEmailRoutingGate $gate, ParsedEmail $email, bool $madeChat, array $ids): void
    {
        $kind = $gate->trashNothingChatKind($email);
        $post = $gate->isTrashNothingGroupPost($email);
        $memberMail = str_ends_with(strtolower(trim((string) $email->fromAddress)), '@user.trashnothing.com');

        if ($kind !== null && $post) {
            $this->bump('false_match', 'post_and_chat');
            $this->listed['false_match'][] = $ids + ['kind' => $kind, 'why' => 'both predicates match'];
        } elseif ($kind !== null && $madeChat) {
            $this->bump('matched', $kind);
        } elseif ($kind !== null) {
            // Routed but not into a chat. On the archive side a drop is not false: the email path
            // drops some chat mail (a reply to a chat since deleted) that the feed still carries.
            if (isset($ids['outcome']) && $ids['outcome'] === 'Dropped') {
                $this->bump('matched_dropped', $kind);
            } else {
                $this->bump('false_match', $kind);
                $this->listed['false_match'][] = $ids + ['kind' => $kind, 'why' => 'the email path made no chat message of it'];
            }
        } elseif ($madeChat) {
            // TN chat the gate leaves on email: auto-replies, TN's notices, a member writing from
            // an address not at user.trashnothing.com.
            $why = $memberMail ? 'tn-member' : 'not-a-member-address';
            $this->bump('left_to_email', $why);
            if ($memberMail) {
                $this->listed['left_to_email'][] = $ids + ['why' => $this->leftWhy($email)];
            }
        } else {
            $this->bump('other', $post ? 'tn_group_post' : 'not_chat');
        }
    }

    /** Why a TN member's chat mail is left to email, from what the gate looks at. */
    private function leftWhy(ParsedEmail $email): string
    {
        return match (true) {
            $email->isAutoSubmitted() => 'auto-submitted',
            $email->isAutoReply() => 'auto-reply pattern',
            $email->isBounce() => 'bounce',
            !$email->isFromTrashNothing() => 'envelope not TN',
            default => 'address not one the feed carries',
        };
    }

    private function bump(string $bucket, string $key): void
    {
        $this->counts[$bucket][$key] = ($this->counts[$bucket][$key] ?? 0) + 1;
    }

    private function report(): void
    {
        $rows = [];
        foreach ($this->counts as $bucket => $keys) {
            ksort($keys);
            foreach ($keys as $key => $n) {
                $rows[] = [$bucket, $key, $n];
            }
        }
        $this->table(['bucket', 'kind or reason', 'mails'], $rows);

        $falseMatches = $this->listed['false_match'] ?? [];
        $this->line('');
        $this->line($falseMatches === []
            ? '[ok]   No mail matched that the email path did not make a chat message of'
            : '[FAIL] Matched, but not chat the email path wrote: ' . count($falseMatches));
        foreach (array_slice($falseMatches, 0, self::LISTED) as $item) {
            $this->line('  ' . json_encode($item));
        }

        $left = $this->listed['left_to_email'] ?? [];
        if ($left !== []) {
            $this->line('');
            $this->line("[note] TN members' chat the gate leaves to email: " . count($left));
            foreach (array_slice($left, 0, self::LISTED) as $item) {
                $this->line('  ' . json_encode($item));
            }
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(string $source): array
    {
        $end = $source === 'db'
            ? CarbonImmutable::parse((string) (DB::table('messages')->max('arrival') ?? 'now'), 'UTC')
            : CarbonImmutable::now('UTC');

        $to = $this->option('date-max') ? CarbonImmutable::parse((string) $this->option('date-max'), 'UTC') : $end;
        $from = $this->option('date-min') ? CarbonImmutable::parse((string) $this->option('date-min'), 'UTC') : $to->subDays(2);

        return [$from, $to];
    }
}
