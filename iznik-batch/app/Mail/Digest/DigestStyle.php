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
}
