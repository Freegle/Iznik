<?php

namespace Tests\Support\OrmHarness;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Assert;

/**
 * Layer 2 of the Laravel ORM migration harness (plan section 7.2): runs the
 * original raw SQL and the replacement query-builder call against the
 * seeded test database and diffs the result sets. Catches what Layer 1's
 * string comparison cannot - collation, NULL handling, implicit-cast
 * semantics that only show up once a query hits the engine. Design ported
 * from iznik-server-go/ormharness/resultparity.go's AssertResultParity;
 * read that file's own doc comment for the parts that transfer unchanged
 * (order-sensitive comparison only when the original SQL contains ORDER BY,
 * unordered/canonical-sorted comparison otherwise, because without ORDER BY
 * the engine is free to return rows in any order and old/new need not agree
 * on one).
 *
 * ONE RUNNER FOR BOTH SIDES, BY DESIGN - THE ONE GENUINE SIMPLIFICATION
 * VERSUS resultparity.go. Go's version runs the original through
 * db.Raw(...).Rows() and the replacement through the GORM chain's OWN
 * .Rows(), two different code paths that happen to share one *sql.DB
 * connection pool - and because of that, roughly a third of resultparity.go
 * (protocolHint, renderForComparison) exists purely to explain a real
 * go-sql-driver/mysql quirk: the driver returns []byte for every column
 * under MySQL's TEXT protocol but native Go types under the BINARY
 * protocol, and which protocol a statement gets depends on whether it was
 * prepared, which in turn depends on whether it has arguments - so a
 * statement with bind args and the "same" statement without can come back
 * with different Go types for equal data. This class does not need that
 * defence, and not by accident: the replacement is rendered via
 * toSql()/getBindings() and then run through runRawSql() - THE SAME
 * function, same PDO instance, same fetch settings - that runs the
 * original. There is only one code path, so there is no second protocol to
 * diverge from the first; a type or NULL difference here can only mean the
 * two statements are genuinely different, never an artefact of how each was
 * executed.
 *
 * WHY QUERY\BUILDER, NOT ELOQUENT MODEL HYDRATION. Go's Layer 2 scans BOTH
 * sides generically via database/sql's *sql.Rows, deliberately not through
 * a GORM-scanned struct - see scanRowsGenerically's own comment: it wants
 * the driver's native, unmodified representation on both sides. The same
 * reasoning rules out fetching the replacement through Eloquent's
 * $model->attributesToArray() (which applies $casts) here: a model
 * attribute cast (e.g. 'id' => 'integer') could turn a string PDO returns
 * into a genuine int, an artificial divergence from the RAW query's output
 * that has nothing to do with whether the two statements select the same
 * data. assert()/assertForSite() normalise any Eloquent\Builder to its
 * underlying Query\Builder (->toBase()) before rendering, for the same
 * reason GoldenSql.php does.
 */
final class ResultParity
{
    /**
     * @param  callable(): QueryBuilder|EloquentBuilder  $replacementBuild
     */
    public static function assert(string $originalSql, array $bindings, callable $replacementBuild): void
    {
        [$originalCols, $originalRows] = self::runRawSql($originalSql, $bindings);

        $built = $replacementBuild();
        $query = $built instanceof EloquentBuilder ? $built->toBase() : $built;
        if (! $query instanceof QueryBuilder) {
            Assert::fail(sprintf(
                'ormharness: replacementBuild() must return a Query\Builder or Eloquent\Builder, got %s',
                get_debug_type($built)
            ));

            return;
        }
        [$replacementCols, $replacementRows] = self::runRawSql($query->toSql(), $query->getBindings());

        self::assertSameColumnSet($originalCols, $replacementCols);

        if (preg_match('/\border\s+by\b/i', $originalSql) === 1) {
            self::assertRowsEqualOrdered($originalCols, $originalRows, $replacementRows);
        } else {
            self::assertRowsEqualUnordered($originalCols, $originalRows, $replacementRows);
        }
    }

    /**
     * AssertResultParityForSite equivalent: same as assert(), but verifies
     * $siteId is a real manifest entry first, so a typo'd site id fails
     * loudly rather than quietly proving nothing. Exists for the same
     * reason golden.go's AssertResultParityForSite does - a site whose only
     * proof is a Layer 2 test has no other place its id gets checked
     * mechanically against the manifest.
     *
     * @param  callable(): QueryBuilder|EloquentBuilder  $replacementBuild
     */
    public static function assertForSite(string $siteId, string $originalSql, array $bindings, callable $replacementBuild): void
    {
        Manifest::site($siteId); // throws if $siteId is not real; see Manifest::site()
        self::assert($originalSql, $bindings, $replacementBuild);
    }

