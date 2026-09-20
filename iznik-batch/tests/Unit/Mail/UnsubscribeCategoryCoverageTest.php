<?php

namespace Tests\Unit\Mail;

use App\Mail\MjmlMailable;
use App\Services\UnsubscribeService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every mailable has to make a deliberate choice about unsubscribing, and that choice has
 * to survive people adding new mail later.
 *
 * MjmlMailable::unsubscribeType() has a default, so a new mailable that says nothing still
 * behaves safely - it carries a working List-Unsubscribe that turns everything off. But
 * "safe" is not "right": a new bulk mail would take away more than the member asked for,
 * and a new transactional mail would carry an unsubscribe link it should not have. Neither
 * shows up as a failure anywhere, which is how EventsDigestMail and VolunteeringDigestMail
 * came to sit uncategorised for as long as they did.
 *
 * So the full list is pinned here. Add a mailable and this test fails until you say which
 * category it belongs to - and the answer lands in the diff where a reviewer sees it.
 */
class UnsubscribeCategoryCoverageTest extends TestCase
{
    /**
     * Every MjmlMailable subclass and the category it unsubscribes from.
     * null means transactional: no List-Unsubscribe header at all.
     *
     * @var array<class-string,string|null>
     */
    private const EXPECTED = [
        \App\Mail\AI\AIImageReviewDigestMail::class => null,
        \App\Mail\Admin\AdminMail::class => null,
        \App\Mail\Admin\ChaseAdminMail::class => null,
        \App\Mail\Admin\ModNotifMail::class => null,
        \App\Mail\Alert\AlertMail::class => null,
        \App\Mail\Birthday\BirthdayMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Charity\CharitySignupMail::class => null,
        \App\Mail\Chat\ChaseupModsMail::class => null,
        \App\Mail\Chat\ChatNotification::class => UnsubscribeService::TYPE_CHAT,
        \App\Mail\Chat\ChatReviewPendingMail::class => null,
        \App\Mail\Chat\ChatReviewSummaryMail::class => null,
        \App\Mail\Chat\SpamWarningMail::class => null,
        // Sent once after a provider stops accepting our mail, in place of the
        // chat notifications we declined to send, so it belongs to the same
        // category those would have carried.
        \App\Mail\Deferrals\UnreadChatCatchUpMail::class => UnsubscribeService::TYPE_CHAT,
        \App\Mail\CommunityNews\CommunityNewsMail::class => UnsubscribeService::TYPE_NEWSLETTER,
        \App\Mail\Digest\DigestReplyNotice::class => null,
        \App\Mail\Digest\UnifiedDigest::class => UnsubscribeService::TYPE_DIGEST,
        \App\Mail\Donation\AskForDonation::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Donation\DonateExternalMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Donation\DonationSummaryMail::class => null,
        \App\Mail\Donation\DonationThankPrepMail::class => null,
        \App\Mail\Donation\DonationThankYou::class => null,
        \App\Mail\Donation\GiftAidChaseUp::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Engage\EngageMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Event\EventsDigestMail::class => UnsubscribeService::TYPE_EVENTS,
        \App\Mail\Fbl\FblNotification::class => null,
        \App\Mail\Group\AlertNoMessagesMail::class => null,
        \App\Mail\Group\BoundaryErrorMail::class => null,
        \App\Mail\Group\ClosedGroupReminderMail::class => null,
        \App\Mail\Group\CustomisationReminderMail::class => null,
        \App\Mail\Group\WelcomeReviewMail::class => null,
        \App\Mail\Housekeeper\HousekeeperResultsMail::class => null,
        \App\Mail\LoveJunk\TnInvoiceMail::class => null,
        \App\Mail\Matched\MatchedPosts::class => UnsubscribeService::TYPE_RELEVANT,
        \App\Mail\Message\AutoRepostWarning::class => null,
        \App\Mail\Message\ChaseUp::class => null,
        \App\Mail\Message\ChaseUpPromised::class => null,
        \App\Mail\Message\DeadlineReached::class => null,
        \App\Mail\Message\ModStdMessageMail::class => null,
        \App\Mail\Newsfeed\ChitchatReportMail::class => null,
        \App\Mail\Newsfeed\NewsfeedDigestMail::class => UnsubscribeService::TYPE_NOTIFICATIONS,
        \App\Mail\Newsfeed\NewsfeedModNotifMail::class => null,
        \App\Mail\Noticeboard\NoticeboardThankMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Notification\ChaseUpMail::class => UnsubscribeService::TYPE_NOTIFICATIONS,
        \App\Mail\Reengage\ReengageMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Ripple\RippleIntroMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Session\ForgotPasswordMail::class => null,
        \App\Mail\Session\LoginLinkMail::class => null,
        \App\Mail\Session\MergeOfferMail::class => null,
        \App\Mail\Session\UnsubscribeConfirmMail::class => null,
        \App\Mail\Session\UnsubscribedNotice::class => null,
        \App\Mail\Session\VerifyEmailMail::class => null,
        \App\Mail\Stories\AskMail::class => UnsubscribeService::TYPE_ENGAGEMENT,
        \App\Mail\Stories\StoriesNewsletterMail::class => UnsubscribeService::TYPE_NEWSLETTER,
        \App\Mail\Stories\StoriesToCentralMail::class => null,
        \App\Mail\Tryst\TrystCalendarInviteMail::class => null,
        \App\Mail\Volunteering\VolunteeringDigestMail::class => UnsubscribeService::TYPE_VOLUNTEERING,
        \App\Mail\Volunteering\VolunteeringRenewMail::class => UnsubscribeService::TYPE_VOLUNTEERING,
        \App\Mail\Welcome\GroupWelcomeMail::class => null,
        \App\Mail\Welcome\WelcomeMail::class => null,
    ];

