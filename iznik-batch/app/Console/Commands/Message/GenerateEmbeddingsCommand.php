<?php

namespace App\Console\Commands\Message;

use App\Services\EmbeddingService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly embedding generator — embeds messages that don't yet have a row
 * in messages_embeddings. See RegenerateEmbeddingsCommand for the full
 * rebuild used after recipe or model changes.
 */
class GenerateEmbeddingsCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'embeddings:generate
                            {--backfill : Process all messages without embeddings}
                            {--limit=500 : Maximum messages to process per run}
                            {--chunk=100 : Messages per embedder invocation}';

    protected $description = 'Generate vector embeddings for live messages missing from messages_embeddings';

    public function handle(EmbeddingService $service): int
    {
        $this->registerShutdownHandlers();

        $limit = (int) $this->option('limit');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($this->option('backfill')) {
            $limit = 50000;
        }

        $totalCount = 0;
        $remaining = $limit;

        while ($remaining > 0) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            $batchLimit = min($remaining, $chunkSize);

            // Bulk-clearance messages have many items appended to textbody by
            // buildBulkSummary; use a wider window so more of the catalogue
            // is covered by the embedding. Normal messages keep the 500-char cap.
            //
            // TODO (per-item embeddings, deferred): for very large clearances even
            // 2500 chars truncates the catalogue. The robust follow-up is a
            // SEPARATE, additive `messages_bulk_items_embeddings` table
            // (msgid, bulkitemid, item_embedding, model_version; PK (msgid,bulkitemid),
            // FK bulkitemid -> messages_bulk_items ON DELETE CASCADE) populated by a
            // second loop here (text = item name + description) and queried by the
            // vector search. This is kept separate from messages_embeddings on
            // purpose: that table is msgid-keyed with an ON DUPLICATE KEY upsert, so
            // adding per-item rows there would change the existing (non-bulk) search
            // path. The additive table avoids any change to current search behaviour.
            $messages = DB::select('
                SELECT ms.msgid, m.subject,
                       LEFT(m.textbody,
                            CASE WHEN EXISTS (
                                SELECT 1 FROM messages_bulk_items
                                WHERE msgid = ms.msgid
                            ) THEN 2500 ELSE 500 END
                       ) AS body
                FROM messages_spatial ms
                JOIN messages m ON m.id = ms.msgid
                LEFT JOIN messages_embeddings me ON me.msgid = ms.msgid
                WHERE me.msgid IS NULL
                  AND ms.successful = 0
                  AND ms.promised = 0
                ORDER BY ms.arrival DESC
                LIMIT ?
            ', [$batchLimit]);

            if (empty($messages)) {
                break;
            }

            $this->info(sprintf('Processing chunk of %d messages (%d done so far)...', count($messages), $totalCount));

            $count = $service->processMessages($messages);
            if ($count === false) {
                return Command::FAILURE;
            }

            $totalCount += $count;
            $remaining -= count($messages);
        }

        if ($totalCount === 0) {
            $this->info('No messages need embedding.');
        } else {
            $this->info("Generated {$totalCount} embeddings.");
            Log::info('Embedding generation complete', ['count' => $totalCount]);
        }

        return Command::SUCCESS;
    }
}
