<?php

namespace App\Console\Commands\CookieYes;

use App\Services\CookieYes\CookieYesException;
use App\Services\CookieYes\CookieYesMcpClient;
use App\Services\CookieYes\CookieYesOAuth;
use Illuminate\Console\Command;

/**
 * One-time login for the CookieYes watchdog, in two steps so it works over
 * `docker exec` with no terminal to type into.
 */
class AuthorizeCommand extends Command
{
    protected $signature = 'cookieyes:authorize
                            {--callback= : The address the browser landed on after you clicked Allow}';

    protected $description = 'Log the CookieYes watchdog in to CookieYes (run once, then again only if the login is lost)';

    public function handle(CookieYesOAuth $oauth, CookieYesMcpClient $mcp): int
    {
        try {
            $callback = (string) $this->option('callback');

            if ($callback === '') {
                $url = $oauth->beginLogin();
                $this->line('1. Open this address while logged in to CookieYes as the account owner, and click Allow:');
                $this->newLine();
                $this->line($url);
                $this->newLine();
                $this->line('2. The browser will then fail to load a localhost page. That is expected.');
                $this->line('   Copy the whole address from its address bar and run, within 24 hours:');
                $this->newLine();
                $this->line('   php artisan cookieyes:authorize --callback="<that address>"');

                return Command::SUCCESS;
            }

            $oauth->completeLogin($callback);
            $domains = $mcp->callTool('list_domains');
            $this->info('Logged in to CookieYes. list_domains answered: ' . json_encode($domains));
        } catch (CookieYesException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
