<?php

namespace App\Console\Commands\TrashNothing;

use App\Models\Message;
use App\Models\User;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Give the posts the TN API ingested before it recorded a sender the member's TrashNothing
 * address as their sender, as ingestion does now (GroupPostIngestionService::createMessage):
 * messages.fromaddr and envelopefrom, the same on the post's messages_history rows, and the
 * From line of the stored message. Without a sender ModTools shows these posts as "via
 * Email", and TN's partner key cannot act on them as their owner. DRY RUN unless --apply.
 * Safe to run again: only posts with no sender are touched.
 */
#[AsCommand(name: 'tn:backfill-post-senders')]
class TNBackfillPostSendersCommand extends Command
{
    /** The day the TN API started ingesting posts; no TN-API post is older. */
    private const SWITCHOVER = '2026-11-03';

    protected $signature = 'tn:backfill-post-senders
                            {--apply : Write the senders (default: report what would change)}
                            {--since=' . self::SWITCHOVER . ' : Only posts that arrived on or after this date}';

    protected $description = 'Record the member\'s TrashNothing address as the sender of posts ingested from the TN API';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $since = (string) $this->option('since');

        if (!$apply) {
            $this->info('DRY RUN - nothing will be written. Pass --apply to write.');
        }

        // Found on the arrival index, so the scan below walks only the ids since then rather
        // than the whole table, which has no index on sourceheader.
        $firstId = Message::where('arrival', '>=', $since)->min('id');
        if ($firstId === null) {
            $this->info("No posts since {$since}.");

            return Command::SUCCESS;
        }

        $addresses = [];
        $scanned = 0;
        $set = 0;
        $noAddress = 0;

        Message::query()
            ->select(['id', 'fromuser', 'fromname', 'message'])
            ->where('id', '>=', $firstId)
            ->where('arrival', '>=', $since)
            ->where('sourceheader', GroupPostIngestionService::SOURCEHEADER)
            ->whereNull('fromaddr')
            ->chunkById(500, function ($messages) use ($apply, &$addresses, &$scanned, &$set, &$noAddress) {
                foreach ($messages as $message) {
                    $scanned++;

                    $userId = (int) $message->fromuser;
                    if (!array_key_exists($userId, $addresses)) {
                        $addresses[$userId] = User::find($userId)?->tnAddress();
                    }

                    $address = $addresses[$userId];
                    if ($address === null) {
                        $noAddress++;
                        continue;
                    }

                    $set++;
                    if ($apply) {
                        $this->setSender($message, $address);
                    }
                }
            });

        $verb = $apply ? 'Set' : '[DRY RUN] Would set';
        $this->info("{$verb} the sender on {$set} of {$scanned} TN API posts with none ({$noAddress} whose member has no TN address, left alone).");

        return Command::SUCCESS;
    }

    private function setSender(Message $message, string $address): void
    {
        $update = ['fromaddr' => $address, 'envelopefrom' => $address];

        $blob = $message->message;
        if ($blob !== null && str_starts_with($blob, 'From: ')) {
            $update['message'] = preg_replace_callback(
                '/\AFrom: [^\r\n]*/',
                fn () => GroupPostIngestionService::fromHeader($message->fromname, $address),
                $blob,
            );
        }

        DB::table('messages')->where('id', $message->id)->update($update);
        DB::table('messages_history')
            ->where('msgid', $message->id)
            ->whereNull('fromaddr')
            ->update(['fromaddr' => $address, 'envelopefrom' => $address]);
    }
}
