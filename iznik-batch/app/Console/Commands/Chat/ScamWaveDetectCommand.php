<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatScamWaveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Every 15 minutes: look for the same chat text arriving from many senders, and mail
 * geeks@ the report when there is a cluster. Read-only; acting on a wave stays a human
 * decision (docs/ops/runbooks/chat-scam-wave.md).
 */
#[AsCommand(name: 'chat:scam-wave-detect')]
class ScamWaveDetectCommand extends Command
{
    protected $signature = 'chat:scam-wave-detect
        {--window=30 : How many minutes back to look}
        {--no-mail : Print the report without mailing it}';

    /** A day. Every message in the window is read into memory, so it is kept bounded. */
    public const MAX_WINDOW_MINUTES = 1440;

    protected $description ='Report chat text sent by many senders in a short window, a possible scam wave';

    public function handle(ChatScamWaveService $service): int
    {
        $window = $this->option('window');
        if (! ctype_digit((string) $window) || (int) $window < 1 || (int) $window > self::MAX_WINDOW_MINUTES) {
            $this->error('--window must be a whole number of minutes from 1 to '.self::MAX_WINDOW_MINUTES.'.');

            return self::FAILURE;
        }

        $result = $service->detect((int) $window);
        $report = $service->report($result);
        $this->line($report);

        if (empty($result['clusters']) || $this->option('no-mail')) {
            return self::SUCCESS;
        }

        $body = "The chat scam-wave detector found the same text coming from many senders.\n\n"
            .$report
            ."\nThe number in brackets is the chat message id. If this is a scam, see docs/ops/runbooks/chat-scam-wave.md for how to respond.\n";

        $to = config('freegle.mail.geeks_addr') ?: 'geeks@ilovefreegle.org';
        Mail::raw($body, function ($message) use ($to) {
            $message->to($to)->subject('[Freegle] possible chat scam wave');
        });
        $this->info("Report emailed to {$to}.");

        return self::SUCCESS;
    }
}
