<?php

namespace App\Console\Commands\Promise;

use App\Services\Promise\DatasetExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
        $negativesPerRoom = $this->option('negatives-per-room') ? (int)$this->option('negatives-per-room') : null;
        $outFile = $this->option('out');

        $this->info("Extracting promise dataset since {$since}...");

        // Ensure output directory exists
        $outDir = dirname($outFile);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        // Query User2User chat rooms
        $query = DB::table('chat_rooms')
            ->where('chattype', 'User2User')
            ->where('created', '>=', $since)
            ->orderByRaw('RAND()');

        if ($maxRooms) {
            $query->limit($maxRooms);
        }

        $rooms = $query->get(['id', 'user1', 'user2', 'created']);

        $this->info("Found {$rooms->count()} rooms to process");

        // Open CSV file
        $fp = fopen($outFile, 'w');
        if (!$fp) {
            $this->error("Failed to open output file: {$outFile}");
            return 1;
        }

        // Write BOM-free UTF-8 header. escape: '' = RFC-4180 quoting (double the quotes,
        // no backslash escaping) so spans full of backslashes (\u.. artefacts) and quotes
        // round-trip correctly with fgetcsv — otherwise rows merge silently on read.
        fputcsv($fp, ['room_id', 'post_type', 'end_turn', 'promise_turn', 'label', 'span'], escape: '');

        $totalRows = 0;
        $totalPositives = 0;
        $successCount = 0;
        $skipCount = 0;

        foreach ($rooms as $room) {
            // Get all messages in this room, ordered by date
            $messages = DB::table('chat_messages')
                ->where('chatid', $room->id)
                ->orderBy('date', 'asc')
                ->get(['userid', 'type', 'message', 'refmsgid']);

            if ($messages->isEmpty()) {
                $skipCount++;
                continue;
            }

            // Determine post_type and roles
            $postType = null;
            $postOwnerId = null;

            // Find the earliest refmsgid to locate the originating post
            $earliestRefMsg = $messages
                ->filter(fn($m) => $m->refmsgid !== null)
                ->sortBy(fn($m) => $m->refmsgid)
                ->first();

            if ($earliestRefMsg) {
                $post = DB::table('messages')
                    ->where('id', $earliestRefMsg->refmsgid)
                    ->first(['id', 'type', 'fromuser']);

                if ($post) {
                    $postType = $post->type; // 'Offer' or 'Wanted'
                    $postOwnerId = $post->fromuser;
                }
            }

            if (!$postType || !$postOwnerId) {
                $skipCount++;
                continue;
            }

            // Determine GIVER and TAKER
            // For Offer: owner is GIVER, other user is TAKER
            // For Wanted: owner is TAKER, other user is GIVER
            $otherUserId = ($room->user1 === $postOwnerId) ? $room->user2 : $room->user1;

            $giverRole = $postType === 'Offer' ? $postOwnerId : $otherUserId;
            $takerRole = $postType === 'Offer' ? $otherUserId : $postOwnerId;

            // Map messages to role
            $mappedMessages = [];
            foreach ($messages as $msg) {
                $role = $msg->userid === $giverRole ? 'GIVER' : 'TAKER';
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
        $this->info("  Rooms skipped: {$skipCount}");
        $this->info("  Total rows: {$totalRows}");
        $this->info("  Positive rows: {$totalPositives}");
        $this->info("  % Positive: {$percentPositive}%");
        $this->info("  Output: {$outFile}");

        return 0;
    }
}
