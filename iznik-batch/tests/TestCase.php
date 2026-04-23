<?php

namespace Tests;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Use DatabaseTransactions for serial PHPUnit execution.
    // This rolls back each test's changes, ensuring test isolation.
    use DatabaseTransactions;

    /**
     * Saved PHPUnit error/exception handler stack from before Laravel's setUp.
     *
     * @var list<callable>|null
     */
    private ?array $phpunitErrorHandlers = null;
    private ?array $phpunitExceptionHandlers = null;

    /**
     * Prevent PHPUnit from marking tests as risky due to Laravel's handler cleanup.
     *
     * PHPUnit 11+ snapshots the error/exception handler stack before setUp and
     * compares it after tearDown. Laravel's HandleExceptions::flushHandlersState()
     * pops ALL handlers and re-enables PHPUnit's ErrorHandler with a new closure
     * instance, causing a strict comparison mismatch (→ risky test).
     *
     * Fix: capture the exact handler stack before Laravel modifies it, then
     * restore that exact stack after Laravel's tearDown wipes everything.
     *
     * @see https://github.com/Freegle/FreegleDocker/issues/34
     */
    protected function setUp(): void
    {
        // Capture PHPUnit's handler stack BEFORE Laravel boots and modifies it.
        $this->phpunitErrorHandlers = $this->captureHandlerStack('error');
        $this->phpunitExceptionHandlers = $this->captureHandlerStack('exception');

        parent::setUp();

        // Force mail driver to 'array' for testing.
        // Docker's MAIL_MAILER=smtp would otherwise override phpunit.xml's setting.
        config(['mail.default' => 'array']);
        \Illuminate\Support\Facades\Mail::forgetMailers();

        // Force cache driver to 'array' and flush it, so rate-limit / throttle
        // entries (e.g. bounce_autoreply:<hash>) don't leak between tests.
        // DatabaseTransactions only isolates the DB; the cache store is shared
        // process-wide, so a cache entry set by one test would otherwise be
        // visible to the next and cause order-dependent failures.
        config(['cache.default' => 'array']);
        \Illuminate\Support\Facades\Cache::flush();

        // Hard check: verify we are connected to the test database, not production.
        // This runs AFTER Laravel boots, so it checks the real PDO connection.
        $dbName = \DB::connection()->getDatabaseName();
        if ($dbName !== 'iznik_batch_test') {
            fwrite(STDERR, "\n\n");
            fwrite(STDERR, "╔════════════════════════════════════════════════════════════════════╗\n");
            fwrite(STDERR, "║  FATAL: Tests are connected to '{$dbName}', not 'iznik_batch_test'! ║\n");
            fwrite(STDERR, "║                                                                    ║\n");
            fwrite(STDERR, "║  Running tests against the production database would corrupt data.  ║\n");
            fwrite(STDERR, "║  This check cannot be bypassed.                                     ║\n");
            fwrite(STDERR, "╚════════════════════════════════════════════════════════════════════╝\n");
            fwrite(STDERR, "\n");
            exit(1);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // After Laravel's flushHandlersState() wiped and re-created handlers,
        // restore the exact stack PHPUnit snapshotted before this test.
        if ($this->phpunitErrorHandlers !== null) {
            // Pop whatever's there now.
            while (set_error_handler(fn () => false) !== null) {
                restore_error_handler();
                restore_error_handler();
            }
            restore_error_handler(); // Remove our probe.

            // Push back the original handlers in order.
            foreach ($this->phpunitErrorHandlers as $handler) {
                set_error_handler($handler);
            }
        }

        if ($this->phpunitExceptionHandlers !== null) {
            while (set_exception_handler(fn () => false) !== null) {
                restore_exception_handler();
                restore_exception_handler();
            }
            restore_exception_handler();

            foreach ($this->phpunitExceptionHandlers as $handler) {
                set_exception_handler($handler);
            }
        }
    }

    /**
     * Capture the current handler stack (error or exception) non-destructively.
     *
     * @return list<callable>
     */
    private function captureHandlerStack(string $type): array
    {
        $handlers = [];

        if ($type === 'error') {
            while (true) {
                $prev = set_error_handler(fn () => false);
                restore_error_handler();
                if ($prev === null) {
                    break;
                }
                $handlers[] = $prev;
                restore_error_handler();
            }
            // Re-push in reverse to restore original stack.
            foreach (array_reverse($handlers) as $h) {
                set_error_handler($h);
            }
        } else {
            while (true) {
                $prev = set_exception_handler(fn () => false);
                restore_exception_handler();
                if ($prev === null) {
                    break;
                }
                $handlers[] = $prev;
                restore_exception_handler();
            }
            foreach (array_reverse($handlers) as $h) {
                set_exception_handler($h);
            }
        }

        return $handlers;
    }

    /**
     * Ensure tests are run via the status container, not directly.
     *
     * This prevents accidentally running tests with `docker exec` instead
     * of via the status container API which manages test isolation and reporting.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! getenv('VIA_STATUS_CONTAINER')) {
            fwrite(STDERR, "\n\n");
            fwrite(STDERR, "╔════════════════════════════════════════════════════════════════╗\n");
            fwrite(STDERR, "║  ERROR: Tests must be run via the status container!            ║\n");
            fwrite(STDERR, "║                                                                ║\n");
            fwrite(STDERR, "║  Use: curl -X POST http://localhost:8081/api/tests/php         ║\n");
            fwrite(STDERR, "║  Or:  curl -X POST http://localhost:8081/api/tests/laravel     ║\n");
            fwrite(STDERR, "║                                                                ║\n");
            fwrite(STDERR, "║  DO NOT run: docker exec freegle-batch php artisan test        ║\n");
            fwrite(STDERR, "╚════════════════════════════════════════════════════════════════╝\n");
            fwrite(STDERR, "\n");
            exit(1);
        }
    }

    /**
     * Create a test user with an email address.
     *
     * @param array $attributes User attributes. Can include:
     *   - email_preferred: Custom email address (default: test{id}@test.com)
     *   - Any other User model attributes
     */
    protected function createTestUser(array $attributes = []): User
    {
        // Extract email_preferred if provided (not a User model attribute)
        $customEmail = $attributes['email_preferred'] ?? null;
        unset($attributes['email_preferred']);

        $user = User::create(array_merge([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ], $attributes));

        // Create email for user - use custom email if provided.
        $email = $customEmail ?? 'test'.$user->id.'@test.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $email,
            'preferred' => 1,
            'added' => now(),
        ]);

        return $user->fresh();
    }

    /**
     * Create a test Freegle group with auto-generated unique name.
     *
     * IMPORTANT: Do NOT pass 'nameshort' or 'namefull' - these are auto-generated
     * to ensure uniqueness in parallel test runs. Use $group->nameshort in assertions.
     *
     * @throws \InvalidArgumentException if nameshort or namefull is provided
     */
    protected function createTestGroup(array $attributes = []): Group
    {
        // Fail fast if caller tries to use hardcoded names - these cause collisions in parallel tests.
        if (isset($attributes['nameshort'])) {
            throw new \InvalidArgumentException(
                "Do not pass 'nameshort' to createTestGroup() - it causes collisions in parallel tests. ".
                'Use the auto-generated name and reference $group->nameshort in assertions.'
            );
        }
        if (isset($attributes['namefull'])) {
            throw new \InvalidArgumentException(
                "Do not pass 'namefull' to createTestGroup() - it causes collisions in parallel tests. ".
                'Use the auto-generated name and reference $group->namefull in assertions.'
            );
        }

        $uniqueId = uniqid('', true);

        return Group::create(array_merge([
            'nameshort' => 'TestGroup_'.$uniqueId,
            'namefull' => 'Test Freegle Group '.$uniqueId,
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5074,
            'lng' => -0.1278,
            'onhere' => 1,
            'publish' => 1,
        ], $attributes));
    }

    /**
     * Create a membership for a user in a group.
     */
    protected function createMembership(User $user, Group $group, array $attributes = []): Membership
    {
        return Membership::create(array_merge([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ], $attributes));
    }

    /**
     * Create a test message (offer/wanted).
     */
    protected function createTestMessage(User $user, Group $group, array $attributes = []): Message
    {
        $message = Message::create(array_merge([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'This is a test offer message.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ], $attributes));

        // Create messages_groups entry.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        return $message->fresh();
    }

    /**
     * Create multiple test messages.
     */
    protected function createTestMessages(User $user, Group $group, int $count = 3): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = $this->createTestMessage($user, $group, [
                'subject' => 'OFFER: Test Item '.($i + 1).' (TestLocation)',
                'type' => $i % 2 === 0 ? Message::TYPE_OFFER : Message::TYPE_WANTED,
            ]);
        }

        return $messages;
    }

    /**
     * Create a test chat room between two users.
     */
    protected function createTestChatRoom(User $user1, User $user2, array $attributes = []): ChatRoom
    {
        return ChatRoom::create(array_merge([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ], $attributes));
    }

    /**
     * Create a test chat message.
     */
    protected function createTestChatMessage(ChatRoom $room, User $user, array $attributes = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'chatid' => $room->id,
            'userid' => $user->id,
            'message' => 'Test message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ], $attributes));
    }

    /**
     * Generate a unique email address for testing.
     *
     * Use this instead of hardcoding email addresses which cause collisions in parallel tests.
     *
     * @param  string  $prefix  Optional prefix for the email (e.g., 'bounced', 'validated')
     * @param  string  $domain  Optional domain for the email (default: 'test.com')
     * @return string A unique email address
     */
    protected function uniqueEmail(string $prefix = 'test', string $domain = 'test.com'): string
    {
        return $prefix.'_'.uniqid('', true).'@'.$domain;
    }

    /**
     * Create a UserEmail record with a unique email address.
     *
     * IMPORTANT: Do NOT pass 'email' - use the returned object's email property.
     *
     * @throws \InvalidArgumentException if email is provided
     */
    protected function createTestUserEmail(User $user, array $attributes = []): UserEmail
    {
        if (isset($attributes['email'])) {
            throw new \InvalidArgumentException(
                "Do not pass 'email' to createTestUserEmail() - it causes collisions in parallel tests. ".
                'Use the auto-generated email and reference $userEmail->email in assertions.'
            );
        }

        return UserEmail::create(array_merge([
            'userid' => $user->id,
            'email' => $this->uniqueEmail(),
            'preferred' => 0,
            'added' => now(),
        ], $attributes));
    }
}
