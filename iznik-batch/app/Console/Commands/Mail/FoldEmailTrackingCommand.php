<?php

namespace App\Console\Commands\Mail;

use App\Services\Mail\EmailTrackingFoldService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Apply the email tracking journal (image loads and pixel opens appended by the Go delivery
 * handlers) to email_tracking and email_tracking_images. See EmailTrackingFoldService for why the
 * journal exists, what reads the result and the rules the fold keeps.
 *
 * Scheduled once a night in the overnight trough. It then runs mail:digest:mark-seen over a window
 * that covers every open it just applied: that command normally runs hourly over the last three
 * hours of opened_at, which would never see an open whose event is now many hours old.
 */
class FoldEmailTrackingCommand extends Command
{
    protected $signature = 'mail:tracking:fold
        {--chunk=5000 : Journal rows applied per transaction}
        {--pause-ms=100 : Pause between chunks, to leave room for the other cluster nodes to apply them}
        {--max-seen-hours=72 : Cap on the look-back handed to mail:digest:mark-seen}';

    protected $description = 'Fold the email tracking journal into email_tracking and email_tracking_images';

    /** A journal row older than this at the end of a run means the fold is not keeping up. */
    private const STALE_HOURS = 36;

    public function handle(EmailTrackingFoldService $service): int
    {
        $stats = $service->fold(max(100, (int) $this->option('chunk')), null, max(0, (int) $this->option('pause-ms')));

        $this->info(sprintf(
            'mail:tracking:fold: %d events in %d chunks: %d emails opened, %d scroll depths, %d image rows, %d for unknown emails dropped.',
            $stats['events'],
            $stats['chunks'],
            $stats['opened'],
            $stats['scroll_updates'],
            $stats['images'],
            $stats['unresolved']
        ));

        if ($stats['opened'] > 0 && $stats['oldest_event'] !== null) {
            // Whole hours back to the oldest event just applied, plus one for the overlap the
            // hourly schedule also leaves.
            $hours = (int) ceil(Carbon::parse($stats['oldest_event'])->diffInMinutes(now()) / 60) + 1;
            $hours = max(3, min($hours, (int) $this->option('max-seen-hours')));

            $this->call('mail:digest:mark-seen', ['--hours' => $hours, '--limit' => 200000]);
        }

        $oldest = EmailTrackingFoldService::oldestUnfolded();
        if ($oldest !== null && $oldest->lt(now()->subHours(self::STALE_HOURS))) {
            // Events arriving after the fold started are normal (they are hours old at most);
            // anything this old means the fold has not been completing.
            Log::error('Email tracking journal is not being folded', [
                'oldest_unfolded' => $oldest->toDateTimeString(),
                'folded_this_run' => $stats['events'],
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
