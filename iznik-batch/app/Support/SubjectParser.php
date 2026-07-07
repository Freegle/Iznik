<?php

namespace App\Support;

/**
 * Parse Freegle-format message subjects into their three structured parts.
 *
 * Freegle subjects are canonically "TYPE: item (location)" — e.g.
 * "OFFER: Coffee table (SE10 1BH)". The location may itself contain
 * parentheses ("Ladywell (between Lewisham and Catford)") so we walk
 * backwards counting parens rather than using a naive regex.
 *
 * Extracted from IncomingMailService::parseSubject so that callers outside
 * the mail pipeline (e.g. embedding pre-processing) can reuse the exact
 * same parse without duplicating the algorithm.
 */
final class SubjectParser
{
    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     *               [type, item, location]; any may be null when the
     *               subject does not match the expected shape.
     */
    public static function parse(string $subj): array
    {
        $type = null;
        $item = null;
        $location = null;

        $p = strpos($subj, ':');

        if ($p !== false) {
            $startp = $p;
            $rest = trim(substr($subj, $p + 1));
            $p = strlen($rest) - 1;

            if (substr($rest, -1) == ')') {
                $count = 0;

                do {
                    $curr = substr($rest, $p, 1);

                    if ($curr == '(') {
                        $count--;
                    } elseif ($curr == ')') {
                        $count++;
                    }

                    $p--;
                } while ($count > 0 && $p > 0);

                if ($count == 0) {
                    $type = trim(substr($subj, 0, $startp));
                    $location = trim(substr($rest, $p + 2, strlen($rest) - $p - 3));
                    $item = trim(substr($rest, 0, $p));
                }
            }
        }

        return [$type, $item, $location];
    }
}
