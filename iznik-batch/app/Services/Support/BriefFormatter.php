<?php

namespace App\Services\Support;

/**
 * Turns a first pass brief (the fixed JSON shape, version 2) into plain text and markdown.
 *
 * The brief came from a model, so every value is read defensively: a missing or oddly typed key
 * leaves its line out and never throws.
 */
class BriefFormatter
{
    /** @return array<int, string> */
    private static function strings(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $out[] = trim((string) $v);
            }
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** "5-hour pool 40% used, 7-day pool 20% used" or a plain "not known". */
    public static function poolLine(mixed $pool): string
    {
        if (! is_array($pool)) {
            return 'AI credit pool: not known';
        }
        $part = fn ($v, $label) => is_numeric($v) ? "{$label} ".round((float) $v).'% used' : "{$label} not known";

        return 'AI credit pool at run time: '.$part($pool['fiveHour'] ?? null, '5-hour').', '.$part($pool['sevenDay'] ?? null, '7-day');
    }

    /** Case ids from the brief's related_cases, as positive integers only. @return array<int, int> */
    private static function caseIds(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $out[] = (int) $v;
            }
        }

        return array_values(array_unique($out));
    }

    private static function caseLink(int $id): string
    {
        return rtrim((string) config('freegle.sites.mod'), '/')."/support/cases/{$id}";
    }

    /**
     * The first pass as plain text, in the order a reader needs it: the question, the answer, the
     * classification and confidence, open questions, related cases, the draft and its link
     * warnings, what was checked, then the pool line.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function text(array $brief): string
    {
        $out = [];
        $question = self::str($brief['question'] ?? '');
        if ($question !== '') {
            $out[] = "The question: {$question}";
        }
        $answer = self::str($brief['answer'] ?? '');
        if ($answer !== '') {
            $out[] = '';
            $out[] = $answer;
        }
        $class = self::str($brief['classification'] ?? '');
        $conf = is_array($brief['confidence'] ?? null) ? $brief['confidence'] : [];
        $level = self::str($conf['level'] ?? '');
        // The reason is a sentence the model wrote; its own full stop would double up in brackets.
        $reason = rtrim(self::str($conf['reason'] ?? ''), '. ');
        $line = [];
        if ($class !== '') {
            $line[] = "Classification: {$class}";
        }
        if ($level !== '') {
            $line[] = "Confidence: {$level}".($reason !== '' ? " ({$reason})" : '');
        }
        if ($line) {
            $out[] = '';
            $out[] = implode('. ', $line);
        }

        $open = self::strings($brief['open_questions'] ?? []);
        if ($open) {
            $out[] = '';
            $out[] = 'Open questions';
            foreach ($open as $q) {
                $out[] = "  - {$q}";
            }
        }

        $related = self::caseIds($brief['related_cases'] ?? []);
        if ($related) {
            $out[] = '';
            $out[] = 'Related cases';
            foreach ($related as $id) {
                $out[] = '  - '.self::caseLink($id);
            }
        }

        $draft = is_array($brief['draft'] ?? null) ? $brief['draft'] : [];
        $draftText = self::str($draft['text'] ?? '');
        if ($draftText !== '') {
            $out[] = '';
            $out[] = 'Draft to the referring volunteer, not sent';
            $out[] = $draftText;
            $warnings = self::strings($draft['link_warnings'] ?? []);
            if ($warnings) {
                $out[] = 'Check before sending:';
                foreach ($warnings as $w) {
                    $out[] = "  - {$w}";
                }
            }
        }

        $checks = self::strings($brief['checks'] ?? []);
        if ($checks) {
            $out[] = '';
            $out[] = 'What was checked';
            foreach ($checks as $c) {
                $out[] = "  - {$c}";
            }
        }

        $out[] = '';
        $out[] = self::poolLine($brief['pool'] ?? null);

        return ltrim(implode("\n", $out), "\n");
    }

    /**
     * The brief as markdown, stored as the run's analysis.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function markdown(array $brief): string
    {
        $out = [];
        $question = self::str($brief['question'] ?? '');
        if ($question !== '') {
            $out[] = "**The question:** {$question}";
        }
        $answer = self::str($brief['answer'] ?? '');
        if ($answer !== '') {
            $out[] = $answer;
        }
        $class = self::str($brief['classification'] ?? '');
        $conf = is_array($brief['confidence'] ?? null) ? $brief['confidence'] : [];
        $level = self::str($conf['level'] ?? '');
        // The reason is a sentence the model wrote; its own full stop would double up in brackets.
        $reason = rtrim(self::str($conf['reason'] ?? ''), '. ');
        if ($class !== '' || $level !== '') {
            $out[] = '**Classification:** '.($class !== '' ? $class : 'not given').'. **Confidence:** '.($level !== '' ? $level : 'not given').($reason !== '' ? " ({$reason})" : '').'.';
        }
        $open = self::strings($brief['open_questions'] ?? []);
        if ($open) {
            $out[] = "## Open questions\n\n".implode("\n", array_map(fn ($q) => "- {$q}", $open));
        }
        $related = self::caseIds($brief['related_cases'] ?? []);
        if ($related) {
            $out[] = "## Related cases\n\n".implode("\n", array_map(fn ($id) => '- ['."#{$id}".'](' . self::caseLink($id) . ')', $related));
        }
        $draft = is_array($brief['draft'] ?? null) ? $brief['draft'] : [];
        $draftText = self::str($draft['text'] ?? '');
        if ($draftText !== '') {
            $out[] = '## Draft to the referring volunteer, not sent';
            $out[] = $draftText;
            $warnings = self::strings($draft['link_warnings'] ?? []);
            if ($warnings) {
                $out[] = "Check before sending:\n\n".implode("\n", array_map(fn ($w) => "- {$w}", $warnings));
            }
        }
        $checks = self::strings($brief['checks'] ?? []);
        if ($checks) {
            $out[] = "## What was checked\n\n".implode("\n", array_map(fn ($c) => "- {$c}", $checks));
        }

        return implode("\n\n", $out);
    }
}
