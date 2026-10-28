<?php

namespace Tests\Feature\Deploy;

use App\Services\AppReleaseClassifierService;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AppReleaseClassifierServiceCoverageTest extends TestCase
{
    private const SHA_KEY = 'app_release_last_production_sha';
    private const NOTIFY_KEY = 'app_release_last_notification';

    private AppReleaseClassifierService $service;

    /** Captured by captureMail(): ['body' => string, 'subject' => string, 'to' => array]. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('config')->whereIn('key', [self::SHA_KEY, self::NOTIFY_KEY])->delete();
        config(['freegle.app_release.notification_email' => 'release-test@example.org']);
        config(['freegle.app_release.notification_interval' => 3600]);
        $this->service = new AppReleaseClassifierService();
    }

    /**
     * Make every config query throw. Repointing database.default instead would break the base
     * TestCase's transaction rollback, leaking this test's rows into the tests that follow.
     */
    private function failConfigQueries(): void
    {
        DB::shouldReceive('table')->with('config')->andThrow(new \RuntimeException('config table unavailable'));
    }

    private function configValue(string $key): ?string
    {
        $row = DB::table('config')->where('key', $key)->first();

        return $row ? $row->value : null;
    }

    /**
     * Mail::fake() makes Mail::raw() a no-op that records nothing, so intercept raw() directly and
     * run the real callback against a real Message to capture the subject and recipient.
     */
    private function captureMail(): void
    {
        $this->sent = [];
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback) {
            $message = new Message(new Email());
            $callback($message);
            $this->sent = [
                'body' => $body,
                'subject' => $message->getSymfonyMessage()->getSubject(),
                'to' => array_map(fn($a) => $a->getAddress(), $message->getSymfonyMessage()->getTo()),
            ];
        });
    }

    private function commits(int $n): array
    {
        $commits = [];
        for ($i = 1; $i <= $n; $i++) {
            $commits[] = ['hash' => "hash$i", 'author' => 'a', 'date' => '2026-10-20', 'message' => "feat: change $i"];
        }

        return $commits;
    }

    private function classifierResult(string $classification, array $commits = [], array $urgent = []): array
    {
        return [
            'classification' => $classification,
            'reason' => 'because',
            'commits' => $commits,
            'urgent_commits' => $urgent,
            'should_promote' => $classification === AppReleaseClassifierService::CLASSIFICATION_URGENT,
        ];
    }

    // getLastProductionSha / saveLastProductionSha / daysSinceLastProduction.

    public function test_getLastProductionSha_returns_null_when_absent(): void
    {
        $this->assertNull($this->service->getLastProductionSha());
    }

    public function test_getLastProductionSha_returns_stored_value(): void
    {
        DB::table('config')->insert(['key' => self::SHA_KEY, 'value' => 'abc123']);

        $this->assertSame('abc123', $this->service->getLastProductionSha());
    }

    public function test_getLastProductionSha_returns_null_and_logs_on_db_exception(): void
    {
        $this->failConfigQueries();
        Log::shouldReceive('warning')->once()->withArgs(
            fn($msg, $ctx) => str_contains($msg, 'Failed to get last production SHA') && str_contains($ctx['error'], 'config table unavailable')
        );

        $this->assertNull($this->service->getLastProductionSha());
    }

    public function test_saveLastProductionSha_inserts_then_updates(): void
    {
        $this->service->saveLastProductionSha('sha-one');
        $this->assertSame('sha-one', $this->configValue(self::SHA_KEY));
        $this->assertSame('sha-one', $this->service->getLastProductionSha());

        $this->service->saveLastProductionSha('sha-two');
        $this->assertSame('sha-two', $this->configValue(self::SHA_KEY));
        $this->assertSame(1, DB::table('config')->where('key', self::SHA_KEY)->count());
    }

    public function test_daysSinceLastProduction_is_null_with_no_row(): void
    {
        $this->assertNull($this->service->daysSinceLastProduction());
    }

    public function test_daysSinceLastProduction_is_null_even_with_a_row(): void
    {
        $this->service->saveLastProductionSha('abc');

        $this->assertNull($this->service->daysSinceLastProduction());
    }

    public function test_daysSinceLastProduction_is_null_on_db_exception(): void
    {
        $this->failConfigQueries();

        $this->assertNull($this->service->daysSinceLastProduction());
    }

    // sendNotification: interval logic.

    public static function intervalProvider(): array
    {
        return [
            'no row sends' => [null, false, true],
            'recent notification skips' => [-60, false, false],
            'recent notification but forced sends' => [-60, true, true],
            'exactly at interval sends' => [-3600, false, true],
            'old notification sends' => [-7200, false, true],
        ];
    }

    /**
     * @dataProvider intervalProvider
     */
    public function test_sendNotification_interval(?int $offsetSeconds, bool $force, bool $expectSent): void
    {
        $this->captureMail();
        if ($offsetSeconds !== null) {
            DB::table('config')->insert(['key' => self::NOTIFY_KEY, 'value' => (string) (time() + $offsetSeconds)]);
        }

        $result = $this->service->sendNotification(
            $this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES),
            $force
        );

        $this->assertSame($expectSent, $result);
        $this->assertSame($expectSent, !empty($this->sent));
    }

    public function test_sendNotification_skipped_does_not_touch_timestamp(): void
    {
        $this->captureMail();
        $recent = (string) (time() - 60);
        DB::table('config')->insert(['key' => self::NOTIFY_KEY, 'value' => $recent]);

        $this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT));

        $this->assertSame($recent, $this->configValue(self::NOTIFY_KEY));
    }

    public function test_sendNotification_updates_timestamp_after_send(): void
    {
        $this->captureMail();
        $before = time();

        $this->assertTrue($this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT)));

        $this->assertGreaterThanOrEqual($before, (int) $this->configValue(self::NOTIFY_KEY));
        $this->assertLessThanOrEqual(time(), (int) $this->configValue(self::NOTIFY_KEY));
    }

    public function test_sendNotification_sends_to_configured_address(): void
    {
        $this->captureMail();

        $this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT));

        $this->assertSame(['release-test@example.org'], $this->sent['to']);
    }

    public function test_sendNotification_returns_false_when_mail_throws(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp down'));
        Log::shouldReceive('error')->once();

        $this->assertFalse($this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT)));
        $this->assertNull($this->configValue(self::NOTIFY_KEY));
    }

    // sendNotification: subject.

    public static function subjectProvider(): array
    {
        return [
            'urgent' => [AppReleaseClassifierService::CLASSIFICATION_URGENT, '🚨 App Release: URGENT - Immediate promotion recommended'],
            'can wait' => [AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT, '📦 App Release: Batched for Wednesday'],
            'no changes' => [AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES, 'ℹ️ App Release: No changes to promote'],
        ];
    }

    /**
     * @dataProvider subjectProvider
     */
    public function test_sendNotification_subject(string $classification, string $subject): void
    {
        $this->captureMail();

        $this->service->sendNotification($this->classifierResult($classification), true);

        $this->assertSame($subject, $this->sent['subject']);
        $this->assertStringContainsString("Classification: $classification", $this->sent['body']);
        $this->assertStringContainsString('Reason: because', $this->sent['body']);
    }

    // sendNotification: body.

    public function test_sendNotification_unknown_classification_throws_unhandled_match(): void
    {
        $this->captureMail();

        $this->expectException(\UnhandledMatchError::class);
        $this->service->sendNotification($this->classifierResult('BOGUS'), true);
    }

    public function test_sendNotification_urgent_details_present(): void
    {
        $this->captureMail();
        $commits = $this->commits(3);
        $urgent = [['hash' => 'hf1', 'author' => 'a', 'date' => '2026-10-20', 'message' => 'hotfix: boom']];

        $this->service->sendNotification(
            $this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_URGENT, $commits, $urgent),
            true
        );

        $body = $this->sent['body'];
        $this->assertStringContainsString("Urgent commits: 1\n", $body);
        $this->assertStringContainsString("Urgent commit details:\n  - hf1: hotfix: boom\n", $body);
        $this->assertStringContainsString('Commits since last production: 3', $body);
    }

    public function test_sendNotification_urgent_details_absent(): void
    {
        $this->captureMail();

        $this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT, $this->commits(2)), true);

        $this->assertStringNotContainsString('Urgent commits:', $this->sent['body']);
        $this->assertStringNotContainsString('Urgent commit details', $this->sent['body']);
    }

    public function test_sendNotification_missing_commit_keys_count_as_zero(): void
    {
        $this->captureMail();

        $this->service->sendNotification(
            ['classification' => AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES, 'reason' => 'none'],
            true
        );

        $this->assertStringContainsString('Commits since last production: 0', $this->sent['body']);
    }

    public static function commitCountProvider(): array
    {
        return [
            'zero' => [0, false, false, 0],
            'one' => [1, true, false, 1],
            'twenty (boundary)' => [20, true, false, 20],
            'twenty one (truncated)' => [21, false, true, 20],
            'thirty' => [30, false, true, 20],
        ];
    }

    /**
     * @dataProvider commitCountProvider
     */
    public function test_sendNotification_commit_list(int $count, bool $expectAll, bool $expectTruncated, int $expectListed): void
    {
        $this->captureMail();

        $this->service->sendNotification(
            $this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT, $this->commits($count)),
            true
        );

        $body = $this->sent['body'];
        $this->assertStringContainsString("Commits since last production: $count", $body);
        $this->assertSame($expectAll, str_contains($body, "All commits:\n"));
        $this->assertSame($expectTruncated, str_contains($body, "Recent commits (showing first 20 of $count):\n"));
        $this->assertSame($expectListed, substr_count($body, '  - 2026-10-20 hash'));
        if ($expectListed > 0) {
            $this->assertStringContainsString("  - 2026-10-20 hash1: feat: change 1\n", $body);
        }
        // The 21st commit is cut off; the 20th is the last shown.
        $this->assertFalse(str_contains($body, 'hash21:'));
        $this->assertStringContainsString('Generated: ', $body);
    }

    public static function footerProvider(): array
    {
        return [
            'urgent has manual promote footer' => [AppReleaseClassifierService::CLASSIFICATION_URGENT, true],
            'can wait has none' => [AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT, false],
            'no changes has none' => [AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES, false],
        ];
    }

    /**
     * @dataProvider footerProvider
     */
    public function test_sendNotification_manual_promote_footer(string $classification, bool $expected): void
    {
        $this->captureMail();

        $this->service->sendNotification($this->classifierResult($classification), true);

        $this->assertSame($expected, str_contains($this->sent['body'], 'run_manual_promote = true'));
        $this->assertSame($expected, str_contains($this->sent['body'], 'To manually trigger production promotion'));
    }

    public function test_sendNotification_with_mail_fake_returns_true_and_records_timestamp(): void
    {
        Mail::fake();

        $this->assertTrue($this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT)));
        $this->assertNotNull($this->configValue(self::NOTIFY_KEY));
        // Within the interval now, so a second unforced call is skipped.
        $this->assertFalse($this->service->sendNotification($this->classifierResult(AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT)));
    }

    // triggerManualPromotion.

    public static function missingTokenProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    /**
     * @dataProvider missingTokenProvider
     */
    public function test_triggerManualPromotion_token_not_configured(?string $token): void
    {
        Http::fake();
        config(['freegle.app_release.circleci_token' => $token]);

        $result = $this->service->triggerManualPromotion();

        $this->assertFalse($result['success']);
        $this->assertSame('CircleCI token not configured', $result['message']);
        Http::assertNothingSent();
    }

    public function test_triggerManualPromotion_success(): void
    {
        config(['freegle.app_release.circleci_token' => 'tok', 'freegle.app_release.circleci_project' => 'gh/Org/repo']);
        Http::fake(['circleci.com/*' => Http::response(['id' => 'pipe-123'], 201)]);

        $result = $this->service->triggerManualPromotion();

        $this->assertTrue($result['success']);
        $this->assertSame('pipe-123', $result['pipeline_id']);
        $this->assertSame('Pipeline triggered: pipe-123', $result['message']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://circleci.com/api/v2/project/gh/Org/repo/pipeline'
                && $request->hasHeader('Authorization', 'Bearer tok')
                && $request['branch'] === 'production'
                && $request['parameters']['run_manual_promote'] === true;
        });
    }

    public static function failureStatusProvider(): array
    {
        return [
            '401' => [401],
            '404' => [404],
            '500' => [500],
        ];
    }

    /**
     * @dataProvider failureStatusProvider
     */
    public function test_triggerManualPromotion_non_2xx(int $status): void
    {
        config(['freegle.app_release.circleci_token' => 'tok', 'freegle.app_release.circleci_project' => 'gh/Org/repo']);
        Http::fake(['circleci.com/*' => Http::response(['message' => 'nope'], $status)]);

        $result = $this->service->triggerManualPromotion();

        $this->assertFalse($result['success']);
        $this->assertSame("CircleCI API error: $status", $result['message']);
        $this->assertArrayNotHasKey('pipeline_id', $result);
    }

    public function test_triggerManualPromotion_connection_exception(): void
    {
        config(['freegle.app_release.circleci_token' => 'tok', 'freegle.app_release.circleci_project' => 'gh/Org/repo']);
        Http::fake(fn() => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $result = $this->service->triggerManualPromotion();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection refused', $result['message']);
    }
}
