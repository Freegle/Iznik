<?php

namespace App\Console\Commands\Mail;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\StripQuotedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off recovery command for emails dropped due to merged user proxy addresses.
 *
 * Bug: findUserByEmail() extracted the UID from proxy addresses like
 * slug-{OLDID}@users.ilovefreegle.org and returned null when the old user
 * didn't exist (merged), instead of falling through to users_emails lookup.
 *
 * This command scans the incoming-archive for Dropped emails to user proxy
 * addresses, resolves the actual recipient via users_emails, and delivers
 * the messages with an apology note.
 */
class RecoverDroppedMergedUserMailCommand extends Command
{
    protected $signature = 'mail:recover-dropped-merged
                            {--execute : Actually deliver messages (default is dry-run)}
                            {--from-date= : Start date (Y-m-d), default: all available}
                            {--to-date= : End date (Y-m-d), default: today}
                            {--file= : Process a single archive file (for testing)}
                            {--prefix= : Message prefix to prepend (omit for no prefix)}';

    protected $description = 'Recover emails dropped due to merged user proxy address bug';

    private ?string $prefix = null;

    public function handle(MailParserService $parser, StripQuotedService $stripQuoted): int
    {
        $execute = $this->option('execute');
        $this->prefix = $this->option('prefix');
        $singleFile = $this->option('file');

        $this->info($execute ? 'EXECUTING - delivering recovered messages' : 'DRY RUN - use --execute to deliver');
        if ($this->prefix !== null) {
            $this->info("Prefix: {$this->prefix}");
        }
        $this->newLine();

        // Single file mode for testing
        if ($singleFile !== null) {
            if (! file_exists($singleFile)) {
                $this->error("File not found: {$singleFile}");

                return 1;
            }
            $result = $this->processArchiveFile($singleFile, $parser, $stripQuoted, $execute);
            $this->info("Result: {$result}");

            return $result === 'error' ? 1 : 0;
        }

        $archiveDir = storage_path('incoming-archive');

        if (! is_dir($archiveDir)) {
            $this->error('Archive directory not found: '.$archiveDir);

            return 1;
        }

        $dateDirs = scandir($archiveDir);
        sort($dateDirs);

        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date') ?? date('Y-m-d');

        $recovered = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($dateDirs as $dateDir) {
            if ($dateDir === '.' || $dateDir === '..') {
                continue;
            }
            if ($fromDate && $dateDir < $fromDate) {
                continue;
            }
            if ($toDate && $dateDir > $toDate) {
                continue;
            }

            $fullDir = $archiveDir.'/'.$dateDir;
            if (! is_dir($fullDir)) {
                continue;
            }

            $files = scandir($fullDir);
            foreach ($files as $file) {
                if (! str_ends_with($file, '.json')) {
                    continue;
                }

                $result = $this->processArchiveFile($fullDir.'/'.$file, $parser, $stripQuoted, $execute);
                match ($result) {
                    'recovered' => $recovered++,
                    'skipped' => $skipped++,
                    'error' => $errors++,
                    default => null,
                };
            }
        }

        $this->newLine();
        $this->info("Done. Recovered: {$recovered}, Skipped: {$skipped}, Errors: {$errors}");

        if (! $execute && $recovered > 0) {
            $this->warn('Run with --execute to actually deliver these messages.');
        }

        return 0;
    }