    /**
     * The pinned list, plus whatever a deployment built on this codebase pins
     * for its own mailables in tests/Unit/Mail/unsubscribe-categories.deployment.php
     * (a file Freegle does not ship; it returns the same class => category shape).
     * Freegle's own entries stay authoritative: the overlay cannot override them.
     *
     * @return array<class-string,string|null>
     */
    private function expected(): array
    {
        $overlay = __DIR__.'/unsubscribe-categories.deployment.php';
        if (! is_file($overlay)) {
            return self::EXPECTED;
        }

        $extra = require $overlay;
        $this->assertIsArray($extra, 'the deployment overlay must return an array');

        return self::EXPECTED + $extra;
    }

    /**
     * @return class-string[]
     */
    private function allMailables(): array
    {
        $found = [];

        foreach (glob(app_path('Mail/*/*.php')) as $file) {
            $src = file_get_contents($file);
            if (! preg_match('/^namespace ([^;]+);/m', $src, $ns)) {
                continue;
            }
            $class = $ns[1].'\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(MjmlMailable::class)) {
                continue;
            }
            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    public function test_every_mailable_has_a_declared_category(): void
    {
        $undeclared = [];

        foreach ($this->allMailables() as $class) {
            if (! array_key_exists($class, $this->expected())) {
                $undeclared[] = $class;

                continue;
            }

            $method = new \ReflectionMethod($class, 'unsubscribeType');
            $this->assertSame(
                $class,
                $method->getDeclaringClass()->getName(),
                $class.' must declare unsubscribeType() itself rather than inheriting the default'
            );
        }

        $this->assertSame([], $undeclared,
            "New mailable(s) with no unsubscribe category:\n  ".implode("\n  ", $undeclared).
            "\n\nDecide which UnsubscribeService::TYPE_* they belong to - or null if they are ".
            'transactional and should carry no List-Unsubscribe - declare it with '.
            'unsubscribeType(), and add them to self::EXPECTED. See '.
            'docs/developers/reference/unsubscribe.md.');
    }

    public function test_declared_categories_match_the_expected_list(): void
    {
        foreach ($this->allMailables() as $class) {
            $mailable = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod($class, 'unsubscribeType');
            $method->setAccessible(true);

            // array_key_exists, not ??: most entries are legitimately null (transactional),
            // and ?? would treat every one of those as missing.
            $this->assertTrue(
                array_key_exists($class, $this->expected()),
                $class.' is not pinned in this test'
            );

            $this->assertSame(
                $this->expected()[$class],
                $method->invoke($mailable),
                $class.' declares a different category from the one pinned in this test'
            );
        }
    }

    public function test_expected_list_has_no_stale_entries(): void
    {
        $gone = array_diff(array_keys($this->expected()), $this->allMailables());

        $this->assertSame([], array_values($gone),
            'These are listed but no longer exist - remove them: '.implode(', ', $gone));
    }

    public function test_every_declared_category_is_a_real_one(): void
    {
        foreach ($this->expected() as $class => $type) {
            if ($type === null) {
                continue;
            }
            $this->assertTrue(UnsubscribeService::isValidType($type), $class.' declares unknown category '.$type);
        }
    }
}
