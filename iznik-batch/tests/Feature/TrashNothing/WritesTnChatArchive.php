<?php

namespace Tests\Feature\TrashNothing;

use App\Services\Mail\Incoming\IncomingArchiveReader;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Verify\ChatEmailInventory;
use App\Services\TrashNothing\Verify\TnEmailRoutingGate;
use Carbon\CarbonImmutable;

/**
 * TN members' chat mail as it lands in the incoming archive, for the chat parity and coverage
 * commands. Binds ChatEmailInventory to a temporary archive directory on first use.
 */
trait WritesTnChatArchive
{
    private ?string $chatArchiveDir = null;

    /** A TN member's reply to a notify- address, carrying TN's log id. */
    private function tnChatReply(int $chatId, int $userId, ?int $tnUserId, string $logId, string $text, string $from = 'member-g1@user.trashnothing.com'): string
    {
        return implode("\r\n", array_filter([
            'From: Member <' . $from . '>',
            'To: ' . $this->notifyAddress($chatId, $userId),
            'Subject: Re: OFFER: Bookcase (Town)',
            'Message-ID: <' . $logId . '@m2.trashnothing.com>',
            'Date: Tue, 17 Nov 2026 10:30:00 +0000',
            'X-Trash-Nothing-Log-Id: ' . $logId,
            $tnUserId !== null ? 'X-Trash-Nothing-User-Id: ' . $tnUserId : null,
        ])) . "\r\n\r\n" . $text . "\r\n";
    }

    private function notifyAddress(int $chatId, int $userId): string
    {
        return "notify-{$chatId}-{$userId}@" . config('freegle.mail.user_domain');
    }

    private function archiveChatMail(string $raw, string $envelopeTo, string $outcome, string $arrival, string $envelopeFrom = 'bounce@trashnothing.com'): void
    {
        if ($this->chatArchiveDir === null) {
            $this->chatArchiveDir = storage_path('incoming-archive/test-tn-chat-' . uniqid('', true));
            $dir = $this->chatArchiveDir;
            $this->app->bind(ChatEmailInventory::class, fn () => new ChatEmailInventory(
                new IncomingArchiveReader(app(MailParserService::class), $dir),
                app(MailParserService::class),
                new TnEmailRoutingGate,
            ));
        }

        $ts = CarbonImmutable::parse($arrival, 'UTC');
        $dateDir = $this->chatArchiveDir . '/' . $ts->format('Y-m-d');
        if (!is_dir($dateDir)) {
            mkdir($dateDir, 0755, true);
        }

        file_put_contents($dateDir . '/' . $ts->format('His') . '_' . random_int(100000, 999999) . '.json', json_encode([
            'version' => 3,
            'timestamp' => $ts->format('Y-m-d\TH:i:s\Z'),
            'envelope' => ['from' => $envelopeFrom, 'to' => $envelopeTo],
            'raw_email' => base64_encode($raw),
            'routing_outcome' => $outcome,
        ]));
    }

    private function removeChatArchive(): void
    {
        if ($this->chatArchiveDir === null) {
            return;
        }

        foreach (glob($this->chatArchiveDir . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->chatArchiveDir . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->chatArchiveDir);
    }
}
