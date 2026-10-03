<?php

namespace App\Support;

/**
 * Frozen behaviour that used to be a per-community setting (settings JSON on the groups table).
 *
 * Per Edward's rule (2026-09-20, .claude-agent-status/briefs/frozen-settings.md): "we don't need
 * to make all global things configurable - if most groups do it one way, that becomes how the
 * system works." Each constant below is the value the majority of the 496 live Freegle
 * communities used on 2026-09-20 (see .claude-agent-status/data/majority.md for the full
 * distribution). These are NOT configurable: no env(), no per-community override. Only
 * REPLY_GATE_AFTER and the judge's model settings (config/freegle.php: judgement.*) are actually
 * configurable, because they are new behaviour, not a migrated setting.
 */
class Defaults
{
    // settings.reposts.offer / wanted (days before an unclaimed post is automatically reposted).
    public const REPOST_DAYS_OFFER = 3;
    public const REPOST_DAYS_WANTED = 7;

    // settings.reposts.max (maximum number of automatic reposts before a post stops being repeated).
    public const REPOST_MAX = 5;

    // settings.reposts.chaseups (how many chase-up messages a poster gets between reposts).
    public const REPOST_CHASEUPS = 2;

    // settings.maxagetoshow (0 = no age limit on which posts are shown).
    public const MAX_AGE_TO_SHOW_DAYS = 0;

    // settings.duplicates.check was 100% on; duplicate detection always runs now.
    public const DUPLICATE_CHECK_ENABLED = true;

    // settings.duplicates.offer / wanted / taken / received (days a duplicate post is suppressed for).
    public const DUPLICATE_WINDOW_OFFER_DAYS = 3;
    public const DUPLICATE_WINDOW_WANTED_DAYS = 7;
    public const DUPLICATE_WINDOW_TAKEN_DAYS = 7;
    public const DUPLICATE_WINDOW_RECEIVED_DAYS = 14;

    // settings.chaseups.interested.enabled / messages.enabled - both were on for the large majority.
    public const CHASEUP_INTERESTED_ENABLED = true;
    public const CHASEUP_MESSAGES_ENABLED = true;

    // settings.spammers.check / remove / chatreview / messagereview - all on; the "off" branches are gone.
    public const SPAMMER_CHECK_ENABLED = true;
    public const SPAMMER_REMOVE_ENABLED = true;
    public const SPAMMER_CHAT_REVIEW_ENABLED = true;
    public const SPAMMER_MESSAGE_REVIEW_ENABLED = true;

    // settings.widerchatreview - on.
    public const WIDER_CHAT_REVIEW_ENABLED = true;

    // settings.spammers.replydistance (miles) - used for the "many replies far away" spammer signal.
    public const SPAMMER_REPLY_DISTANCE_MILES = 50;

    // settings.includearea / includepc - subject lines always include the area name and postcode.
    public const SUBJECT_INCLUDE_AREA = true;
    public const SUBJECT_INCLUDE_POSTCODE = true;

    // settings.keywords.OFFER/WANTED/TAKEN/RECEIVED - English only now; the per-community keyword
    // table and its readers are deleted, not just defaulted.
    public const KEYWORD_OFFER = 'OFFER';
    public const KEYWORD_WANTED = 'WANTED';
    public const KEYWORD_TAKEN = 'TAKEN';
    public const KEYWORD_RECEIVED = 'RECEIVED';

    // settings.centralmailsdisabled - central mails (digests, notifications) are always sent now.
    public const CENTRAL_MAILS_ENABLED = true;

    // settings.allowedits.group / moderated - edits are always allowed.
    public const EDITS_ALLOWED = true;

    // settings.autoapprove.messages - the old per-community toggle is gone; AutoApproveService's
    // branch-wide delay is the only behaviour.
    public const AUTO_APPROVE_MESSAGES_LEGACY_TOGGLE = false;

    // Community feature toggles - all on, always, for every member (communityevents, volunteering,
    // newsfeed, newsletter, stories, relevant, engagement, showchat, businesscards, autoadmins,
    // social.repostcentral, chitchat, communitynews).
    public const FEATURE_EVENTS_ENABLED = true;
    public const FEATURE_VOLUNTEERING_ENABLED = true;
    public const FEATURE_NEWSFEED_ENABLED = true;
    public const FEATURE_NEWSLETTER_ENABLED = true;
    public const FEATURE_STORIES_ENABLED = true;
    public const FEATURE_RELEVANT_ENABLED = true;
    public const FEATURE_ENGAGEMENT_ENABLED = true;
    public const FEATURE_SHOWCHAT_ENABLED = true;
    public const FEATURE_BUSINESSCARDS_ENABLED = true;
    public const FEATURE_AUTOADMINS_ENABLED = true;
    public const FEATURE_REPOSTCENTRAL_ENABLED = true;
    public const FEATURE_CHITCHAT_ENABLED = true;
    public const FEATURE_COMMUNITYNEWS_ENABLED = true;

    // Micro-volunteering: on nationally; all task types on (the per-community toggles are gone).
    public const MICROVOLUNTEERING_ENABLED = true;
    public const MICROVOLUNTEERING_APPROVEDMESSAGES_ENABLED = true;
    public const MICROVOLUNTEERING_WORDMATCH_ENABLED = true;
    public const MICROVOLUNTEERING_PHOTOROTATE_ENABLED = true;

    // settings.onlovejunk - LoveJunk syndication is on for every post.
    public const LOVEJUNK_SYNDICATION_ENABLED = true;

    // rules.restrictpersonalinfo - the one rule toggle that survives as a deterministic (non-judge)
    // check: personal details are always kept out of posts.
    public const RESTRICT_PERSONAL_INFO = true;

    // rules.restrictdistance - no distance restriction on replies.
    public const RESTRICT_REPLY_DISTANCE = false;
}