    /**
     * Runs $sql through the SAME low-level path regardless of whether it is
     * the hand-written original or the query-builder's own rendered SQL -
     * see this class's header for why that single-path design is what
     * removes the protocol-divergence class of false positive Go's
     * equivalent has to explain away.
     *
     * @return array{0:list<string>,1:list<array<string,mixed>>}
     */
    private static function runRawSql(string $sql, array $bindings): array
    {
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($bindings));

        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = $meta['name'] ?? "col{$i}";
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return [$columns, $rows];
    }

    /**
     * @param  list<string>  $originalCols
     * @param  list<string>  $replacementCols
     */
    private static function assertSameColumnSet(array $originalCols, array $replacementCols): void
    {
        $a = $originalCols;
        $b = $replacementCols;
        sort($a);
        sort($b);

        Assert::assertSame($a, $b, sprintf(
            "ormharness: column sets differ\n  original:    %s\n  replacement: %s",
            implode(', ', $originalCols),
            implode(', ', $replacementCols)
        ));
    }

    /**
     * @param  list<string>  $cols
     * @param  list<array<string,mixed>>  $original
     * @param  list<array<string,mixed>>  $replacement
     */
    private static function assertRowsEqualOrdered(array $cols, array $original, array $replacement): void
    {
        Assert::assertCount(
            count($original),
            $replacement,
            sprintf('ormharness: row count differs (order-sensitive comparison): original=%d replacement=%d', count($original), count($replacement))
        );

        foreach ($original as $i => $row) {
            self::assertRowEqual($cols, "row {$i}", $row, $replacement[$i]);
        }
    }

    /**
     * Both result sets are sorted into the same canonical order (a
     * deterministic string encoding of each row, so equal rows sort
     * adjacently and identically on both sides) before comparing position
     * by position. This is only a sort key, not the equality test itself -
     * assertRowEqual still does the real, type-and-NULL-sensitive
     * comparison once rows are lined up.
     *
     * @param  list<string>  $cols
     * @param  list<array<string,mixed>>  $original
     * @param  list<array<string,mixed>>  $replacement
     */
    private static function assertRowsEqualUnordered(array $cols, array $original, array $replacement): void
    {
        Assert::assertCount(
            count($original),
            $replacement,
            sprintf('ormharness: row count differs (unordered comparison): original=%d replacement=%d', count($original), count($replacement))
        );

        $sortedOriginal = self::sortRowsCanonically($cols, $original);
        $sortedReplacement = self::sortRowsCanonically($cols, $replacement);

        foreach ($sortedOriginal as $i => $row) {
            self::assertRowEqual($cols, "row {$i} (canonical order, no ORDER BY on the original query)", $row, $sortedReplacement[$i]);
        }
    }

    /**
     * @param  list<string>  $cols
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private static function sortRowsCanonically(array $cols, array $rows): array
    {
        $out = $rows;
        usort($out, fn ($a, $b) => self::canonicalRowKey($cols, $a) <=> self::canonicalRowKey($cols, $b));

        return $out;
    }

    /**
     * @param  list<string>  $cols
     * @param  array<string,mixed>  $row
     */
    private static function canonicalRowKey(array $cols, array $row): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $parts[] = $c.'='.self::formatValue($row[$c] ?? null);
        }

        return implode("\x1f", $parts); // unit separator: cheap, unlikely-to-collide delimiter
    }

    /**
     * @param  list<string>  $cols
     * @param  array<string,mixed>  $original
     * @param  array<string,mixed>  $replacement
     */
    private static function assertRowEqual(array $cols, string $rowLabel, array $original, array $replacement): void
    {
        foreach ($cols as $c) {
            $originalValue = $original[$c] ?? null;
            $replacementValue = $replacement[$c] ?? null;

            // Strict (===) so a NULL never matches "" or 0, and (for the
            // small number of columns where the two sides could disagree
            // on PHP type despite equal underlying data) a genuine type
            // difference still fails rather than being coerced away by ==.
            if ($originalValue === $replacementValue) {
                continue;
            }

            Assert::fail(sprintf(
                "ormharness: %s, column \"%s\" differs:\n  original:    %s\n  replacement: %s",
                $rowLabel,
                $c,
                self::formatValue($originalValue),
                self::formatValue($replacementValue)
            ));
        }
        Assert::assertTrue(true); // record an assertion per row so PHPUnit does not flag the test as risky
    }

    private static function formatValue(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }

        return sprintf('%s (%s)', var_export($v, true), get_debug_type($v));
    }
}
