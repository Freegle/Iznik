<?php

namespace Tests\Unit\Services\FirstReply;

use App\Services\FirstReply\MaxReachService;
use App\Services\Ripple\MaxReachCandidateIndex;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The maxreach candidate scan, which runs once a minute against a ~50GB table
 * on the read node and so is worth pinning properly.
 *
 * Two things here are invisible to ordinary tests and both have already gone
 * wrong in production:
 *
 *  - The QUERY PLAN can change while the SQL and the results stay identical.
 *    That is exactly what happened when 993a85b7b made the predicate match
 *    nothing: same query, same (empty) answer, 2m09s instead of milliseconds.
 *    So the tests that matter here EXPLAIN the real query and assert which
 *    index it drives off.
 *  - FORCE INDEX names an index as a STRING. If that index is renamed or
 *    dropped, MySQL raises "Key ... doesn't exist in table" - a hard error, not
 *    a fallback - and first-reply match mail stops for everyone. Nothing else
 *    in the codebase would notice, so it is asserted here.
 */
class MaxReachCandidateScanTest extends TestCase
{
    /** A small box for the outer bound; nothing here reads the geometry. */
    private const REACH = 'POLYGON((-0.10 51.50, -0.08 51.50, -0.08 51.52, -0.10 51.52, -0.10 51.50))';

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachCandidateIndex::reset();
    }

    protected function tearDown(): void
    {
        MaxReachCandidateIndex::reset();
        parent::tearDown();
    }

    private function service(): MaxReachService
    {
        return app(MaxReachService::class);
    }

    /**
     * The index the FORCE INDEX fallback names must exist. A missing one is a
     * hard MySQL error on every run of the pass, not a slow query.
     */
    public function test_the_index_the_hint_names_actually_exists(): void
    {
        $this->assertGreaterThan(
            0,
            $this->indexCount('rippling_reach_status_index'),
            'MaxReachService FORCE INDEXes rippling_reach_status_index; if it has been '
            .'renamed or dropped, the candidate scan raises "Key does not exist" every minute'
        );
    }

    /**
     * The composite, and its column ORDER. Order is the whole point: equality
     * on (status, has_max_reach) then updated_at already sorted, so the
     * ORDER BY ... LIMIT is a backward index scan that stops early. Drop
     * updated_at from the index and the filesort returns, silently.
     */
    public function test_the_composite_index_exists_with_its_columns_in_order(): void
    {
        // Aliased explicitly: information_schema reports column names in upper
        // case here, so pluck('column_name') finds nothing.
        $cols = array_map(
            fn ($r) => $r->col,
            DB::select(
                "SELECT column_name AS col FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                    AND index_name = 'rippling_reach_maxreach_candidates'
                  ORDER BY seq_in_index"
            )
        );

        $this->assertSame(['status', 'has_max_reach', 'updated_at'], $cols,
            'the candidate index must be (status, has_max_reach, updated_at) in that order');
    }

    /** The generated column must be defined over max_polygon_cells ALONE. */
    public function test_the_generated_column_pins_only_the_column_that_survives(): void
    {
        $row = DB::selectOne(
            "SELECT generation_expression AS expr FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND column_name = 'has_max_reach'"
        );
        $expr = $row->expr ?? null;

        $this->assertNotNull($expr, 'has_max_reach is missing');
        $this->assertStringContainsString('max_polygon_cells', (string) $expr);

        // A generated column pins every column it names, so referencing either
        // of these would make MySQL REFUSE to drop them and would break the
        // legacy-geometry drop migration outright.
        foreach (['max_polygon_hash', 'overflow_bounds'] as $dropped) {
            $this->assertStringNotContainsString($dropped, (string) $expr,
                "has_max_reach must not reference $dropped - it is dropped later, and a "
                .'generated column referencing it would block that drop');
        }
        $this->assertDoesNotMatchRegularExpression('/\bmax_polygon\b(?!_cells)/', (string) $expr,
            'has_max_reach must not reference max_polygon - it is dropped later');
    }

    /**
     * THE test. With the index present the query must actually drive off it,
     * and must not sort. Asserting the SQL text would pass while the plan
     * silently reverted to the whole-index walk this exists to prevent.
     */
    public function test_with_the_index_the_plan_uses_it_and_does_not_sort(): void
    {
        MaxReachCandidateIndex::fake(ready: true);

        $plan = $this->explain($this->service()->candidateQuery(200));

        $this->assertSame('rippling_reach_maxreach_candidates', $plan->key,
            'the candidate scan must drive off the composite index, not '.($plan->key ?? 'nothing'));
        $this->assertStringNotContainsStringIgnoringCase('filesort', (string) $plan->Extra,
            'the index carries updated_at precisely so this does not sort');
    }

    /**
     * And without it, the #1404 fallback must drive off the status index -
     * NOT rippling_reach_updated_at, which is the pathological choice.
     */
    public function test_without_the_index_the_hint_still_avoids_the_updated_at_walk(): void
    {
        MaxReachCandidateIndex::fake(ready: false);

        $plan = $this->explain($this->service()->candidateQuery(200));

        $this->assertSame('rippling_reach_status_index', $plan->key);
        $this->assertNotSame('rippling_reach_updated_at', $plan->key,
            'driving off updated_at is the 2m09s whole-index walk PR #1404 fixed');
    }

    /**
     * The hint and the index are alternatives. Keeping the hint once the index
     * exists is not merely redundant - it PINS the planner to the worse index
     * and the composite is never used. This asserts the two never coexist.
     */
    public function test_the_hint_disappears_once_the_index_exists(): void
    {
        MaxReachCandidateIndex::fake(ready: true);
        $withIndex = $this->service()->candidateQuery(200)->toSql();

        MaxReachCandidateIndex::reset();
        MaxReachCandidateIndex::fake(ready: false);
        $withoutIndex = $this->service()->candidateQuery(200)->toSql();

        $this->assertStringNotContainsString('FORCE INDEX', $withIndex,
            'with the composite in place a FORCE INDEX pins the planner to the WRONG index');
        $this->assertStringContainsString('has_max_reach', $withIndex,
            'MySQL will not substitute the generated column, so the query must name it');

        $this->assertStringContainsString('FORCE INDEX', $withoutIndex);
        $this->assertStringNotContainsString('has_max_reach', $withoutIndex,
            'naming a column that does not exist yet would kill the pass outright');
    }

    /**
     * #1404's own check, kept: only the FROM clause is raw. If `status` ever
     * became string-interpolated instead of bound, the raw FROM would be the
     * thing that made it look acceptable.
     */
    public function test_status_stays_a_bound_parameter_in_both_forms(): void
    {
        foreach ([true, false] as $indexed) {
            MaxReachCandidateIndex::reset();
            MaxReachCandidateIndex::fake(ready: $indexed);

            $q = $this->service()->candidateQuery(200);
            $this->assertContains('expanding', $q->getBindings(),
                'status must be bound, not interpolated');
            $this->assertStringNotContainsString("'expanding'", $q->toSql());
        }
    }

    /**
     * Neither form may narrow what the pass can SEE. A faster query that finds
     * less work is not a fix, and an index hint is exactly the kind of change
     * that can quietly do that.
     */
    public function test_both_forms_find_the_same_candidate(): void
    {
        $msgid = $this->seedCandidate();

        $found = [];
        foreach ([true, false] as $indexed) {
            MaxReachCandidateIndex::reset();
            MaxReachCandidateIndex::fake(ready: $indexed);

            $ids = array_map(
                fn ($r) => (int) $r->msgid,
                $this->service()->candidateQuery(200)->get()->all()
            );
            $found[$indexed ? 'indexed' : 'hinted'] = in_array($msgid, $ids, true);
        }

        $this->assertTrue($found['indexed'], 'the indexed form missed a real candidate');
        $this->assertTrue($found['hinted'], 'the hinted form missed a real candidate');
    }

    /** A post that already HAS a max reach must not be offered by either form. */
    public function test_neither_form_offers_a_post_that_already_has_a_max_reach(): void
    {
        $msgid = $this->seedCandidate();
        DB::table('rippling_reach')->where('msgid', $msgid)
            ->update(['max_polygon_cells' => 'not null at all']);

        foreach ([true, false] as $indexed) {
            MaxReachCandidateIndex::reset();
            MaxReachCandidateIndex::fake(ready: $indexed);

            $ids = array_map(
                fn ($r) => (int) $r->msgid,
                $this->service()->candidateQuery(200)->get()->all()
            );
            $this->assertNotContains($msgid, $ids,
                ($indexed ? 'indexed' : 'hinted').' form re-offered a filled row');
        }
    }

    /** The guard reports the real schema, and can be moved for a test. */
    public function test_the_guard_reads_the_schema_and_can_be_faked(): void
    {
        MaxReachCandidateIndex::reset();
        $this->assertTrue(MaxReachCandidateIndex::ready(),
            'the test schema should carry the candidate index');

        MaxReachCandidateIndex::fake(ready: false);
        $this->assertFalse(MaxReachCandidateIndex::ready());

        MaxReachCandidateIndex::reset();
        $this->assertTrue(MaxReachCandidateIndex::ready(), 'reset must drop the override');
    }

    private function indexCount(string $name): int
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND index_name = ?",
            [$name]
        );

        return (int) ($row->n ?? 0);
    }

    /** EXPLAIN the builder's own SQL and bindings, so the plan tested is the real one. */
    private function explain(\Illuminate\Database\Query\Builder $q): object
    {
        $rows = DB::select('EXPLAIN '.$q->toSql(), $q->getBindings());
        $this->assertNotEmpty($rows, 'EXPLAIN returned nothing');

        return $rows[0];
    }

    private function seedCandidate(): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, schedule, status, outer_bound, arrival, mode,
                tick, total_ticks, total_freeglers, max_drive_min, created_at, updated_at)
             VALUES (?, 51.51, -0.09, ?, 'expanding',
                     ST_Buffer(ST_GeomFromText(?, 3857), 0.002),
                     NOW(), 'drive', 1, 3, 0, 30, NOW(), NOW())",
            [
                $msgid,
                json_encode([['drive_min' => 30]]),
                self::REACH,
            ]
        );

        return $msgid;
    }
}
