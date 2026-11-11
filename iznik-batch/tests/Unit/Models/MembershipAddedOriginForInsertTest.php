<?php

namespace Tests\Unit\Models;

use App\Models\Membership;
use Tests\TestCase;

class MembershipAddedOriginForInsertTest extends TestCase
{
    /** Records what reaches Sentry. */
    private object $sentry;

    protected function setUp(): void
    {
        parent::setUp();

        // A recorder rather than a mock, so a call with unexpected arguments is seen in the
        // assertion instead of throwing inside the code under test.
        $this->sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = [(string) $message, (string) $severity];
            }
        };
        $this->app->instance('sentry', $this->sentry);
    }

    public function test_a_known_origin_is_kept_without_a_report(): void
    {
        foreach (Membership::ADDED_ORIGINS as $origin) {
            if ($origin === Membership::ADDED_ORIGIN_UNKNOWN) {
                continue;
            }
            $this->assertSame($origin, Membership::addedOriginForInsert($origin, 'test', 1, 2));
        }

        $this->assertSame([], $this->sentry->calls);
    }

    public function test_unknown_or_unrecognised_is_written_as_unknown_and_reported_once_as_an_error(): void
    {
        foreach ([Membership::ADDED_ORIGIN_UNKNOWN, '', 'nonsense'] as $origin) {
            $this->sentry->calls = [];

            $this->assertSame(
                Membership::ADDED_ORIGIN_UNKNOWN,
                Membership::addedOriginForInsert($origin, 'SomeService::join', 11, 22),
            );

            $this->assertCount(1, $this->sentry->calls, "origin '{$origin}'");
            [$message, $severity] = $this->sentry->calls[0];
            $this->assertStringContainsString('SomeService::join', $message);
            $this->assertStringContainsString('user 11, group 22', $message);
            $this->assertSame((string) \Sentry\Severity::error(), $severity);
        }
    }
}
