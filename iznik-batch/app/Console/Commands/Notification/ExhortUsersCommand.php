<?php

namespace App\Console\Commands\Notification;

use App\Services\NotificationExhortService;
use Illuminate\Console\Command;

class ExhortUsersCommand extends Command
{
    protected $signature = 'notifications:exhort
                            {--url= : URL of the page to link to (default: stories page)}
                            {--title= : Notification title (default: stories prompt)}
                            {--text= : Notification body text (default: stories prompt)}
                            {--dry-run : Preview without inserting notifications}';

    protected $description = 'Send onsite Exhort notifications to recently-active established users';

    public function handle(NotificationExhortService $service): int
    {
        $url = $this->option('url') ?? 'https://www.ilovefreegle.org/stories';
        $title = $this->option('title') ?? 'Tell us your Freegle story!';
        $text = $this->option('text') ?? 'We love to hear why people Freegle - it keeps our volunteers volunteering, and it helps show new freeglers what it\'s all about.';
        $dryRun = (bool) $this->option('dry-run');

        $count = $service->sendExhort(
            url: $url,
            title: $title,
            text: $text,
            dryRun: $dryRun,
        );

        $prefix = $dryRun ? '[DRY RUN] Would send' : 'Sent';
        $this->info("{$prefix} {$count} exhort notification(s).");

        return self::SUCCESS;
    }
}
