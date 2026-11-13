<?php

namespace App\Console\Commands\Merge;

use App\Services\Merge\MergeOfferRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Finishes merge offers whose Accept was refused while the /merge page sent the offer id in a
 * form the Go API could not read (April to November 2026).
 *
 * Input: a file with one line per offer, "offer id, keep user id, other user id", where "keep" is
 * the account the member chose to keep. scripts/merge-accepts-from-loki.mjs writes it from the
 * production logs. Blank lines and lines starting with # are ignored.
 *
 * Dry run unless --apply. With --apply each offer that passes the checks below is sent to the Go
 * API's merge endpoint as the Accept the member made, so the merge is the one the /merge page
 * would have done: Go's MergeUsersTx, the only account merge (docs/developers/reference/
 * account-merge.md). The PHP User::merge is deliberately not used; it is a different copy.
 *
 * Checks, each reported as a refusal with its reason, after which the run carries on: the line
 * is well formed, the offer exists, nobody has answered it, the two accounts are the offer's
 * accounts (either order), both still exist, neither is a TrashNothing account and neither has
 * opted out of merging. The Go API checks them again. Once an offer is accepted a second run
 * reports "already accepted" and does nothing.
 */
#[AsCommand(name: 'merges:complete-accepted')]
class CompleteAcceptedMergesCommand extends Command
{
    protected $signature = 'merges:complete-accepted
                            {--file= : File of lines "offer id, keep user id, other user id"}
                            {--apply : Do the merges (default is a dry run)}';

    protected $description = 'Complete merge offers the member accepted while the Accept was being refused';

    public function handle(MergeOfferRules $rules): int
    {
        $file = $this->option('file');
        $apply = (bool) $this->option('apply');

        if (!$file || !is_readable($file)) {
            $this->error('--file must name a readable file.');

            return self::FAILURE;
        }

        $this->info($apply ? 'APPLYING' : 'DRY RUN - use --apply to merge');

        $counts = ['merged' => 0, 'would merge' => 0, 'refused' => 0, 'failed' => 0];

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $outcome = $this->handleLine($rules, $number + 1, $line, $apply);
            $counts[$outcome]++;
        }

        $this->info(sprintf(
            'Done: %d merged, %d would merge, %d refused, %d failed.',
            $counts['merged'],
            $counts['would merge'],
            $counts['refused'],
            $counts['failed']
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function handleLine(MergeOfferRules $rules, int $lineNumber, string $line, bool $apply): string
    {
        $parts = array_map('trim', explode(',', $line));

        if (count($parts) !== 3 || array_filter($parts, fn ($p) => !ctype_digit($p)) !== []) {
            return $this->refuse($lineNumber, 'not "offer id, keep user id, other user id"');
        }

        [$offerId, $keep, $other] = array_map('intval', $parts);

        $offer = DB::table('merges')->where('id', $offerId)->first();

        if (!$offer) {
            return $this->refuse($lineNumber, "offer {$offerId} does not exist");
        }

        if ($offer->accepted !== null) {
            return $this->refuse($lineNumber, "offer {$offerId} was already accepted");
        }

        if ($offer->rejected !== null) {
            return $this->refuse($lineNumber, "offer {$offerId} was rejected");
        }

        $offered = [(int) $offer->user1, (int) $offer->user2];

        if ($keep === $other || !in_array($keep, $offered, true) || !in_array($other, $offered, true)) {
            return $this->refuse($lineNumber, "the accounts are not the ones offered in offer {$offerId}");
        }

        if (!$offer->uid) {
            return $this->refuse($lineNumber, "offer {$offerId} has no link key");
        }

        $refusal = $rules->accountsRefusal($keep, $other);

        if ($refusal !== null) {
            return $this->refuse($lineNumber, "offer {$offerId}: {$refusal}");
        }

        if (!$apply) {
            $this->line("Line {$lineNumber}: would merge account {$other} into {$keep} for offer {$offerId}");

            return 'would merge';
        }

        try {
            $response = Http::asJson()->timeout(120)->post((string) config('freegle.apiv2_merge_url'), [
                'id' => $offerId,
                'uid' => $offer->uid,
                'user1' => $keep,
                'user2' => $other,
                'action' => 'Accept',
            ]);
        } catch (\Throwable $e) {
            $this->error("Line {$lineNumber}: offer {$offerId}: could not reach the API: " . $e->getMessage());

            return 'failed';
        }

        if ($response->successful()) {
            $this->line("Line {$lineNumber}: merged account {$other} into {$keep} for offer {$offerId}");

            return 'merged';
        }

        // The API refusing is an answer, like our own checks: a 5xx is a failure.
        $message = (string) ($response->json('message') ?? $response->json('error') ?? $response->body());
        $message = substr(trim($message), 0, 200);

        if ($response->serverError()) {
            $this->error("Line {$lineNumber}: offer {$offerId}: the API failed ({$response->status()}): {$message}");

            return 'failed';
        }

        return $this->refuse($lineNumber, "offer {$offerId}: the API refused ({$response->status()}): {$message}");
    }

    private function refuse(int $lineNumber, string $reason): string
    {
        $this->warn("Line {$lineNumber}: refused, {$reason}");

        return 'refused';
    }
}
