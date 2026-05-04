<?php

namespace App\Services\SpamCheck;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for checking emails against SpamAssassin and Rspamd.
 */
class SpamCheckService
{
    private string $spamassassinHost;
    private int $spamassassinPort;
    private string $rspamdHost;
    private int $rspamdPort;

    public function __construct()
    {
        $this->spamassassinHost = config('freegle.spam_check.spamassassin_host', 'spamassassin-app');
        $this->spamassassinPort = (int) config('freegle.spam_check.spamassassin_port', 783);
        $this->rspamdHost = config('freegle.spam_check.rspamd_host', 'rspamd');
        $this->rspamdPort = (int) config('freegle.spam_check.rspamd_port', 11334);
    }

    /**
     * Check an email with SpamAssassin.
     */
    public function checkSpamAssassin(string $rawEmail): SpamResult
    {
        try {
            $socket = @fsockopen($this->spamassassinHost, $this->spamassassinPort, $errno, $errstr, 5);

            if (!$socket) {
                return SpamResult::error('SpamAssassin', "Connection failed: {$errstr}");
            }

            // Send SYMBOLS command (returns score and matched rules)
            // Must use SPAMC/1.2 — spamd rejects 1.5.
            $command = "SYMBOLS SPAMC/1.2\r\n";
            $command .= "Content-length: " . strlen($rawEmail) . "\r\n";
            $command .= "\r\n";
            $command .= $rawEmail;

            stream_set_timeout($socket, 30);
            fwrite($socket, $command);
            // Signal end-of-write so spamd knows the full message has been sent.
            stream_socket_shutdown($socket, STREAM_SHUT_WR);

            $response = '';
            while (!feof($socket)) {
                $response .= fread($socket, 8192);
                $info = stream_get_meta_data($socket);
                if ($info['timed_out']) {
                    fclose($socket);
                    throw new \RuntimeException('SpamAssassin timed out after 30 seconds');
                }
            }

            fclose($socket);

            return SpamResult::fromSpamAssassin($response);
        } catch (\Exception $e) {
            Log::warning('SpamAssassin check failed', ['error' => $e->getMessage()]);
            return SpamResult::error('SpamAssassin', $e->getMessage());
        }
    }

    /**
     * Check an email with Rspamd.
     */
    public function checkRspamd(string $rawEmail): SpamResult
    {
        try {
            $url = "http://{$this->rspamdHost}:{$this->rspamdPort}/checkv2";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'message/rfc822',
                ])
                ->withBody($rawEmail, 'message/rfc822')
                ->post($url);

            if (!$response->successful()) {
                return SpamResult::error('Rspamd', "HTTP {$response->status()}");
            }

            $data = $response->json();
            if (!$data) {
                return SpamResult::error('Rspamd', 'Invalid JSON response');
            }

            return SpamResult::fromRspamd($data);
        } catch (\Exception $e) {
            Log::warning('Rspamd check failed', ['error' => $e->getMessage()]);
            return SpamResult::error('Rspamd', $e->getMessage());
        }
    }

    /**
     * Check an email with all available spam filters.
     *
     * @return array<string, SpamResult>
     */
    public function checkAll(string $rawEmail): array
    {
        return [
            'spamassassin' => $this->checkSpamAssassin($rawEmail),
            'rspamd' => $this->checkRspamd($rawEmail),
        ];
    }

    /**
     * Check if spam checking is enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('freegle.spam_check.enabled', false);
    }
}
