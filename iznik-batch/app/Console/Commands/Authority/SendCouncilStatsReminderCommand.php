<?php

namespace App\Console\Commands\Authority;

use App\Mail\Authority\AuthorityStatsReminderMail;
use App\Services\AuthorityStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Quarterly council statistics reminder.
 *
 * Generates one spreadsheet per configured authority for the last full quarter
 * and emails them to the partnerships inbox for review, so the quarterly council
 * emails can then be sent (a couple of weeks after the quarter ends).
 */
class SendCouncilStatsReminderCommand extends Command
{
    protected $signature = 'authority:stats-reminder
                            {--q= : Quarter start date (default: the last full quarter, "3 months ago")}
                            {--to= : Override the reminder recipient}
                            {--dry-run : Generate the spreadsheets but do not send the email}';

    protected $description = 'Generate the quarterly council statistics spreadsheets and email them to the partnerships inbox for review';

    public function handle(AuthorityStatsService $service): int
    {
        $quarter = (string) ($this->option('q') ?: '3 months ago');

        $ids = config('authority_stats.authority_ids', []);
        if (empty($ids)) {
            $this->error('No authority IDs configured (authority_stats.authority_ids).');
            return Command::FAILURE;
        }

        $quarterNumber = $service->getQuarterNumber($quarter);
        $year = date('Y', strtotime($quarter));
        $quarterLabel = "Q{$quarterNumber} {$year}";

        // Generate the spreadsheets into a fresh, isolated directory.
        $dir = storage_path('app/authority-stats-reminder/' . date('YmdHis'));
        File::ensureDirectoryExists($dir);

        try {
            $this->info("Generating {$quarterLabel} statistics for " . count($ids) . ' authorities ...');
            $exit = Artisan::call('authority:stats', [
                '--i' => implode(',', $ids),
                '--q' => $quarter,
                '--output' => $dir,
            ]);

            $files = glob($dir . '/*.xlsx') ?: [];
            if ($exit !== Command::SUCCESS || count($files) === 0) {
                $this->error('Spreadsheet generation failed (exit ' . $exit . ', ' . count($files) . ' files).');
                return Command::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info('Dry run: generated ' . count($files) . " spreadsheet(s) in {$dir}; not sending.");
                return Command::SUCCESS;
            }

            $to = (string) ($this->option('to') ?: config('authority_stats.reminder_recipient'));
            if ($to === '') {
                $this->error('No reminder recipient configured.');
                return Command::FAILURE;
            }

            Mail::to($to)->send(new AuthorityStatsReminderMail($quarterLabel, $files));

            $this->info("Sent {$quarterLabel} council statistics (" . count($files) . " attachments) to {$to}.");
            Log::info('authority:stats-reminder sent', [
                'quarter' => $quarterLabel,
                'to' => $to,
                'attachments' => count($files),
            ]);

            return Command::SUCCESS;
        } finally {
            // Tidy the generated files once they've been attached and sent.
            File::deleteDirectory($dir);
        }
    }
}
