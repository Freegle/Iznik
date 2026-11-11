<?php

namespace Tests\Unit\Ux;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Corpse control for UX experiments decided by batch code (docs/developers/reference/ux-experiments.md).
 *
 * Every `UxArm::for($id, '<name>')` in app/ must be listed in config/ux_switches.php with the day it
 * was opened, and every listed experiment must still have a call. The name must be a string literal,
 * so that this check can see it. It is the same rule as the browser's
 * (iznik-nuxt3/tests/unit/uxExperimentsManifest.spec.js) and the Go API's (TestServerSwitches in
 * iznik-server-go/test/ux_serverarm_test.go).
 *
 * Age has two limits. An entry older than WARN_AFTER_DAYS (14) is only warned about: a missed cleanup
 * must be loud, not a reason to turn every other change red. One older than HARD_FAIL_DAYS (30), unless
 * its keep_until is still ahead, fails the build, so it cannot be forgotten for ever.
 *
 * The scan is unit tested on sample source as well as run over the tree, so a tree that is found
 * empty cannot mean it checks nothing.
 */
class UxSwitchManifestTest extends TestCase
{
    public const WARN_AFTER_DAYS = 14;

    public const HARD_FAIL_DAYS = 30;

    private const NAME = '/^[A-Za-z0-9_-]{1,64}\z/';

    /** The file that defines the call; its examples and its own name are not switches. */
    private const OWNER = 'Services/Ux/UxArm.php';

    // ---------------------------------------------------------------- the scanner

