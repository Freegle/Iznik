<?php

namespace Tests\Unit\Mail;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Enumerates every concrete Mailable under app/Mail (plan 2026-09-27-lockdown-switch.md,
 * section 11.8) and requires each one to be accounted for in exactly one of three lists
 * below:
 *
 * - IMPLEMENTERS: implements DescribesMemberContent, so lockdown:filter-spool can check
 *   whether the content it names is still fit to send once email resumes after a hold.
 * - EXEMPT: genuinely carries no member-authored content - a system/ops/admin mail, a
 *   static template, or one that is only ever about the recipient's own account or
 *   transaction.
 * - KNOWN_GAPS: does carry member-authored content (a chat message, a post, a ChitChat
 *   item, or free text a member or moderator wrote) but no id is currently threaded
 *   through the code to let filter-spool check it. Documented here with the reason,
 *   rather than silently missed.
 *
 * A newly added Mailable that is not in one of these three lists fails this test - the
 * point is that a new mail type quoting member content cannot silently go unfiltered by
 * lockdown. A newly added implementer must also actually implement the interface, and an
 * EXEMPT/KNOWN_GAPS entry must not.
 */
class DescribesMemberContentEnumerationTest extends TestCase
{
    private const IMPLEMENTERS = [
        \App\Mail\Digest\UnifiedDigest::class,
        \App\Mail\Matched\MatchedPosts::class,
        \App\Mail\Newsfeed\NewsfeedModNotifMail::class,
        \App\Mail\Newsfeed\NewsfeedDigestMail::class,
        \App\Mail\Notification\ChaseUpMail::class,
        \App\Mail\Message\AutoRepostWarning::class,
        \App\Mail\Message\ChaseUp::class,
        \App\Mail\Message\ChaseUpPromised::class,
        \App\Mail\Message\ModStdMessageMail::class,
        \App\Mail\Newsfeed\ChitchatReportMail::class,
        \App\Mail\Chat\ChatNotification::class,
        \App\Mail\Message\DeadlineReached::class,
        \App\Mail\Ripple\RippleIntroMail::class,
        \App\Mail\Stories\StoriesNewsletterMail::class,
        \App\Mail\Stories\StoriesToCentralMail::class,
        \App\Mail\Volunteering\VolunteeringDigestMail::class,
        \App\Mail\Volunteering\VolunteeringRenewMail::class,
        \App\Mail\Event\EventsDigestMail::class,
        \App\Mail\Donation\AskForDonation::class,
        \App\Mail\Tryst\TrystCalendarInviteMail::class,
    ];

    private const EXEMPT = [
        \App\Mail\Group\AlertNoMessagesMail::class,
        \App\Mail\Group\BoundaryErrorMail::class,
        \App\Mail\Group\ClosedGroupReminderMail::class,
        \App\Mail\Group\CustomisationReminderMail::class,
        \App\Mail\Housekeeper\HousekeeperResultsMail::class,
        \App\Mail\LoveJunk\TnInvoiceMail::class,
        \App\Mail\Noticeboard\NoticeboardThankMail::class,
        \App\Mail\Reengage\ReengageMail::class,
        \App\Mail\Session\ForgotPasswordMail::class,
        \App\Mail\Session\LoginLinkMail::class,
        \App\Mail\Session\MergeOfferMail::class,
        \App\Mail\Session\UnsubscribeConfirmMail::class,
        \App\Mail\Session\UnsubscribedNotice::class,
        \App\Mail\Session\VerifyEmailMail::class,
        \App\Mail\Stories\AskMail::class,
        \App\Mail\Chat\ChatReviewPendingMail::class,
        \App\Mail\AI\AIImageReviewDigestMail::class,
        \App\Mail\Admin\AdminMail::class,
        \App\Mail\Admin\ChaseAdminMail::class,
        \App\Mail\Admin\ModNotifMail::class,
        \App\Mail\Alert\AlertMail::class,
        \App\Mail\Birthday\BirthdayMail::class,
        \App\Mail\Charity\CharitySignupMail::class,
        \App\Mail\Deferrals\UnreadChatCatchUpMail::class,
        \App\Mail\Digest\DigestReplyNotice::class,
        \App\Mail\Donation\DonateExternalMail::class,
        \App\Mail\Donation\DonationSummaryMail::class,
        \App\Mail\Donation\DonationThankPrepMail::class,
        \App\Mail\Donation\DonationThankYou::class,
        \App\Mail\Donation\GiftAidChaseUp::class,
        \App\Mail\Engage\EngageMail::class,
        \App\Mail\Fbl\FblNotification::class,
        \App\Mail\Welcome\WelcomeMail::class,
    ];

