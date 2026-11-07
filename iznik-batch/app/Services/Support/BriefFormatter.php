<?php

namespace App\Services\Support;

/**
 * Turns a first pass brief (the fixed JSON shape, version 1) into plain text and markdown.
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

    /**
     * The whole first pass as plain text: summary and judgement first, then the six fact sections,
     * then what is unusual, what could not be found, the draft and the pool.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function text(array $brief): string
    {
        $out = [];
        $summary = self::str($brief['summary'] ?? '');
        if ($summary !== '') {
            $out[] = "Summary: {$summary}";
        }
        $class = self::str($brief['classification'] ?? '');
        $conf = is_array($brief['confidence'] ?? null) ? $brief['confidence'] : [];
        $level = self::str($conf['level'] ?? '');
        $reason = self::str($conf['reason'] ?? '');
        if ($class !== '') {
            $out[] = "Classification: {$class}";
        }
        if ($level !== '') {
            $out[] = "Confidence: {$level}".($reason !== '' ? " ({$reason})" : '');
        }

        foreach (is_array($brief['facts'] ?? null) ? $brief['facts'] : [] as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $title = self::str($fact['title'] ?? ($fact['key'] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = '';
            $out[] = $title;
            $lines = self::strings($fact['lines'] ?? []);
            foreach ($lines as $l) {
                $out[] = "  - {$l}";
            }
            $note = self::str($fact['note'] ?? '');
            if ($note !== '') {
                $out[] = $lines ? "  ({$note})" : "  {$note}";
            }
        }

        foreach (['unusual' => 'Anything unusual', 'could_not_find' => 'Could not find'] as $key => $title) {
            $items = self::strings($brief[$key] ?? []);
            if ($items) {
                $out[] = '';
                $out[] = $title;
                foreach ($items as $i) {
                    $out[] = "  - {$i}";
                }
            }
        }

        $draft = is_array($brief['draft'] ?? null) ? $brief['draft'] : [];
        $draftText = self::str($draft['text'] ?? '');
        if ($draftText !== '') {
            $out[] = '';
            $out[] = 'Draft to the referring volunteer (not sent)';
            $out[] = $draftText;
            $warnings = self::strings($draft['link_warnings'] ?? []);
            if ($warnings) {
                $out[] = 'Check before sending:';
                foreach ($warnings as $w) {
                    $out[] = "  - {$w}";
                }
            }
        }

        $out[] = '';
        $out[] = self::poolLine($brief['pool'] ?? null);

        return implode("\n", $out);
    }

    /**
     * The brief as markdown, stored as the run's analysis.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function markdown(array $brief): string
    {
        $out = [];
        $summary = self::str($brief['summary'] ?? '');
        if ($summary !== '') {
            $out[] = $summary;
        }
        $class = self::str($brief['classification'] ?? '');
        $conf = is_array($brief['confidence'] ?? null) ? $brief['confidence'] : [];
        $level = self::str($conf['level'] ?? '');
        if ($class !== '' || $level !== '') {
            $out[] = '**Classification:** '.($class !== '' ? $class : 'not given').'. **Confidence:** '.($level !== '' ? $level : 'not given').'.';
        }
        foreach (is_array($brief['facts'] ?? null) ? $brief['facts'] : [] as $fact) {
            if (! is_array($fact) || self::str($fact['title'] ?? '') === '') {
                continue;
            }
            $out[] = '## '.self::str($fact['title']);
            $lines = self::strings($fact['lines'] ?? []);
            foreach ($lines as $l) {
                $out[] = "- {$l}";
            }
            if (self::str($fact['note'] ?? '') !== '') {
                $out[] = '_'.self::str($fact['note']).'_';
            }
        }
        foreach (['unusual' => 'Anything unusual', 'could_not_find' => 'Could not find'] as $key => $title) {
            $items = self::strings($brief[$key] ?? []);
            if ($items) {
                $out[] = "## {$title}";
                foreach ($items as $i) {
                    $out[] = "- {$i}";
                }
            }
        }
        $draftText = self::str((is_array($brief['draft'] ?? null) ? $brief['draft'] : [])['text'] ?? '');
        if ($draftText !== '') {
            $out[] = '## Draft to the referring volunteer (not sent)';
            $out[] = $draftText;
        }

        return implode("\n\n", $out);
    }
}
