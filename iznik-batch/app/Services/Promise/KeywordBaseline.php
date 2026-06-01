<?php

namespace App\Services\Promise;

/**
 * Keyword-based baseline classifier for promise detection.
 *
 * Simple, interpretable, rule-based approach using promise cues:
 *   - Agreement phrases: "i'll take", "yes please", "you can have"
 *   - Ownership signals: "it's yours", "it's yours"
 *   - Meeting arrangement: "see you", day names, times
 *   - PII markers: <ADDRESS>, <POSTCODE>, <PHONE>
 */
class KeywordBaseline
{
    /**
     * Predict whether the span indicates a promise (1) or not (0).
     *
     * @param string $span
     * @return int (0 or 1)
     */
    public function predict(string $span): int
    {
        $text = mb_strtolower($span);

        // Strong positive signals (very high confidence)
        if ($this->hasAgreementPhrase($text)) {
            return 1;
        }

        // PII presence (address, postcode, phone) is a strong signal
        if ($this->hasPiiMarkers($text)) {
            return 1;
        }

        // Meeting arrangement (see you + day/time)
        if ($this->hasMeetingArrangement($text)) {
            return 1;
        }

        // Default: no promise detected
        return 0;
    }

    /**
     * Check for explicit agreement/acceptance phrases.
     */
    private function hasAgreementPhrase(string $text): bool
    {
        $patterns = [
            // Agreement
            "/\bi'?ll\s+take\s+(it|them)/",
            "/\byes\s+please\b/",
            "/\byou\s+can\s+have\s+(it|them)/",
            "/\bcan\s+have\s+(it|them)\b/",
            "/\bit'?s\s+yours\b/",
            "/\bthat'?s\s+yours\b/",

            // Gratitude with explicit taking
            "/\bthank\s+you.{0,20}(i'?ll|will|can|going to)\s+(take|collect|come)/",
            "/\bthank\s+you.{0,10}(it'?s|it is)\s+yours/",

            // Collection/pickup commitment
            "/\bi\s+(can|will|going to|'ll)\s+collect\b/",
            "/\b(will|can|'ll)\s+(collect|pick)\s+(it|them|up)/",
            "/\bcollecting\s+\w+/", // "collecting thursday"
            "/\bi\s+can\s+come/",
            "/\bi\s+can\s+pop\s+(round|over)/",
            "/\bwill\s+bring\s+it/",
            "/\bwill\s+drop\s+it/",
            "/\bi\s+can\s+drop\s+it/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for PII markers that indicate progression to logistics.
     */
    private function hasPiiMarkers(string $text): bool
    {
        return (
            strpos($text, '<address>') !== false ||
            strpos($text, '<postcode>') !== false ||
            strpos($text, '<phone>') !== false ||
            strpos($text, '<email>') !== false
        );
    }

    /**
     * Check for meeting arrangement (day/time + "see you" / collection intent).
     */
    private function hasMeetingArrangement(string $text): bool
    {
        // Days of the week
        $dayPattern = '/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|wed|thu|fri|sat|sun)\b/';

        // Times/occasions
        $timePattern = '/\b(morning|afternoon|evening|night|pm|am|o\'?clock|at\s+\d+)/';

        // "see you" + day/time
        if (preg_match('/\bsee\s+you\b/', $text)) {
            if (preg_match($dayPattern, $text) || preg_match($timePattern, $text)) {
                return true;
            }
        }

        // Explicit collection arrangement with day/time
        if (preg_match('/\b(collect|come|pick)\b/', $text)) {
            if (preg_match($dayPattern, $text) || preg_match($timePattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