    /**
     * Class => the reason it carries member content but has no id threaded through yet.
     */
    private const KNOWN_GAPS = [
        \App\Mail\Group\WelcomeReviewMail::class => "quotes a group's custom welcome text (groups.welcomemail), moderator-authored, no author id threaded through",
        \App\Mail\Welcome\GroupWelcomeMail::class => 'same gap as WelcomeReviewMail - quotes groups.welcomemail, no author id threaded through',
        \App\Mail\Chat\ChaseupModsMail::class => 'textSummary/chatUrl describe chat content in prose, no chatId threaded through',
        \App\Mail\Chat\ChatReviewSummaryMail::class => 'summary is prose built from chat content, no per-message ids threaded through',
        \App\Mail\Chat\SpamWarningMail::class => 'messageSubject is prose, no numeric message id threaded through',
        \App\Mail\CommunityNews\CommunityNewsMail::class => 'quotes a users_stories entry via CommunityNewsEmailService::pickStory(), neither story id nor author id threaded through',
    ];

    public function test_every_concrete_mailable_is_classified(): void
    {
        $found = $this->concreteMailableClasses();
        $classified = array_merge(self::IMPLEMENTERS, self::EXEMPT, array_keys(self::KNOWN_GAPS));

        $unclassified = array_diff($found, $classified);
        $this->assertEmpty(
            $unclassified,
            "New Mailable(s) not classified in " . static::class . " - add to IMPLEMENTERS, "
            . "EXEMPT, or KNOWN_GAPS: " . implode(', ', $unclassified)
        );

        // A class listed here that no longer extends MjmlMailable (renamed, removed) would
        // otherwise sit stale forever, silently proving nothing.
        $stale = array_diff($classified, $found);
        $this->assertEmpty(
            $stale,
            'Class(es) listed in this test no longer exist as a concrete Mailable under app/Mail - '
            . 'remove from IMPLEMENTERS, EXEMPT or KNOWN_GAPS: ' . implode(', ', $stale)
        );
    }

    public function test_no_class_is_listed_twice(): void
    {
        $all = array_merge(self::IMPLEMENTERS, self::EXEMPT, array_keys(self::KNOWN_GAPS));

        $this->assertCount(
            count($all),
            array_unique($all),
            'A class appears in more than one of IMPLEMENTERS/EXEMPT/KNOWN_GAPS'
        );
    }

    public function test_implementers_implement_the_interface(): void
    {
        foreach (self::IMPLEMENTERS as $class) {
            $this->assertContains(
                DescribesMemberContent::class,
                class_implements($class) ?: [],
                "{$class} is listed as an IMPLEMENTER but does not implement DescribesMemberContent"
            );
        }
    }

    public function test_exempt_and_known_gaps_do_not_implement_the_interface(): void
    {
        foreach (array_merge(self::EXEMPT, array_keys(self::KNOWN_GAPS)) as $class) {
            $this->assertNotContains(
                DescribesMemberContent::class,
                class_implements($class) ?: [],
                "{$class} implements DescribesMemberContent but is listed as EXEMPT or a KNOWN GAP - "
                . 'move it to IMPLEMENTERS and give it an about() method'
            );
        }
    }

    /**
     * Every concrete Mailable under app/Mail, found by walking the directory rather than a
     * fixed list - so a class added later and never mentioned here trips the classification
     * test above instead of passing by omission.
     *
     * "Concrete Mailable" means a class extending MjmlMailable: that generically excludes
     * the interfaces/traits under Contracts/ and Traits/ (class_exists() is false for those)
     * and helper classes such as MjmlMailable itself and Digest/DigestStyle.php (they exist
     * as classes but do not extend MjmlMailable).
     *
     * @return string[] Fully-qualified class names.
     */
    private function concreteMailableClasses(): array
    {
        $classes = [];

        foreach (File::allFiles(app_path('Mail')) as $file) {
            $relative = $file->getRelativePathname();
            $class = 'App\\Mail\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!class_exists($class)) {
                // Interfaces (Contracts/) and traits (Traits/) are not classes.
                continue;
            }

            if (is_subclass_of($class, MjmlMailable::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
