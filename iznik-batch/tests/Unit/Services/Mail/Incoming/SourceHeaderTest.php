<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\ParsedEmail;
use ReflectionMethod;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * Tests for source header determination in incoming emails.
 *
 * The sourceheader field identifies where a message originated from:
 * - TN-native-app, TN-web-app: TrashNothing posts
 * - Yahoo-Web: Yahoo Groups web interface (historical)
 * - MessageMaker: Freegle Message Maker tool
 * - Platform: Posts from Freegle's own domains
 * - Yahoo-Email: Default for external email (historical name, not Yahoo-specific)
 */
class SourceHeaderTest extends TestCase
{
    use EmailFixtures;

    private IncomingMailService $service;

    private MailParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(MailParserService::class);
        $this->service = app(IncomingMailService::class);
    }

    /**
     * Helper to call the private determineSourceHeader method.
     */
    private function callDetermineSourceHeader(ParsedEmail $email): string
    {
        $method = new ReflectionMethod(IncomingMailService::class, 'determineSourceHeader');
        $method->setAccessible(true);

        return $method->invoke($this->service, $email);
    }

    // ========================================
    // X-Freegle-Source Header Tests
    // ========================================

    public function test_uses_x_freegle_source_header_when_present(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test item',
            'X-Freegle-Source' => 'CustomSource',
        ]);

        $parsed = $this->parser->parse($email, 'user@example.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('CustomSource', $this->callDetermineSourceHeader($parsed));
    }

    public function test_ignores_x_freegle_source_when_unknown(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test item',
            'X-Freegle-Source' => 'Unknown',
        ]);

        $parsed = $this->parser->parse($email, 'user@example.com', 'group@groups.ilovefreegle.org');

        // Should fall through to default (Yahoo-Email for external address)
        $this->assertEquals('Yahoo-Email', $this->callDetermineSourceHeader($parsed));
    }

    // ========================================
    // TrashNothing Source Header Tests
    // ========================================

    public function test_detects_trashnothing_native_app(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user-g123@user.trashnothing.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test item (Location AB1)',
            'X-trash-nothing-Source' => 'native-app',
            'X-trash-nothing-Post-ID' => '12345',
        ]);

        $parsed = $this->parser->parse($email, 'user-g123@user.trashnothing.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('TN-native-app', $this->callDetermineSourceHeader($parsed));
    }

    public function test_detects_trashnothing_web_app(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user-g456@user.trashnothing.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Another item (Location CD2)',
            'X-trash-nothing-Source' => 'web-app',
            'X-trash-nothing-Post-ID' => '67890',
        ]);

        $parsed = $this->parser->parse($email, 'user-g456@user.trashnothing.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('TN-web-app', $this->callDetermineSourceHeader($parsed));
    }

    public function test_detects_trashnothing_email_source(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user-g789@user.trashnothing.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Email item (Location EF3)',
            'X-trash-nothing-Source' => 'email',
        ]);

        $parsed = $this->parser->parse($email, 'user-g789@user.trashnothing.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('TN-email', $this->callDetermineSourceHeader($parsed));
    }

    // ========================================
    // X-Mailer Header Tests
    // ========================================

    public function test_detects_yahoo_groups_message_poster(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@yahoo.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Yahoo item',
            'X-Mailer' => 'Yahoo Groups Message Poster',
        ]);

        $parsed = $this->parser->parse($email, 'user@yahoo.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('Yahoo-Web', $this->callDetermineSourceHeader($parsed));
    }

    public function test_detects_freegle_message_maker(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Message Maker item',
            'X-Mailer' => 'Freegle Message Maker v1.0',
        ]);

        $parsed = $this->parser->parse($email, 'user@example.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('MessageMaker', $this->callDetermineSourceHeader($parsed));
    }

    // ========================================
    // Domain-based Default Tests
    // ========================================

    public function test_returns_platform_for_internal_domain(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@users.ilovefreegle.org',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Internal item',
        ]);

        $parsed = $this->parser->parse($email, 'user@users.ilovefreegle.org', 'group@groups.ilovefreegle.org');

        $this->assertEquals('Platform', $this->callDetermineSourceHeader($parsed));
    }

    public function test_returns_yahoo_email_for_external_domain(): void
    {
        // "Yahoo-Email" is the historical default name for any external email
        // (not actually Yahoo-specific anymore)
        $email = $this->createMinimalEmail([
            'From' => 'user@gmail.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: External item',
        ]);

        $parsed = $this->parser->parse($email, 'user@gmail.com', 'group@groups.ilovefreegle.org');

        $this->assertEquals('Yahoo-Email', $this->callDetermineSourceHeader($parsed));
    }

    // ========================================
    // Priority Tests (X-Freegle-Source takes precedence)
    // ========================================

    public function test_x_freegle_source_takes_priority_over_trashnothing(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user-g123@user.trashnothing.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test item',
            'X-Freegle-Source' => 'Freegle App',
            'X-trash-nothing-Source' => 'native-app',
        ]);

        $parsed = $this->parser->parse($email, 'user-g123@user.trashnothing.com', 'group@groups.ilovefreegle.org');

        // X-Freegle-Source should win
        $this->assertEquals('Freegle App', $this->callDetermineSourceHeader($parsed));
    }

    public function test_trashnothing_takes_priority_over_mailer(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user-g123@user.trashnothing.com',
            'To' => 'group@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test item',
            'X-trash-nothing-Source' => 'web-app',
            'X-Mailer' => 'Yahoo Groups Message Poster',
        ]);

        $parsed = $this->parser->parse($email, 'user-g123@user.trashnothing.com', 'group@groups.ilovefreegle.org');

        // TrashNothing header should win over X-Mailer
        $this->assertEquals('TN-web-app', $this->callDetermineSourceHeader($parsed));
    }
}
