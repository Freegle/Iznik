<?php

namespace App\Services\TrashNothing\Verify;

use App\Services\Mail\Incoming\ParsedEmail;

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
 * earlier phase would have claimed first:
 *
 *   - Phase 2, chat replies — isChatNotificationReply(), i.e. notify-/replyto-
 *     addresses (IncomingMailService.php:118). TN chat replies still arrive by
 *     email and remain in scope (section E), so this one matters most.
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

        // Claimed by an earlier phase than handleGroupPost() — see class docblock.
        if ($email->isToVolunteers || $email->isToAuto) {
            return false;
        }

        return ! $email->isChatNotificationReply();
    }
}
