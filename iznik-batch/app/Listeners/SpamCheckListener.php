<?php

namespace App\Listeners;

use App\Services\SpamCheck\SpamCheckService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Listener that checks outgoing emails for spam and adds headers with the scores.
 *
 * This is only active when SPAM_CHECK_ENABLED=true (typically in testing).
 */
class SpamCheckListener
{
    private SpamCheckService $spamChecker;

    public function __construct(SpamCheckService $spamChecker)
    {
        $this->spamChecker = $spamChecker;
    }

    /**
     * Handle the event.
     */
    public function handle(MessageSending $event): void
    {
        if (!SpamCheckService::isEnabled()) {
            return;
        }

        try {
            $message = $event->message;
            $rawEmail = $message->toString();

            // Check with both spam filters
            $results = $this->spamChecker->checkAll($rawEmail);

            // Add SpamAssassin headers
            $saResult = $results['spamassassin'];
            $message->getHeaders()->addTextHeader('X-Spam-Score-SA', $saResult->toHeaderValue());
            $message->getHeaders()->addTextHeader('X-Spam-Status-SA', $saResult->isSpam ? 'Yes' : 'No');
            if (!$saResult->error && !empty($saResult->symbols)) {
                $message->getHeaders()->addTextHeader('X-Spam-Symbols-SA', $saResult->symbolsToHeaderValue());
            }

            // Add Rspamd headers
            $rspamdResult = $results['rspamd'];
            $message->getHeaders()->addTextHeader('X-Spam-Score-Rspamd', $rspamdResult->toHeaderValue());
            $message->getHeaders()->addTextHeader('X-Spam-Status-Rspamd', $rspamdResult->isSpam ? 'Yes' : 'No');
            if (!$rspamdResult->error && !empty($rspamdResult->symbols)) {
                $message->getHeaders()->addTextHeader('X-Spam-Symbols-Rspamd', $rspamdResult->symbolsToHeaderValue());
            }

            Log::debug('Spam check completed', [
                'spamassassin_score' => $saResult->score,
                'rspamd_score' => $rspamdResult->score,
            ]);
        } catch (\Exception $e) {
            Log::warning('Spam check listener failed', ['error' => $e->getMessage()]);
            // Don't fail the email sending if spam check fails
        }
    }
}
