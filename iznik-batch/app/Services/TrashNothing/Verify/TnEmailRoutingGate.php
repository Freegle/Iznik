<?php

namespace App\Services\TrashNothing\Verify;

use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\TrashNothing\Ingestion\TnChatMessage;

/**
 * Decides whether an inbound email should skip the legacy routing path because
 * TN posts are now ingested via the API instead.
 *
 * This is the "switch off the email path" half of plans/tn-api-post-ingestion.md
 * section S. It lives in the *callers* of IncomingMailService (the controller and
 * the CLI command), never inside the service itself, for the same reason section
 * I gives for emitting Loki there: IncomingMailService is frozen, and the caller
 * is the correct seam for a decision about whether to route at all.
 *
 * Both callers archive the raw email BEFORE routing (IncomingMailController::
 * receive(), IncomingMailCommand::handle()), so skipping routing does not stop
 * archiving — which is precisely what makes the archive usable as an independent
 * inventory for tn:verify-email-coverage.
 *
 * THE PREDICATE MUST STAY NARROW: anything it matches stops being routed, so
 * matching too widely silently drops mail. It must therefore admit only what
 * route() would have delivered to handleGroupPost() — Phase 5 — and nothing an
 * earlier phase would have claimed first. claimedByAnEarlierPhase() mirrors
 * route() (IncomingMailService.php:94-176) phase by phase, in its order; the
 * cases most easily missed are:
 *
 *   - Phase 2, chat replies — isChatNotificationReply(), i.e. notify- addresses
 *     (IncomingMailService.php:118), and the separate replyto- branch
 *     (IncomingMailService.php:121). TN chat replies still arrive by email and
 *     remain in scope (section E), so this one matters most.
 *   - Phases 3/3b/3c, bounces and digest replies. These are the other exclusions
 *     with real side effects: routing a bounce records it (and can turn a
 *     member's mail off), and a digest reply gets an explanatory auto-response.
 *     Skipping either would lose that work with nothing on the API side to
 *     replace it.
 *   - Phase 4, volunteer/auto mail — isToVolunteers/isToAuto
 *     (IncomingMailService.php:176). Easy to miss: MailParserService strips the
 *     '-volunteers'/'-auto' suffix and still reports a targetGroupName, so
 *     testing targetGroupName alone would swallow these.
 *   - Phase 5, group posts — targetGroupName !== null (IncomingMailService.php:179).
 *
 * Phase 1's system addresses (readreceipt-, handover-, eventsoff-, subscribe,
 * …) are not excluded explicitly. They are unreachable for this predicate in
 * practice because TN posts are delivered to plain group addresses and never
 * carry the post header on a command address; and the TN post header
 * requirement means an ordinary member's mail to any of them is unaffected.
 *
 * The one route() check deliberately NOT mirrored is isKnownSpammer()
 * (IncomingMailService.php:289), because it is the only one that needs database
 * state — and mirroring it would buy nothing. It ends in DROPPED, so the email
 * path creates nothing either way, and the API path ingests from TN regardless
 * of what arrives by email; excluding it here could not stop a known spammer's
 * TN post going live. (If that matters it needs a spam check inside the API
 * ingestion path, which today has none.) Keeping the gate free of queries also
 * keeps ArchiveInventoryService's per-email scan query-free.
 */
class TnEmailRoutingGate
{
    /**
     * Recorded in the archive file's routing_outcome in place of the
     * RoutingResult that route() would have produced, so a skipped email is
     * distinguishable from one that arrived before the cutover (or from a
     * crashed run that never recorded an outcome at all).
     *
     * Deliberately NOT a RoutingResult value: nothing routed it, and reusing
     * e.g. 'Dropped' would misreport it to anything reading the archive.
     */
    public const OUTCOME_SKIPPED = 'SkippedTnApi';

    /**
     * True when this email is a TN group post AND the cutover flag is set.
     *
     * The flag is the same one that turns the API path on
     * (FREEGLE_TN_INGEST_POSTS_VIA_API): switching the email path off is one half
     * of a single cutover, and either half alone is wrong — email-off with the API
     * off drops TN posts entirely, API-on with email still routing double-writes
     * them.
     */
    public function shouldSkipRouting(ParsedEmail $email): bool
    {
        if (! config('freegle.trashnothing.ingest_posts_via_api', false)) {
            return false;
        }

        return $this->isTrashNothingGroupPost($email);
    }

    /**
     * The narrow predicate itself, flag-independent so it can be reasoned about
     * (and tested) without config in the way.
     */
    public function isTrashNothingGroupPost(ParsedEmail $email): bool
    {
        if ($email->getTrashNothingPostId() === null) {
            return false;
        }

        if ($email->targetGroupName === null) {
            return false;
        }

        return ! $this->claimedByAnEarlierPhase($email);
    }

    /**
     * The command addresses route() hands to routeSystemAddress() (Phase 1) or claims in
     * Phases 2 and 3 by local part. Several end in digits, like a member's address, so direct
     * mail must be told apart from them by prefix.
     */
    private const COMMAND_PREFIXES = [
        'readreceipt-', 'handover-', 'digestoff-', 'eventsoff-', 'newslettersoff-', 'relevantoff-',
        'volunteeringoff-', 'notificationmailsoff-', 'unsubscribe-', 'replyto-', 'bounce-', 'notify-',
    ];

