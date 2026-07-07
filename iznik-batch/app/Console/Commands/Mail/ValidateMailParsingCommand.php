<?php

namespace App\Console\Commands\Mail;

use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Validates that Laravel mail parsing produces identical results to the PHP implementation.
 *
 * This command fetches messages from the database and compares how they would be
 * parsed/routed by the new Laravel code vs the stored results from the original PHP code.
 *
 * Usage:
 *   php artisan mail:validate-parsing           # Validate last 100 messages
 *   php artisan mail:validate-parsing --limit=500
 *   php artisan mail:validate-parsing --message-id=12345
 *   php artisan mail:validate-parsing --dry-run  # Show what would be validated
 */
class ValidateMailParsingCommand extends Command
{
    protected $signature = 'mail:validate-parsing
        {--limit=100 : Number of messages to validate}
        {--message-id= : Validate a specific message ID}
        {--type= : Filter by message type (Offer, Wanted, etc.)}
        {--dry-run : Show what would be validated without actually running}
        {--verbose : Show detailed comparison for each message}';

    protected $description = 'Validate that Laravel mail parsing matches PHP implementation';

    private MailParserService $parser;

    private int $totalChecked = 0;

    private int $matched = 0;

    private int $mismatched = 0;

    private array $mismatches = [];

    public function __construct(MailParserService $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $messageId = $this->option('message-id');
        $type = $this->option('type');
        $dryRun = $this->option('dry-run');
        $verbose = $this->option('verbose');

        $this->info('Mail Parsing Validation');
        $this->info('======================');
        $this->newLine();

        // Build query
        $query = DB::table('messages')
            ->select([
                'messages.id',
                'messages.source',
                'messages.message',
                'messages.fromaddr',
                'messages.envelopefrom',
                'messages.envelopeto',
                'messages.subject',
                'messages.messageid',
                'messages.type',
                'messages.lastroute',
                'messages.spamtype',
                'messages.spamreason',
            ])
            ->whereNotNull('messages.message')
            ->where('messages.message', '!=', '')
            ->orderBy('messages.id', 'desc');

        if ($messageId) {
            $query->where('messages.id', $messageId);
            $limit = 1;
        }

        if ($type) {
            $query->where('messages.type', $type);
        }

        $query->limit($limit);

        if ($dryRun) {
            $count = $query->count();
            $this->info("Would validate {$count} messages");
            $this->info('Query: '.$query->toSql());

            return self::SUCCESS;
        }

        $this->info("Validating up to {$limit} messages...");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($limit);

        $query->chunk(100, function ($messages) use ($progressBar, $verbose) {
            foreach ($messages as $message) {
                $this->validateMessage($message, $verbose);
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Validation Summary');
        $this->info('==================');
        $this->info("Total checked: {$this->totalChecked}");
        $this->info("Matched: {$this->matched}");
        $this->warn("Mismatched: {$this->mismatched}");

        if ($this->mismatched > 0) {
            $this->newLine();
            $this->error('Mismatches found:');

            $headers = ['Message ID', 'Field', 'Expected (PHP)', 'Actual (Laravel)'];
            $rows = [];

            foreach ($this->mismatches as $mismatch) {
                $rows[] = [
                    $mismatch['id'],
                    $mismatch['field'],
                    $this->truncate($mismatch['expected'], 30),
                    $this->truncate($mismatch['actual'], 30),
                ];
            }

            $this->table($headers, $rows);

            return self::FAILURE;
        }

        $this->info('All messages validated successfully!');

        return self::SUCCESS;
    }

    private function validateMessage(object $message, bool $verbose): void
    {
        $this->totalChecked++;

        // The raw message is stored in the 'message' column
        $rawEmail = $message->message;
        if (empty($rawEmail)) {
            return;
        }

        // Parse using our new Laravel service
        $envelopeFrom = $message->envelopefrom ?? $message->fromaddr ?? '';
        $envelopeTo = $message->envelopeto ?? '';

        try {
            $parsed = $this->parser->parse($rawEmail, $envelopeFrom, $envelopeTo);
        } catch (\Exception $e) {
            $this->recordMismatch($message->id, 'parse_error', 'success', $e->getMessage());

            return;
        }

        $allMatch = true;

        // Compare subject
        if ($message->subject !== $parsed->subject) {
            $this->recordMismatch($message->id, 'subject', $message->subject, $parsed->subject);
            $allMatch = false;
        }

        // Compare from address
        if ($message->fromaddr && $message->fromaddr !== $parsed->fromAddress) {
            $this->recordMismatch($message->id, 'fromaddr', $message->fromaddr, $parsed->fromAddress);
            $allMatch = false;
        }

        // Compare message ID
        if ($message->messageid && $message->messageid !== $parsed->messageId) {
            // Normalize by removing angle brackets
            $expectedMsgId = trim($message->messageid, '<>');
            $actualMsgId = $parsed->messageId;
            if ($expectedMsgId !== $actualMsgId) {
                $this->recordMismatch($message->id, 'messageid', $expectedMsgId, $actualMsgId);
                $allMatch = false;
            }
        }

        // Compare type detection (bounce, chat reply, etc.)
        $this->validateTypeDetection($message, $parsed, $allMatch);

        if ($allMatch) {
            $this->matched++;
        } else {
            $this->mismatched++;
        }

        if ($verbose) {
            $status = $allMatch ? '✓' : '✗';
            $this->line("  {$status} Message #{$message->id}: {$message->subject}");
        }
    }

    private function validateTypeDetection(object $message, $parsed, bool &$allMatch): void
    {
        // Check bounce detection
        $isBounceInDb = str_starts_with($message->envelopeto ?? '', 'bounce-');
        if ($isBounceInDb !== $parsed->isBounce()) {
            // Only flag if it's a clear bounce address pattern
            if ($isBounceInDb) {
                $this->recordMismatch($message->id, 'is_bounce', 'true', 'false');
                $allMatch = false;
            }
        }

        // Check chat reply detection
        $isChatReplyInDb = str_starts_with($message->envelopeto ?? '', 'notify-');
        if ($isChatReplyInDb !== $parsed->isChatNotificationReply()) {
            if ($isChatReplyInDb) {
                $this->recordMismatch($message->id, 'is_chat_reply', 'true', 'false');
                $allMatch = false;
            }
        }
    }

    private function recordMismatch(int $id, string $field, ?string $expected, ?string $actual): void
    {
        $this->mismatches[] = [
            'id' => $id,
            'field' => $field,
            'expected' => $expected ?? '(null)',
            'actual' => $actual ?? '(null)',
        ];
    }

    private function truncate(?string $value, int $length): string
    {
        if ($value === null) {
            return '(null)';
        }
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length - 3).'...';
    }
}
