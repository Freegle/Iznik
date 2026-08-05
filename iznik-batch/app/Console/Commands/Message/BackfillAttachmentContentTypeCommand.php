<?php

namespace App\Console\Commands\Message;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill messages_attachments.contenttype for rows the API created without it.
 *
 * doCreate omitted contenttype for imgtype=Message on the assumption that the
 * column did not matter there, so the vast majority of rows carry NULL or ''.
 * The API now always writes it, but that only fixes rows created from here on -
 * everything already stored still has nothing to serve a Content-Type from.
 *
 * Scope is deliberately narrow: only rows with an externaluid, i.e. uploads that
 * went through the tus/delivery path the API controls, and which that path
 * produces as JPEG. Legacy rows holding inline `data` blobs are left alone
 * because their real type is not knowable from the row, and guessing would
 * replace "no information" with "wrong information".
 *
 * Chunked and resumable: it walks by ascending id and only ever narrows the
 * candidate set, so it is safe to stop it and run it again, and safe to run in
 * bounded slices with --limit against a table of this size.
 */
class BackfillAttachmentContentTypeCommand extends Command
{
    protected $signature = 'messages:backfill-attachment-contenttype
                            {--value=image/jpeg : contenttype to write}
                            {--chunk=1000 : Rows per UPDATE}
                            {--limit=0 : Stop after this many rows (0 = no limit)}
                            {--include-legacy : Also fill rows with no externaluid (inline data blobs)}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill the empty messages_attachments.contenttype left by the old image-create path';

    public function handle(): int
    {
        $value = (string) $this->option('value');
        if (trim($value) === '') {
            $this->error('--value cannot be empty.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $includeLegacy = (bool) $this->option('include-legacy');

        $this->info(sprintf(
            '%s contenttype=%s on messages_attachments (%s)%s',
            $dryRun ? 'Would set' : 'Setting',
            $value,
            $includeLegacy ? 'all rows missing it' : 'rows with an externaluid only',
            $limit > 0 ? sprintf(', stopping after %d rows', $limit) : '',
        ));

        $updated = 0;
        $lastId = 0;

        while (true) {
            $batch = max(1, $limit > 0 ? min($chunk, $limit - $updated) : $chunk);

            // Select ids first, then update by id. Doing it in one statement with a
            // LIMIT would still scan from the start of the table each pass; carrying
            // the high-water mark forward keeps every pass bounded.
            $ids = $this->candidates($includeLegacy)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $lastId = (int) $ids->last();

            if (! $dryRun) {
                DB::table('messages_attachments')->whereIn('id', $ids)->update(['contenttype' => $value]);
            }

            $updated += $ids->count();
            $this->output->write('.');

            if ($limit > 0 && $updated >= $limit) {
                break;
            }
        }

        $this->newLine();
        $this->info(sprintf('%d row(s) %s.', $updated, $dryRun ? 'would be filled' : 'filled'));

        if (! $dryRun && $updated > 0) {
            Log::info('messages:backfill-attachment-contenttype', [
                'value' => $value,
                'updated' => $updated,
                'include_legacy' => $includeLegacy,
                'last_id' => $lastId,
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Rows still missing a usable contenttype.
     */
    private function candidates(bool $includeLegacy): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('messages_attachments')
            ->select('id')
            ->where(function ($w) {
                $w->whereNull('contenttype')->orWhere('contenttype', '');
            });

        if (! $includeLegacy) {
            $q->whereNotNull('externaluid')->where('externaluid', '!=', '');
        }

        return $q;
    }
}
