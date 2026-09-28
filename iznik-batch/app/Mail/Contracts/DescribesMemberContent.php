<?php

namespace App\Mail\Contracts;

/**
 * A Mailable that quotes or links to member-authored content: a chat message, a post, a
 * ChitChat item, or the member who authored one of those (plan 2026-09-27-lockdown-switch.md,
 * section 11.8).
 *
 * {@see \App\Services\EmailSpoolerService::spool()} reads about() and writes it into the
 * spool file as the `about` field. `lockdown:filter-spool` reads that field back to decide
 * whether a waiting mail still describes content fit to send once email resumes after a
 * hold - a chat message that was never delivered, a post that never got Approved, a ChitChat
 * item still hidden, or an author who has since been marked a spammer, all mean the file is
 * removed rather than sent. A Mailable that does NOT implement this interface is assumed to
 * carry no member content and is always sent as normal; see
 * tests/Unit/Mail/DescribesMemberContentEnumerationTest.php, which enumerates every Mailable
 * under app/Mail and fails if one is not classified as an implementer, EXEMPT, or a documented
 * KNOWN GAP - so a new mail type quoting member content cannot silently go unfiltered.
 */
interface DescribesMemberContent
{
    /**
     * The ids of the member content this mail describes, and the users who authored it.
     * Every key is always present, each an array of ints (empty where this mail has none of
     * that kind). Deliberately only ids, never Eloquent models - filter-spool re-reads current
     * state from the ids rather than trusting anything captured when the mail was built.
     *
     * @return array{chatmessages: int[], messages: int[], newsfeed: int[], users: int[]}
     */
    public function about(): array;
}