    private function processArchiveFile(
        string $path,
        MailParserService $parser,
        StripQuotedService $stripQuoted,
        bool $execute
    ): string {
        $data = json_decode(@file_get_contents($path), true);
        if ($data === null) {
            return 'skipped';
        }

        // Only process Dropped emails
        if (($data['routing_outcome'] ?? '') !== 'Dropped') {
            return 'skipped';
        }

        $envelope = $data['envelope'] ?? [];
        $to = $envelope['to'] ?? '';
        $from = $envelope['from'] ?? '';

        // Only process user proxy addresses (not bounces, groups, system addresses)
        if (! preg_match('/^.+\-(\d+)@users\.ilovefreegle\.org$/', $to, $matches)) {
            return 'skipped';
        }

        $embeddedUid = (int) $matches[1];

        // Only process if the embedded UID doesn't exist (merged user)
        if (User::find($embeddedUid) !== null) {
            return 'skipped';
        }

        // Look up the actual recipient via users_emails
        $userEmail = UserEmail::where('email', $to)->first();
        if ($userEmail === null) {
            return 'skipped';
        }

        $recipientUser = User::find($userEmail->userid);
        if ($recipientUser === null) {
            return 'skipped';
        }

        // Decode the raw email
        $rawEmail = base64_decode($data['raw_email'] ?? '');
        if (empty($rawEmail)) {
            return 'skipped';
        }

        // Find the sender
        try {
            $parsed = $parser->parse($rawEmail, $from, $to);
        } catch (\Throwable $e) {
            $this->error("  Parse error in {$path}: {$e->getMessage()}");

            return 'error';
        }

        // Look up sender by email
        $senderEmail = UserEmail::where('email', $parsed->fromAddress)->first();
        if ($senderEmail === null) {
            // Try envelope from
            $senderEmail = UserEmail::where('email', $from)->first();
        }
        if ($senderEmail === null) {
            return 'skipped';
        }

        $senderUser = User::find($senderEmail->userid);
        if ($senderUser === null || $senderUser->id === $recipientUser->id) {
            return 'skipped';
        }

        // Get message body
        $body = $parsed->textBody;
        if ($body === null && $parsed->htmlBody !== null) {
            $html2text = new \Html2Text\Html2Text($parsed->htmlBody);
            $body = $html2text->getText();
        }
        $body = $stripQuoted->strip($body ?? '');

        if (empty(trim($body))) {
            return 'skipped';
        }

        // Try to find refmsgid from x-fd-msgid header
        $refMsgId = null;
        $fdMsgId = $parsed->getHeader('x-fd-msgid');
        if ($fdMsgId !== null && is_numeric($fdMsgId)) {
            $refMsgId = (int) $fdMsgId;
        }

        $timestamp = $data['timestamp'] ?? 'unknown';
        $subject = $parsed->subject ?? '(no subject)';

        $this->line("  [{$timestamp}] {$from} -> user {$recipientUser->id} ({$recipientUser->fullname}): {$subject}");

        if (! $execute) {
            return 'recovered';
        }

        try {
            // Get or create chat
            $chat = $this->getOrCreateChat($senderUser->id, $recipientUser->id);
            if ($chat === null) {
                $this->error("  Could not create chat for {$senderUser->id} -> {$recipientUser->id}");

                return 'error';
            }

            // Deduplicate: skip if an identical message already exists in this chat
            // (guards against running the command twice on the same archive)
            $bodySnippet = '%'.addcslashes(substr($body, 0, 100), '%_\\').'%';
            $existingDupe = DB::table('chat_messages')
                ->where('chatid', $chat->id)
                ->where('userid', $senderUser->id)
                ->where('message', 'LIKE', $bodySnippet)
                ->exists();

            if ($existingDupe) {
                $this->line('    (skipped - duplicate already exists)');

                return 'skipped';
            }

            // Create the chat message with optional prefix
            $messageBody = $this->prefix !== null
                ? $this->prefix."\n\n---\n\n".$body
                : $body;
            $type = $refMsgId !== null ? ChatMessage::TYPE_INTERESTED : ChatMessage::TYPE_DEFAULT;

            $chatMessage = ChatMessage::create([
                'chatid' => $chat->id,
                'userid' => $senderUser->id,
                'message' => $messageBody,
                'date' => now(),
                'type' => $type,
                'refmsgid' => $refMsgId,
                'platform' => 0,
                'seenbyall' => 0,
                'mailedtoall' => 0,
                'reviewrequired' => 0,
                'reviewrejected' => 0,
                'processingrequired' => 0,
            ]);

            // Update chat room latestmessage
            $chat->update(['latestmessage' => now()]);

            Log::info('Recovered dropped merged-user email', [
                'archive_file' => $path,
                'chat_id' => $chat->id,
                'chat_message_id' => $chatMessage->id,
                'sender_id' => $senderUser->id,
                'recipient_id' => $recipientUser->id,
                'ref_msg_id' => $refMsgId,
            ]);

            return 'recovered';

        } catch (\Throwable $e) {
            $this->error("  Error delivering: {$e->getMessage()}");

            return 'error';
        }
    }

    private function getOrCreateChat(int $userId1, int $userId2): ?ChatRoom
    {
        $chat = ChatRoom::where('chattype', 'User2User')
            ->where(function ($q) use ($userId1, $userId2) {
                $q->where(function ($q2) use ($userId1, $userId2) {
                    $q2->where('user1', $userId1)->where('user2', $userId2);
                })->orWhere(function ($q2) use ($userId1, $userId2) {
                    $q2->where('user1', $userId2)->where('user2', $userId1);
                });
            })
            ->first();

        if ($chat !== null) {
            return $chat;
        }

        // Normalize order
        [$u1, $u2] = $userId1 < $userId2 ? [$userId1, $userId2] : [$userId2, $userId1];

        $chat = ChatRoom::create([
            'chattype' => 'User2User',
            'user1' => $u1,
            'user2' => $u2,
        ]);

        DB::table('chat_roster')->insertOrIgnore([
            ['chatid' => $chat->id, 'userid' => $u1],
            ['chatid' => $chat->id, 'userid' => $u2],
        ]);

        return $chat;
    }
}
