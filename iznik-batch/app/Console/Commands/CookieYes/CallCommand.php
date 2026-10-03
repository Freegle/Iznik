<?php

namespace App\Console\Commands\CookieYes;

use App\Services\CookieYes\CookieYesException;
use App\Services\CookieYes\CookieYesMcpClient;
use Illuminate\Console\Command;

/**
 * Call one CookieYes MCP tool and print exactly what came back. For diagnosing
 * the watchdog and for recording test fixtures.
 */
class CallCommand extends Command
{
    protected $signature = 'cookieyes:call
                            {tool : A tool name, or tools/list to see them all with their arguments}
                            {--args= : Tool arguments as a JSON object}';

    protected $description = 'Call a CookieYes MCP tool and print the raw result';

    public function handle(CookieYesMcpClient $mcp): int
    {
        $tool = (string) $this->argument('tool');
        $args = [];

        if ($this->option('args') !== null) {
            $args = json_decode((string) $this->option('args'), true);
            if (! is_array($args)) {
                $this->error('--args must be a JSON object');

                return Command::FAILURE;
            }
        }

        try {
            $result = $tool === 'tools/list' ? $mcp->listTools() : $mcp->callToolRaw($tool, $args);
        } catch (CookieYesException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
