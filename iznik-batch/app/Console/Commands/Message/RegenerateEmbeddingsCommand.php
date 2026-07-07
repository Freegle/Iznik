<?php

namespace App\Console\Commands\Message;

use App\Services\EmbeddingService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-embed every live message from scratch. Used after the embedding text
 * recipe changes (e.g. splitting subject and body into separate vectors) or
 * after a model-version bump.
 *
 * Lives alongside embeddings:generate — the nightly command which only
 * embeds rows that don't yet have one. This command rewrites existing rows.
 */
class RegenerateEmbeddingsCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'embeddings:regenerate
                            {--limit=0 : Max messages to process (0 = unlimited)}
                            {--chunk=100 : Messages per embedder invocation}';

    protected $description = 'Re-embed all live messages (subject + body) from scratch';

    public function handle(EmbeddingService $service): int
    {
        $this->registerShutdownHandlers();

        $limit = (int) $this->option('limit');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $totalCount = 0;
        $lastId = 0;

        while (true) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            $remaining = $limit > 0 ? $limit - $totalCount : PHP_INT_MAX;
            if ($remaining <= 0) {
                break;
            }

            $batchLimit = min($remaining, $chunkSize);

            $messages = DB::select('
                SELECT ms.msgid, m.subject, LEFT(m.textbody, 500) as body
                FROM messages_spatial ms
                JOIN messages m ON m.id = ms.msgid
                WHERE ms.successful = 0
                  AND ms.promised = 0
                  AND ms.msgid > ?
                ORDER BY ms.msgid ASC
                LIMIT ?
            ', [$lastId, $batchLimit]);

            if (empty($messages)) {
                break;
            }

            $this->info(sprintf('Regenerating chunk of %d (%d done)...', count($messages), $totalCount));

            $count = $service->processMessages($messages);
            if ($count === false) {
                $this->error('Embedder failed; aborting.');

                return Command::FAILURE;
            }

            $totalCount += $count;
            $lastId = (int) end($messages)->msgid;
        }

        if ($totalCount === 0) {
            $this->info('No live messages to regenerate.');
        } else {
            $this->info("Regenerated {$totalCount} embeddings.");
            Log::info('Embedding regeneration complete', ['count' => $totalCount]);
        }

        return Command::SUCCESS;
    }
}
