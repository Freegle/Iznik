<?php

namespace Tests\Feature\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Layer 2 (result parity) for the JSON predicates converted out of raw SQL.
 *
 * Layer 1 cannot speak to these. The builder equivalents render DIFFERENT SQL
 * from the raw statements they replace - whereJsonDoesntContainKey emits
 * not ifnull(json_contains_path(...), 0) where the raw said JSON_EXTRACT(...)
 * IS NULL - so a golden string comparison would simply fail. The question that
 * actually matters is whether the two forms select the same rows, and the only
 * honest way to answer it is to run both against data.
 *
 * WHY EACH TEST CARRIES A NEGATIVE CONTROL. A parity assertion over a table
 * with no interesting rows passes trivially: both sides return nothing and the
 * test proves nothing. Every test here therefore also runs the CONVERSION THAT
 * LOOKS RIGHT AND IS WRONG, and asserts it DIVERGES. If the fixture ever stops
 * exercising the edge cases, the negative control fails and says so, rather
 * than the positive assertion quietly going vacuous.
 *
 * The trap being guarded, established by MySQL truth table:
 * json_unquote() turns JSON true into the string 'true', false into 'false'
 * and JSON null into 'null'. MySQL casts each of those to 0 in a numeric
 * comparison. So ->where('settings->closed', 0) - which Laravel renders as
 * json_unquote(json_extract(...)) = 0 - matches true, false AND null, where
 * the raw JSON_EXTRACT(settings,'$.closed') = 0 matches only integer 0.
 * On Group::scopeNotClosed that reports every CLOSED group as open.
 */
class JsonPredicateParityTest extends TestCase
{
    /**
     * One row per JSON shape the settings column is known to hold. Group.php
     * documents the mixed int/bool storage in its own comment, so these are
     * real shapes, not hypotheticals.
     */
    private const FIXTURE = [
        1 => null,                  // column NULL
        2 => '{}',                  // key absent
        3 => '{"closed":null}',     // JSON null - NOT SQL NULL
        4 => '{"closed":false}',
        5 => '{"closed":true}',
        6 => '{"closed":0}',
        7 => '{"closed":1}',
        8 => '{"closed":"0"}',      // string, not number
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A TEMPORARY table keeps this independent of the groups schema and
        // leaves nothing behind. It lives on the write connection, which is
        // why every read below is pinned with useWritePdo(): with the
        // read/write split in play a plain select goes to the reader, which
        // cannot see it.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS json_parity_fixture');
        DB::statement('CREATE TEMPORARY TABLE json_parity_fixture (id INT PRIMARY KEY, settings JSON NULL)');

        foreach (self::FIXTURE as $id => $settings) {
            DB::table('json_parity_fixture')->insert(['id' => $id, 'settings' => $settings]);
        }
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS json_parity_fixture');

        parent::tearDown();
    }

    /** @return list<int> */
    private function ids(callable $build): array
    {
        return DB::table('json_parity_fixture')
            ->where($build)
            ->useWritePdo()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_not_closed_conversion_selects_the_same_rows(): void
    {
        $raw = fn ($q) => $q->whereNull('settings')
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') IS NULL")
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = false")
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = 0");

        $converted = fn ($q) => $q->whereNull('settings')
            ->orWhereJsonDoesntContainKey('settings->closed')
            ->orWhere('settings->closed', false)
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = 0");

        $this->assertSame([1, 2, 4, 6], $this->ids($raw), 'fixture no longer exercises the edge cases');
        $this->assertSame($this->ids($raw), $this->ids($converted));
    }

    /**
     * The negative control for the test above: converting the integer arm too
     * is the obvious next step and is wrong.
     */
    public function test_converting_the_integer_arm_would_change_which_rows_match(): void
    {
        $raw = fn ($q) => $q->whereNull('settings')
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') IS NULL")
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = false")
            ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = 0");

        $tempting = fn ($q) => $q->whereNull('settings')
            ->orWhereJsonDoesntContainKey('settings->closed')
            ->orWhere('settings->closed', false)
            ->orWhere('settings->closed', 0);

        $this->assertNotSame($this->ids($raw), $this->ids($tempting));

        // Specifically: it reports the CLOSED group as open.
        $this->assertContains(5, $this->ids($tempting));
        $this->assertNotContains(5, $this->ids($raw));
    }

    /**
     * whereJsonDoesntContainKey is key-ABSENCE, which is what the raw
     * IS NULL test actually meant. whereNull() on a JSON path is the wrong
     * tool: Laravel renders it as a compound that ALSO matches JSON null,
     * so it would match id 3 as well.
     */
    public function test_json_is_null_maps_to_key_absence_not_where_null(): void
    {
        $raw = fn ($q) => $q->whereRaw("JSON_EXTRACT(settings, '$.closed') IS NULL");

        $this->assertSame([1, 2], $this->ids($raw));
        $this->assertSame(
            $this->ids($raw),
            $this->ids(fn ($q) => $q->whereJsonDoesntContainKey('settings->closed'))
        );
        $this->assertSame(
            [1, 2, 3],
            $this->ids(fn ($q) => $q->whereNull('settings->closed')),
            'whereNull on a JSON path also matches JSON null - it is not the raw predicate'
        );
    }

    /**
     * Booleans are the one JSON comparison Laravel does NOT wrap in
     * json_unquote, which is why the boolean arms convert exactly.
     */
    public function test_boolean_comparison_is_exact(): void
    {
        $this->assertSame(
            $this->ids(fn ($q) => $q->whereRaw("JSON_EXTRACT(settings, '$.closed') = false")),
            $this->ids(fn ($q) => $q->where('settings->closed', false))
        );
        $this->assertSame(
            $this->ids(fn ($q) => $q->whereRaw("JSON_EXTRACT(settings, '$.closed') = true")),
            $this->ids(fn ($q) => $q->where('settings->closed', true))
        );
    }
}
