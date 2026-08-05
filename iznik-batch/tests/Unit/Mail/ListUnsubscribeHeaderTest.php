<?php

namespace Tests\Unit\Mail;

use App\Mail\MjmlMailable;
use App\Models\User;
use App\Services\UnsubscribeService;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Regression guard for the List-Unsubscribe header.
 *
 * Reported 2026-08-05: a member clicked Unsubscribe on a daily digest, was told by her
 * mail client that it had worked, and instead got an auto-reply from noreply@ saying the
 * mailbox was not monitored and to contact support. Nothing was unsubscribed. Both arms
 * of the header were broken:
 *  - the mailto: pointed at noreply@ilovefreegle.org, whose MX is Google Workspace, so it
 *    never reached us at all;
 *  - the https: pointed at a front-end page that answers the RFC 8058 POST with a 200 and
 *    does nothing, which mail clients report as a successful unsubscribe.
 */
class ListUnsubscribeHeaderTest extends TestCase
{
    private function mailableFor(User $user, ?string $type): MjmlMailable
    {
        return new class($user->id, $type) extends MjmlMailable
        {
            public function __construct(private int $uid, private ?string $type)
            {
                parent::__construct();
            }

            protected function getSubject(): string
            {
                return 'Test';
            }

            protected function getRecipientUserId(): ?int
            {
                return $this->uid;
            }

            protected function unsubscribeType(): ?string
            {
                return $this->type;
            }

            public function exposeHeaders(Email $message): void
            {
                $this->addListUnsubscribeHeaders($message);
            }
        };
    }

    private function headerFor(User $user, ?string $type): ?string
    {
        $message = new Email;
        $this->mailableFor($user, $type)->exposeHeaders($message);
        $header = $message->getHeaders()->get('List-Unsubscribe');

        return $header?->getBodyAsString();
    }

    public function test_mailto_arm_goes_to_a_domain_we_actually_receive_mail_for(): void
    {
        $user = $this->createTestUser();

        $header = $this->headerFor($user, UnsubscribeService::TYPE_DIGEST);

        $this->assertNotNull($header);
        $this->assertStringContainsString('@'.config('freegle.mail.user_domain'), $header);
        $this->assertStringNotContainsString(
            config('freegle.mail.noreply_addr'),
            $header,
            'noreply@ilovefreegle.org has Google Workspace MX - mail sent there never reaches our handler'
        );
    }

    public function test_mailto_arm_is_the_keyed_address_the_incoming_handler_parses(): void
    {
        $user = $this->createTestUser();

        $header = $this->headerFor($user, UnsubscribeService::TYPE_DIGEST);

        // Must match the pattern in IncomingMailService::handleOneClickUnsubscribe().
        $this->assertMatchesRegularExpression(
            '/<mailto:unsubscribe-'.$user->id.'-[^-]+-digest@/',
            $header
        );
    }

    public function test_https_arm_is_the_apiv2_endpoint_that_actions_the_opt_out(): void
    {
        $user = $this->createTestUser();

        $header = $this->headerFor($user, UnsubscribeService::TYPE_DIGEST);

        $this->assertStringContainsString('/user/unsubscribe?', $header);
        $this->assertStringContainsString('t=digest', $header);
        $this->assertStringContainsString('u='.$user->id, $header);
        $this->assertDoesNotMatchRegularExpression(
            '#<https?://[^>]*/unsubscribe/\d+>#',
            $header,
            'The front-end page answers the RFC 8058 POST with 200 and does nothing'
        );
    }

    public function test_both_arms_carry_the_same_category(): void
    {
        $user = $this->createTestUser();

        $header = $this->headerFor($user, UnsubscribeService::TYPE_CHAT);

        $this->assertStringContainsString('-chat@', $header);
        $this->assertStringContainsString('t=chat', $header);
    }

    public function test_both_arms_carry_the_same_key(): void
    {
        $user = $this->createTestUser();

        $header = $this->headerFor($user, UnsubscribeService::TYPE_DIGEST);

        preg_match('/mailto:unsubscribe-\d+-([^-]+)-/', $header, $mailtoKey);
        preg_match('/[?&]k=([^&>]+)/', $header, $urlKey);

        $this->assertNotEmpty($mailtoKey[1] ?? null);
        $this->assertSame($mailtoKey[1], $urlKey[1] ?? null);
    }

    public function test_one_click_post_header_is_present(): void
    {
        $user = $this->createTestUser();
        $message = new Email;

        $this->mailableFor($user, UnsubscribeService::TYPE_DIGEST)->exposeHeaders($message);

        $this->assertSame(
            'List-Unsubscribe=One-Click',
            $message->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString()
        );
    }

    public function test_transactional_mail_carries_no_unsubscribe_headers(): void
    {
        $user = $this->createTestUser();
        $message = new Email;

        $this->mailableFor($user, null)->exposeHeaders($message);

        $this->assertFalse($message->getHeaders()->has('List-Unsubscribe'));
        $this->assertFalse($message->getHeaders()->has('List-Unsubscribe-Post'));
    }

    public function test_no_headers_without_a_known_recipient(): void
    {
        $mailable = new class extends MjmlMailable
        {
            protected function getSubject(): string
            {
                return 'Test';
            }

            public function exposeHeaders(Email $message): void
            {
                $this->addListUnsubscribeHeaders($message);
            }
        };

        $message = new Email;
        $mailable->exposeHeaders($message);

        $this->assertFalse($message->getHeaders()->has('List-Unsubscribe'));
    }

    public function test_declared_categories_are_all_valid(): void
    {
        // A typo in a mailable's category would fall back to turning everything off,
        // which is a much bigger hammer than the member asked for.
        $files = glob(app_path('Mail/*/*.php'));
        $checked = 0;

        foreach ($files as $file) {
            $src = file_get_contents($file);
            if (! preg_match('/unsubscribeType\(\): \?string\s*\{\s*return\s+([^;]+);/s', $src, $m)) {
                continue;
            }
            $checked++;
            $expr = trim($m[1]);
            if ($expr === 'null') {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/^UnsubscribeService::TYPE_[A-Z]+$/',
                $expr,
                basename($file).' must use an UnsubscribeService constant'
            );
            $value = constant('App\Services\UnsubscribeService::'.substr($expr, strlen('UnsubscribeService::')));
            $this->assertTrue(UnsubscribeService::isValidType($value), basename($file)." declares unknown category $value");
        }

        $this->assertGreaterThan(20, $checked, 'Expected most mailables to declare a category');
    }
}
