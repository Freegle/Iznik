<?php

namespace Tests\Unit\Support;

use App\Support\NameSanitiser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers display-name rewrite rules for Discourse thread #9587.
 * Stored name is untouched; non-mods with suspicious names get rewritten
 * on display only.
 */
class NameSanitiserTest extends TestCase
{
    public static function suspiciousNames(): array
    {
        return [
            'freegle support (exact phishing)' => ['iLovefreegle Support'],
            'freegle support team' => ['Freegle Support Team'],
            'lowercase variant' => ['ilovefreegle support team'],
            'freegle admin' => ['Freegle Admin'],
            'trashnothing help' => ['TrashNothing Help'],
            'leet digits' => ['Fr33gle Supp0rt'],
            'spacing obfuscation' => ['i Love freegle Team'],
            'dotted freecycle' => ['free.cycle'],
            'one edit ilovefreegle' => ['Ilovefreegl Support'],
            'bare freegle' => ['Freegle'],
            'freegle postcode' => ['Freegle SK9'],
            'bare admin' => ['Admin'],
            'support team solo' => ['Support Team'],
            'prize winner' => ['Prize Winner'],
            'surname freegle' => ['Susan Freegle'],
            'freegler support (weak brand + authority)' => ['Freegler Support'],
        ];
    }

    #[DataProvider('suspiciousNames')]
    public function test_suspicious_names_are_rewritten_for_non_mods(string $input): void
    {
        $out = NameSanitiser::sanitize($input, isExempt: false);
        $this->assertNotSame($input, $out, "expected '$input' to be rewritten");
        $this->assertNotEmpty($out);
    }

    public static function cleanNames(): array
    {
        return [
            'plain name' => ['Emma Brown'],
            'authority-sounding surname' => ['Emma Support'],
            'freegler alone' => ['Adam Freegler'],
            'eagle (near-miss)' => ['Eagle'],
            'greg (near-miss)' => ['Greg'],
            'empty' => [''],
            'single letter' => ['J'],
            'tn email leak' => ['alice-g3486@user.trashnothing.com'],
        ];
    }

    #[DataProvider('cleanNames')]
    public function test_clean_names_pass_through_unchanged(string $input): void
    {
        $this->assertSame($input, NameSanitiser::sanitize($input, isExempt: false));
    }

    public function test_exempt_users_keep_any_name(): void
    {
        $suspicious = ['iLovefreegle Support', 'Freegle Admin', 'Admin'];
        foreach ($suspicious as $name) {
            $this->assertSame(
                $name,
                NameSanitiser::sanitize($name, isExempt: true),
                "exempt user's '$name' must pass through"
            );
        }
    }

    public function test_damerau_levenshtein_covers_transposition(): void
    {
        $this->assertSame(1, NameSanitiser::damerauLevenshtein('freegle', 'freegel', 2));
        $this->assertSame(1, NameSanitiser::damerauLevenshtein('freegle', 'freeggle', 2));
        $this->assertSame(0, NameSanitiser::damerauLevenshtein('freegle', 'freegle', 2));
    }
}
