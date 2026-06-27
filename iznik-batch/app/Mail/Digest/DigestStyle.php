<?php

namespace App\Mail\Digest;

/**
 * Shared design tokens for the unified digest's two hand-maintained
 * renderings (resources/views/emails/mjml/digest/unified.blade.php and
 * resources/views/emails/amp/digest/unified.blade.php).
 *
 * The two templates are written in different languages (MJML vs AMP4Email)
 * so their *structure* can't be shared — but every visual VALUE that should
 * match across them must come from here, not be repeated as a literal in
 * each file. The 2026-06-11 review found the OFFER green and WANTED blue
 * had silently drifted between the variants precisely because they were
 * duplicated literals; this class is the fix for that failure mode.
 *
 * When adding a new shared value: add the constant here, reference it from
 * BOTH templates, and never inline the raw value in either.
 */
class DigestStyle
{
    /** Accent for OFFER pills / Reply buttons — Freegle brand green. */
    public const OFFER_GREEN = '#338808';

    /** Accent for WANTED pills / Reply buttons. */
    public const WANTED_BLUE = '#00A1CB';

    /**
     * Number of summary ("In this digest") lines shown before the rest are
     * tucked behind the collapsible "Show N more" control. Three matches the
     * V1 "What's New" index density and keeps the email short for clients
     * that can't collapse (the overflow degrades to one line per post).
     *
     * Referenced by BOTH the MJML (<details>) and AMP4Email (<amp-accordion>)
     * renderings so the cut-off can never drift between the two variants.
     */
    public const SUMMARY_VISIBLE_LINES = 3;

    /**
     * Maximum number of live posts carried in the body of a daily digest, across
     * ALL MIME parts (HTML, plain text, AMP). A dense-area daily digest can run to
     * hundreds of posts; without a cap the HTML balloons past Gmail's ~102KB clip
     * (silently truncating mid-post) and the AMP part exceeds AMP4Email's 200KB
     * hard limit. When the cap bites, each part shows an "in this digest" intro
     * pointing the reader to the website for the remaining posts. Originally an
     * AMP-only guard; now shared so all three parts cut off at the same place.
     */
    public const DIGEST_POST_CAP = 65;
}
