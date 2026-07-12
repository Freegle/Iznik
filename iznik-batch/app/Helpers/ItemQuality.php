<?php

namespace App\Helpers;

/**
 * Quality checks on a post's item — the subject text between "OFFER:"/"WANTED:" and the
 * trailing "(location)". Used to route low-quality/vague posts to Pending for a moderator to
 * review rather than auto-approving them.
 *
 * Intentionally MORE aggressive than the client-side compose gate
 * (iznik-nuxt3/composables/useItemValidation.js): routing to Pending is reversible — a mod
 * approves the genuine ones — so it can afford a wider net (filler variants like "anything
 * nice", and "<vague> for/to X" like "things for the garden"). Sized against 30 days of
 * production posts at ~3/day across Freegle, with the sampled matches all genuinely vague.
 */
class ItemQuality
{
    // Content-free / vague item patterns. The item is lower-cased, trimmed and location-stripped
    // before matching. The "<vague> for/to X" rule only fires when the LEADING word is itself
    // content-free, so a specific request ("bike for a toddler") is never matched.
    private const VAGUE_PATTERNS = [
        '/^(any|anything|everything|every ?thing|all|stuff|things|items|goods|various|various items|various stuff|free stuff|free items|free things|freebies|something|owt|whatever|unwanted|unwanted items|unwanted stuff|misc|miscellaneous|sundries|no idea|not sure|nothing specific)$/',
        '/^(anything|everything|something) (else|nice|really|useful|good|free|at all)$/',
        '/^(anything|something|stuff|things|items|bits) (for|to) /',
        // Content-free responses/fillers left in the item box ("Yes", "No thanks",
        // "Please") — a whole-item match only, so real names are untouched.
        '/^(yes|yeah|yep|yup|no|nope|nah|ok|okay|okey|yes please|no thanks|please|pls|ta|thanks|thank you|thankyou|cheers)$/',
    ];

    /**
     * Extract the item from a "TYPE: item (area)" subject — lower-cased and trimmed. Returns ''
     * when there's no usable item.
     */
    public static function itemFromSubject(?string $subject): string
    {
        if ($subject === null || $subject === '') {
            return '';
        }
        // Strip a leading "OFFER:"/"WANTED:" (any case) and a trailing "(area)".
        $s = preg_replace('/^\s*(OFFER|WANTED)\s*:\s*/i', '', $subject);
        $s = preg_replace('/\s*\([^)]*\)\s*$/', '', $s);

        return strtolower(trim($s));
    }

    /**
     * True when the post's item carries no real information — a content-free catch-all
     * ("anything", "free stuff", "various items", "things for the garden"). Such posts are
     * routed to Pending rather than auto-approved.
     */
    public static function subjectItemIsVague(?string $subject): bool
    {
        $item = self::itemFromSubject($subject);
        if ($item === '') {
            return false;
        }
        foreach (self::VAGUE_PATTERNS as $re) {
            if (preg_match($re, $item)) {
                return true;
            }
        }

        return false;
    }
}