    /**
     * The calls to UxArm::for in some PHP source, comments left out: each is the name when the second
     * argument is a plain string literal, or null when it is anything else (a variable, a constant, a
     * join of strings), which the scan cannot follow.
     *
     * @return array<int, ?string>
     */
    public static function callsIn(string $source): array
    {
        $code = trim($source) !== '' && ! str_contains($source, '<?php') ? "<?php\n".$source : $source;
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_INLINE_HTML], true)) {
                continue;
            }
            $tokens[] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
        }

        $calls = [];
        $n = count($tokens);
        for ($i = 0; $i + 3 < $n; $i++) {
            [$type, $text] = $tokens[$i];
            $isArm = in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(^|\\\\)UxArm\z/', $text) === 1;
            $isFor = in_array($tokens[$i + 2][0], [T_STRING, T_FOR], true) && strtolower($tokens[$i + 2][1]) === 'for';
            if (! $isArm || $tokens[$i + 1][0] !== T_DOUBLE_COLON || ! $isFor || $tokens[$i + 3][1] !== '(') {
                continue;
            }

            // The arguments, split at the commas that are not inside brackets.
            $args = [[]];
            $depth = 0;
            for ($j = $i + 4; $j < $n; $j++) {
                $t = $tokens[$j][1];
                if (in_array($t, ['(', '[', '{'], true) || $tokens[$j][0] === T_CURLY_OPEN) {
                    $depth++;
                } elseif (in_array($t, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($t === ',' && $depth === 0) {
                    $args[] = [];

                    continue;
                }
                $args[count($args) - 1][] = $tokens[$j];
            }

            $second = $args[1] ?? [];
            $name = null;
            if (count($second) === 1 && $second[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = substr($second[0][1], 1, -1);
                $name = preg_match(self::NAME, $literal) === 1 ? $literal : null;
            }
            $calls[] = $name;
        }

        return $calls;
    }

    /**
     * Entries past the soft limit, which only warn: opened more than 14 days ago with no keep_until
     * still ahead.
     *
     * @param  array<int, mixed>  $entries
     * @return string[]
     */
    public static function warnings(array $entries, Carbon $today): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (! is_array($e) || ! is_string($e['name'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['opened'] ?? ''))) {
                continue;
            }
            $age = self::age($e, $today);
            if ($age > self::WARN_AFTER_DAYS && ! self::kept($e, $today)) {
                $out[] = "{$e['name']}: opened {$e['opened']}, $age days ago. Clean it up (it fails the build at ".self::HARD_FAIL_DAYS.' days) or set keep_until with a reason.';
            }
        }

        return $out;
    }

    /**
     * What is wrong with the list and the calls.
     *
     * @param  array<int, mixed>  $entries
     * @param  array<int, array{name: string, file: string}>  $switches  every literal call in the code
     * @return string[]
     */
    public static function problems(array $entries, array $switches, Carbon $today): array
    {
        $problems = [];
        $listed = [];
        foreach ($entries as $e) {
            if (! is_array($e) || ! is_string($e['name'] ?? null) || preg_match(self::NAME, $e['name']) !== 1) {
                $problems[] = 'a switch list entry has no usable name';

                continue;
            }
            if (isset($listed[$e['name']])) {
                $problems[] = "{$e['name']}: listed twice";
            }
            $listed[$e['name']] = $e;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['opened'] ?? ''))) {
                $problems[] = "{$e['name']}: opened must be YYYY-MM-DD";

                continue;
            }
            $age = self::age($e, $today);
            if ($age > self::HARD_FAIL_DAYS && ! self::kept($e, $today)) {
                $problems[] = "{$e['name']}: opened {$e['opened']}, $age days ago, past ".self::HARD_FAIL_DAYS.'; clean it up or set keep_until with a reason';
            }
        }

        $inCode = [];
        foreach ($switches as $s) {
            $inCode[$s['name']] = true;
            if (! isset($listed[$s['name']])) {
                $problems[] = "{$s['name']}: used in {$s['file']} but not in config/ux_switches.php";
            }
        }
        foreach (array_keys($listed) as $name) {
            if (! isset($inCode[$name])) {
                $problems[] = "$name: in config/ux_switches.php but no UxArm::for(\$id, '$name') left in the code; remove the entry";
            }
        }

        return $problems;
    }

    private static function age(array $entry, Carbon $today): int
    {
        return (int) floor(($today->getTimestamp() - Carbon::parse($entry['opened'].' 00:00:00', 'UTC')->getTimestamp()) / 86400);
    }

    private static function kept(array $entry, Carbon $today): bool
    {
        return is_string($entry['keep_until'] ?? null)
            && Carbon::parse($entry['keep_until'].' 00:00:00', 'UTC')->getTimestamp() >= $today->getTimestamp();
    }

    /**
     * Every UxArm::for in the PHP files under a directory (the batch's app/ in the real check).
     *
     * @return array{0: array<int, array{name: string, file: string}>, 1: string[], 2: int} switches, calls that are not literals, files read
     */
    private function scanTree(string $root): array
    {
        $switches = [];
        $nonLiteral = [];
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative === self::OWNER) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'UxArm')) {
                continue;
            }
            foreach (self::callsIn($source) as $name) {
                if ($name === null) {
                    $nonLiteral[] = "app/$relative: a UxArm::for call whose name is not a string literal, so config/ux_switches.php cannot track it";
                } else {
                    $switches[] = ['name' => $name, 'file' => "app/$relative"];
                }
            }
        }

        return [$switches, $nonLiteral, $files];
    }

    // ---------------------------------------------------------------- the checker, on sample source

    public function test_it_finds_a_call_in_each_of_its_spellings(): void
    {
        $this->assertSame(['give-hint'], self::callsIn("\$arm = UxArm::for(\$user->id, 'give-hint');"));
        $this->assertSame(['a_b'], self::callsIn('$arm = UxArm::for($id, "a_b");'));
        $this->assertSame(['x'], self::callsIn("\$a = \\App\\Services\\Ux\\UxArm::for(\$id, 'x');"));
        $this->assertSame(['x'], self::callsIn("\$a = Ux\\UxArm::for(\$id, 'x');"));
        $this->assertSame(['x'], self::callsIn("\$a = UxArm::for(\n    \$id,\n    'x'\n);"));
        $this->assertSame(['x'], self::callsIn("<?php\nclass A { function f(\$id) { return UxArm::for(\$id, 'x'); } }"));
    }

    public function test_it_finds_a_call_whose_first_argument_has_brackets_and_commas_in_it(): void
    {
        $this->assertSame(['x'], self::callsIn("UxArm::for((int) \$row->userid, 'x')"));
        $this->assertSame(['x'], self::callsIn("UxArm::for(max(1, \$ids[0]), 'x')"));
        $this->assertSame(['x'], self::callsIn("UxArm::for(\$this->ids(['a', 'b'])[0], 'x')"));
    }

    public function test_it_finds_every_call_in_a_file(): void
    {
        $this->assertSame(['a', 'b', 'a'], self::callsIn("UxArm::for(\$i, 'a'); if (UxArm::for(\$i, 'b') === 'items') { UxArm::for(\$j, 'a'); }"));
    }

    public function test_it_ignores_a_switch_that_only_appears_in_a_comment(): void
    {
        $this->assertSame([], self::callsIn("// UxArm::for(\$id, 'doc-example')\n# UxArm::for(\$id, 'hash')\n/* UxArm::for(\$id, 'block') */\n/** @example UxArm::for(\$id, 'doc') */"));
    }

    public function test_it_ignores_other_methods_and_other_classes(): void
    {
        $this->assertSame([], self::callsIn("UxArm::pick(\$arm, ['control' => 1]); UxArm::expose(\$id, 'x', 'items', 'email', 1); Other::for(\$id, 'x'); \$x->for(\$id, 'x');"));
    }

    public function test_it_flags_a_call_whose_name_is_not_a_literal(): void
    {
        $this->assertSame([null], self::callsIn('UxArm::for($id, $name)'));
        $this->assertSame([null], self::callsIn('UxArm::for($id, self::NAME)'));
        $this->assertSame([null], self::callsIn("UxArm::for(\$id, 'a'.\$b)"));
        $this->assertSame([null], self::callsIn('UxArm::for($id, "a{$b}")'), 'an interpolated string is not a literal');
        $this->assertSame([null], self::callsIn("UxArm::for(\$id, 'bad name')"), 'nor is a name the engine would not accept');
        $this->assertSame([null], self::callsIn('UxArm::for($id)'));
    }

    // ---------------------------------------------------------------- the list, on sample entries

    private function today(): Carbon
    {
        return Carbon::parse('2026-10-14 12:00:00', 'UTC');
    }

    public function test_a_fresh_listed_switch_passes(): void
    {
        $entries = [['name' => 'x', 'opened' => '2026-10-10']];

        $this->assertSame([], self::problems($entries, [['name' => 'x', 'file' => 'a.php']], $this->today()));
    }

    public function test_a_call_with_no_entry_fails(): void
    {
        $this->assertSame(
            ['x: used in app/a.php but not in config/ux_switches.php'],
            self::problems([], [['name' => 'x', 'file' => 'app/a.php']], $this->today())
        );
    }

    public function test_an_entry_with_no_call_left_fails(): void
    {
        $p = self::problems([['name' => 'gone', 'opened' => '2026-10-10']], [], $this->today());

        $this->assertCount(1, $p);
        $this->assertStringContainsString("no UxArm::for(\$id, 'gone') left in the code", $p[0]);
    }

    public function test_an_entry_older_than_the_hard_limit_fails(): void
    {
        $p = self::problems([['name' => 'old', 'opened' => '2026-09-01']], [['name' => 'old', 'file' => 'a.php']], $this->today());

        $this->assertCount(1, $p);
        $this->assertStringContainsString('past 30', $p[0]);
    }

    public function test_an_entry_between_14_and_30_days_old_warns_but_does_not_fail(): void
    {
        $entries = [['name' => 'late', 'opened' => '2026-09-25']];

        $this->assertSame([], self::problems($entries, [['name' => 'late', 'file' => 'a.php']], $this->today()));
        $w = self::warnings($entries, $this->today());
        $this->assertCount(1, $w);
        $this->assertStringContainsString('late', $w[0]);
        $this->assertStringContainsString('19 days', $w[0]);
    }

    public function test_the_edges_of_the_limits(): void
    {
        $today = $this->today();   // 14 October
        $at = fn (string $opened) => [['name' => 'x', 'opened' => $opened]];

        $this->assertSame([], self::warnings($at('2026-09-30'), $today), '14 days is not yet past the soft limit');
        $this->assertCount(1, self::warnings($at('2026-09-29'), $today), '15 days is');
        $this->assertSame([], self::problems($at('2026-09-14'), [['name' => 'x', 'file' => 'a']], $today), '30 days is not yet past the hard limit');
        $this->assertCount(1, self::problems($at('2026-09-13'), [['name' => 'x', 'file' => 'a']], $today), '31 days is');
    }

    public function test_a_fresh_or_deliberately_kept_entry_does_not_warn(): void
    {
        $entries = [['name' => 'new', 'opened' => '2026-10-10'], ['name' => 'kept', 'opened' => '2026-09-01', 'keep_until' => '2026-11-01']];

        $this->assertSame([], self::warnings($entries, $this->today()));
    }

    public function test_an_old_entry_kept_until_a_future_date_passes_and_one_kept_until_the_past_does_not(): void
    {
        $kept = [['name' => 'old', 'opened' => '2026-09-01', 'keep_until' => '2026-11-01']];
        $lapsed = [['name' => 'old', 'opened' => '2026-09-01', 'keep_until' => '2026-10-01']];
        $call = [['name' => 'old', 'file' => 'a.php']];

        $this->assertSame([], self::problems($kept, $call, $this->today()));
        $this->assertCount(1, self::problems($lapsed, $call, $this->today()));
    }

    public function test_a_bad_opened_date_a_missing_name_and_a_name_listed_twice_all_fail(): void
    {
        $call = [['name' => 'x', 'file' => 'a.php']];

        $this->assertSame(['x: opened must be YYYY-MM-DD'], self::problems([['name' => 'x', 'opened' => 'last week']], $call, $this->today()));
        $this->assertSame(['a switch list entry has no usable name'], self::problems([['opened' => '2026-10-10']], [], $this->today()));
        $this->assertSame(['x: listed twice'], self::problems([['name' => 'x', 'opened' => '2026-10-10'], ['name' => 'x', 'opened' => '2026-10-10']], $call, $this->today()));
    }

    // ---------------------------------------------------------------- the tree

    public function test_every_call_in_the_code_is_listed_none_is_dead_none_is_too_old_and_every_name_is_a_literal(): void
    {
        [$switches, $nonLiteral, $files] = $this->scanTree(base_path('app'));

        $this->assertGreaterThan(100, $files, 'the scan found the tree: an empty one would check nothing');
        $this->assertSame([], $nonLiteral);
        $this->assertSame([], self::problems((array) config('ux_switches'), $switches, Carbon::now('UTC')));
    }

    public function test_the_tree_scan_finds_a_call_that_is_there_and_skips_the_file_that_defines_it(): void
    {
        $dir = sys_get_temp_dir().'/uxswitch-'.bin2hex(random_bytes(4));
        mkdir($dir.'/Services/Ux', 0777, true);
        file_put_contents($dir.'/Mail.php', "<?php\nclass Mail { function f(\$id) { return \\App\\Services\\Ux\\UxArm::for(\$id, 'probe-switch'); } }\n");
        file_put_contents($dir.'/Careless.php', "<?php\nclass Careless { function f(\$id, \$name) { return UxArm::for(\$id, \$name); } }\n");
        file_put_contents($dir.'/Services/Ux/UxArm.php', "<?php\nclass UxArm { function f(\$id) { return UxArm::for(\$id, 'the-owners-own-example'); } }\n");
        file_put_contents($dir.'/Unrelated.php', "<?php\nclass Unrelated {}\n");
        file_put_contents($dir.'/notes.txt', "UxArm::for(\$id, 'not-php')");
        try {
            [$switches, $nonLiteral, $files] = $this->scanTree($dir);
        } finally {
            foreach (['/Mail.php', '/Careless.php', '/Services/Ux/UxArm.php', '/Unrelated.php', '/notes.txt'] as $f) {
                unlink($dir.$f);
            }
            rmdir($dir.'/Services/Ux');
            rmdir($dir.'/Services');
            rmdir($dir);
        }

        $this->assertSame([['name' => 'probe-switch', 'file' => 'app/Mail.php']], $switches);
        $this->assertCount(1, $nonLiteral);
        $this->assertStringContainsString('app/Careless.php', $nonLiteral[0]);
        $this->assertSame(4, $files, 'the four PHP files, the owner included, and not the text file');
    }

    public function test_an_experiment_added_later_with_its_entry_and_its_call_passes_over_the_real_tree(): void
    {
        // The change that adds the first experiment adds its entry to config/ux_switches.php and its UxArm::for call
        // in the same commit. The check has to pass for that change, whatever the list held before it.
        [$switches] = $this->scanTree(base_path('app'));
        $entries = array_merge(array_values((array) config('ux_switches')), [
            ['name' => 'added-later', 'opened' => '2026-10-10', 'keep_until' => null, 'channel' => 'email', 'type' => 'Later', 'arms' => ['control', 'b']],
        ]);
        $switches[] = ['name' => 'added-later', 'file' => 'app/Mail/Later.php'];

        $this->assertSame([], self::problems($entries, $switches, $this->today()));
        $this->assertSame(
            ['added-later: in config/ux_switches.php but no UxArm::for($id, \'added-later\') left in the code; remove the entry'],
            self::problems($entries, array_slice($switches, 0, -1), $this->today()),
            'and the entry alone, with no call, is still found'
        );
    }

    public function test_says_so_without_failing_about_an_entry_left_past_14_days(): void
    {
        foreach (self::warnings((array) config('ux_switches'), Carbon::now('UTC')) as $warning) {
            fwrite(STDERR, "UX experiment cleanup overdue: $warning\n");
        }

        $this->assertTrue(true);
    }
}
