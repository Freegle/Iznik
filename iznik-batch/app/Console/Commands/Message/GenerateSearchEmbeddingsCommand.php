<?php

namespace App\Console\Commands\Message;

use App\Services\EmbeddingService;
use App\Traits\GracefulShutdown;
use App\Traits\SingleInstanceLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Embeds saved search terms that have no row in users_searches_embeddings, or
 * whose row was written by an older model.
 *
 * Sibling of embeddings:generate. Kept separate rather than folded in because
 * the two read different tables and a saved search is created far less often
 * than a post, so this can run at a much lower cadence.
 *
 * Terms are embedded as DOCUMENTS, the same way post subjects are, so their
 * cosines sit on the same scale and the matched-posts threshold means the same
 * thing against both. See EmbeddingService::processSearches.
 */
class GenerateSearchEmbeddingsCommand extends Command
{
    use GracefulShutdown;
    use SingleInstanceLock;

    protected $signature = 'embeddings:searches
                            {--backfill : Process every saved search without a current embedding}
                            {--limit=500 : Maximum searches to process per run}
                            {--chunk=100 : Searches per embedder invocation}';

    protected $description = 'Generate vector embeddings for saved search terms';

    public function handle(EmbeddingService $service): int
    {
        // Same spawn-an-embedder cost and the same overlap hole as
        // embeddings:generate (see SingleInstanceLock). TTL sized for a
        // --backfill run, which shares the lock because it does the same work.
        return $this->runSingleInstance('embeddings:searches:run', 3600, fn (): int => $this->runGuarded($service));
    }

    private function runGuarded(EmbeddingService $service): int
    {
        $this->registerShutdownHandlers();

        $limit = $this->option('backfill') ? 200000 : (int) $this->option('limit');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $total = 0;
        $remaining = $limit;

        while ($remaining > 0) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            // Missing entirely, or embedded by a model we no longer use - mixing
            // scales silently would be worse than not matching at all.
            //
            // Newest first, and only the window the consumers read: both the
            // scout signal and /searchmatches filter on date >= 6 months, so an
            // older search would be embedded for nobody, and while a backlog is
            // draining the searches most likely to still be live intent should
            // be matchable first, not last.
            $searches = DB::table('users_searches as s')
                ->leftJoin('users_searches_embeddings as e', 'e.searchid', '=', 's.id')
                ->where('s.deleted', 0)
                ->whereNotNull('s.term')
                ->where('s.term', '<>', '')
                ->where('s.date', '>=', now()->subMonths((int) config('freegle.firstreply.search_max_age_months', 6)))
                ->where(fn ($q) => $q->whereNull('e.searchid')
                    ->orWhere('e.model_version', '<>', EmbeddingService::MODEL_VERSION))
                ->select('s.id', 's.term')
                ->orderByDesc('s.id')
                ->limit(min($chunkSize, $remaining))
                ->get();

            if ($searches->isEmpty()) {
                break;
            }

            $written = $service->processSearches($searches);

            if ($written === false) {
                Log::warning('embeddings:searches - embedder failed, stopping this run');
                $this->error('Embedder failed.');

                return self::FAILURE;
            }

            $total += $written;
            $remaining -= $searches->count();
        }

        $this->info("Embedded {$total} saved search term(s).");

        return self::SUCCESS;
    }
}