    /**
     * Whether this is a TN member's chat message that TN's chat-messages feed carries: the
     * flag-independent predicate of plans/2026-11-12-tn-api-chat-ingestion.md section 4.3.
     */
    public function isTrashNothingChatMessage(ParsedEmail $email): bool
    {
        return $this->trashNothingChatKind($email) !== null;
    }

    /**
     * Which row of the chat plan's section 1 a TN member's email is, as a TnChatMessage kind, or
     * null when it is not one the feed carries. Both conditions must hold:
     *
     *   - It comes from a TN member: From at user.trashnothing.com, and a TN envelope as
     *     isFromTrashNothing() reads it. TN's own notices (row 9) come from noreply@trashnothing.com
     *     and so stay on email. TN puts no secret on reply mail, so the gate cannot ask for it; a
     *     Freegle member's mail never comes from a TN address, so it is never matched.
     *   - route() would hand it to a handler the feed covers, in route()'s own phase order, and
     *     that handler would write it. Bounces, read receipts and auto-replies are left to the
     *     email path, which drops or handles them before writing anything.
     */
    public function trashNothingChatKind(ParsedEmail $email): ?string
    {
        if (! $this->isFromTrashNothingMember($email)) {
            return null;
        }

        $localPart = strtolower(explode('@', $email->envelopeTo)[0] ?? '');

        // Phase 1, system addresses.
        if ($localPart === 'fbl' || $email->isSubscribeCommand() || $email->isUnsubscribeCommand()) {
            return null;
        }
        foreach (self::COMMAND_PREFIXES as $prefix) {
            if ($prefix !== 'notify-' && str_starts_with($localPart, $prefix)) {
                return null;
            }
        }

        // Phase 2, a reply in a chat (row 1), less what handleChatNotificationReply drops first.
        if ($email->isChatNotificationReply()) {
            if ($this->isReadReceipt($email) || $email->isBounce() || $email->isAutoSubmitted() || $email->isAutoReply()) {
                return null;
            }

            return TnChatMessage::KIND_CHAT_REPLY;
        }

        // Phases 3 to 3c and the filters before Phase 4.
        if ($email->isBounce() || $email->isDigestReply() || $email->isAutoReply()) {
            return null;
        }
        $envelopeFrom = strtolower($email->envelopeFrom);
        if ($envelopeFrom !== '' && $envelopeFrom === strtolower($email->envelopeTo)) {
            return null;
        }

        // Phase 4, to a community's volunteers (rows 4 and 5). Not -auto@, which is not a TN route.
        if ($email->isToVolunteers) {
            return str_starts_with($email->subject ?? '', 'Reporting ')
                ? TnChatMessage::KIND_REPORT
                : TnChatMessage::KIND_VOLUNTEERS;
        }
        if ($email->isToAuto || $email->targetGroupName !== null) {
            return null;
        }

        // Phase 6, direct mail to a member's address on our user domain (rows 2 and 3).
        $userDomain = preg_quote((string) config('freegle.mail.user_domain', 'users.ilovefreegle.org'), '/');
        if (! preg_match('/-\d+@' . $userDomain . '$/i', $email->envelopeTo)) {
            return null;
        }

        return $email->getHeader('x-fd-msgid') !== null
            ? TnChatMessage::KIND_POST_REPLY
            : TnChatMessage::KIND_USER_MESSAGE;
    }

    private function isFromTrashNothingMember(ParsedEmail $email): bool
    {
        $domain = '@user.' . strtolower((string) config('freegle.mail.trashnothing_domain', 'trashnothing.com'));

        return str_ends_with(strtolower(trim((string) $email->fromAddress)), $domain) && $email->isFromTrashNothing();
    }

    /** IncomingMailService::isReadReceipt(). */
    private function isReadReceipt(ParsedEmail $email): bool
    {
        return str_contains(strtolower($email->getHeader('content-type') ?? ''), 'disposition-notification')
            || str_contains(strtolower($email->getHeader('content-disposition') ?? ''), 'notification');
    }

    /**
     * True when route() would have dealt with this email before Phase 5 ever
     * looked at targetGroupName — see the class docblock. Ordered to match
     * route() itself so the two can be read side by side.
     */
    private function claimedByAnEarlierPhase(ParsedEmail $email): bool
    {
        // Computed exactly as route() computes it (IncomingMailService.php:128).
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Phase 2 — chat notification replies and replyto- addresses.
        if ($email->isChatNotificationReply() || str_starts_with($localPart, 'replyto-')) {
            return true;
        }

        // Phase 3 / 3b — DSNs, and human replies to a bounce return-path address.
        if ($email->isBounce() || str_starts_with($localPart, 'bounce-')) {
            return true;
        }

        // Phase 3c — replies to a digest, which get an explanatory auto-response.
        if ($email->isDigestReply()) {
            return true;
        }

        // shouldDropSender() (IncomingMailService.php:260).
        if (strtolower($email->fromAddress ?? '') === 'info@twitter.com') {
            return true;
        }

        if ($email->isAutoReply()) {
            return true;
        }

        // isSelfSent() (IncomingMailService.php:278).
        $envelopeFrom = strtolower($email->envelopeFrom);
        if ($envelopeFrom !== '' && $envelopeFrom === strtolower($email->envelopeTo)) {
            return true;
        }

        // Phase 4 — volunteer/auto mail.
        return $email->isToVolunteers || $email->isToAuto;
    }
}
