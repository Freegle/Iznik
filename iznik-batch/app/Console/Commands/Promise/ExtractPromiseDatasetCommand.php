<?php

namespace App\Console\Commands\Promise;

use App\Services\Promise\DatasetExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'promise:extract')]
class ExtractPromiseDatasetCommand extends Command
{
    protected $signature = 'promise:extract {--since=} {--max-rooms=} {--window=8} {--tolerance=1} {--negatives-per-room=} {--out=storage/promise/dataset.csv}';

    protected $description = 'Extract promise dataset from chat rooms';

    private DatasetExtractor $extractor;

    public function __construct()
    {
        parent::__construct();
        $this->extractor = new DatasetExtractor();
    }

    public function handle(): int
    {
        // Parse options
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))
            : now()->subMonths(6);

        $maxRooms = $this->option('max-rooms') ? (int)$this->option('max-rooms') : null;
        $window = (int)$this->option('window');
        $tolerance = (int)$this->option('tolerance');
        $negativesPerRoom = $this->option('negatives-per-room') !== null && $this->option('negatives-per-room') !== ''
            ? (int)$this->option('negatives-per-room')
            : null;
        $outFile = $this->option('out');

        if ($window < 1) {
            $this->error('--window must be at least 1');
            return 1;
        }

        if ($tolerance < 0) {
            $this->error('--tolerance must be 0 or more');
            return 1;
        }

        if ($negativesPerRoom !== null && $negativesPerRoom < 1) {
            $this->error('--negatives-per-room must be at least 1 when given');
            return 1;
        }

        $this->info("Extracting promise dataset since {$since}...");

        $outDir = dirname($outFile);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0700, true);
        }

        // Query User2User chat rooms.
        // NB: do NOT use ORDER BY RAND() — it filesorts the entire filtered set and
        // times out the live-DB tunnel on large windows. Use a cheap PK-ordered scan
        // (most-recent first) and bound the sample with --since / --max-rooms.
        $query = DB::table('chat_rooms')
            ->where('chattype', 'User2User')
            ->where('created', '>=', $since)
            ->orderBy('id', 'desc');

        if ($maxRooms) {
            $query->limit($maxRooms);
        }

        $rooms = $query->get(['id', 'user1', 'user2', 'created']);

        $this->info("Found {$rooms->count()} rooms to process");

        // Open CSV file. The dataset is member chat text: keep it readable by the batch user only.
        // The umask makes the file owner-only from the moment it is created, and is put back
        // straight after, whether or not the open worked.
        $previousUmask = umask(0077);
        $fp = fopen($outFile, 'w');
        umask($previousUmask);
        if (!$fp) {
            $this->error("Failed to open output file: {$outFile}");
            return 1;
        }
        chmod($outFile, 0600); // a file left over from an earlier run keeps its old mode otherwise

        // Write BOM-free UTF-8 header. escape: '' = RFC-4180 quoting (double the quotes,
        // no backslash escaping) so spans full of backslashes (\u.. artefacts) and quotes
        // round-trip correctly with fgetcsv — otherwise rows merge silently on read.
        fputcsv($fp, ['room_id', 'post_type', 'end_turn', 'promise_turn', 'label', 'span'], escape: '');

        $totalRows = 0;
        $totalPositives = 0;
        $successCount = 0;
        $skipCount = 0;
        $multiPostSkipCount = 0;

        foreach ($rooms as $room) {
            // Get the messages the members actually saw, ordered by date. Deleted messages,
            // messages rejected in review and messages still held for review are left out.
            $messages = DB::table('chat_messages')
                ->where('chatid', $room->id)
                ->where('deleted', 0)
                ->where('reviewrejected', 0)
                ->where('reviewrequired', 0)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get(['userid', 'type', 'message', 'refmsgid']);

            if ($messages->isEmpty()) {
                $skipCount++;
                continue;
            }

            // Determine post_type and roles
            $postType = null;
            $postOwnerId = null;

            // A User2User room is one per pair of members, so it can hold chats about
            // several posts. Roles and the promise belong to one post, so a room that
            // refers to more than one is skipped.
            $refMsgIds = $messages
                ->pluck('refmsgid')
                ->filter(fn($id) => $id !== null)
                ->unique();

            if ($refMsgIds->count() > 1) {
                $multiPostSkipCount++;
                $skipCount++;
                continue;
            }

            if ($refMsgIds->isNotEmpty()) {
                $post = DB::table('messages')
                    ->where('id', $refMsgIds->first())
                    ->first(['id', 'type', 'fromuser']);

                if ($post) {
                    $postType = $post->type; // 'Offer' or 'Wanted'
                    $postOwnerId = (int)$post->fromuser;
                }
            }

            if (!$postType || !$postOwnerId) {
                $skipCount++;
                continue;
            }

            // The post's owner must be one of the two members, or the roles would be wrong.
            $user1 = (int)$room->user1;
            $user2 = (int)$room->user2;

            if ($postOwnerId !== $user1 && $postOwnerId !== $user2) {
                $skipCount++;
                continue;
            }

            // Determine GIVER and TAKER
            // For Offer: owner is GIVER, other user is TAKER
            // For Wanted: owner is TAKER, other user is GIVER
            $otherUserId = ($user1 === $postOwnerId) ? $user2 : $user1;

            $giverRole = $postType === 'Offer' ? $postOwnerId : $otherUserId;

            // Map messages to role
            $mappedMessages = [];
            foreach ($messages as $msg) {
                $role = (int)$msg->userid === $giverRole ? 'GIVER' : 'TAKER';
                $mappedMessages[] = [
                    'type' => $msg->type,
                    'role' => $role,
                    'text' => $msg->message ?? '',
                ];
            }

            // Extract dataset rows
            $rows = $this->extractor->extractRoom(
                roomId: $room->id,
                postType: $postType,
                messages: $mappedMessages,
                opts: [
                    'window' => $window,
                    'tolerance' => $tolerance,
                    'negativesPerRoom' => $negativesPerRoom,
                ]
            );

            foreach ($rows as $row) {
                fputcsv($fp, $row, escape: '');
                $totalRows++;
                if ($row['label'] === 1) {
                    $totalPositives++;
                }
            }

            $successCount++;
        }

        fclose($fp);

        $percentPositive = $totalRows > 0 ? round(($totalPositives / $totalRows) * 100, 2) : 0;

        $this->info("");
        $this->info("Extraction complete!");
        $this->info("  Rooms processed: {$successCount}");
        $this->info("  Rooms skipped: {$skipCount} (of which {$multiPostSkipCount} refer to more than one post)");
        $this->info("  Total rows: {$totalRows}");
        $this->info("  Positive rows: {$totalPositives}");
        $this->info("  % Positive: {$percentPositive}%");
        $this->info("  Output: {$outFile}");

        return 0;
    }
}
