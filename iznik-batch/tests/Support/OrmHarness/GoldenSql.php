<?php

namespace Tests\Support\OrmHarness;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Layer 1 of the Laravel ORM migration harness (plan section 7.2): renders
 * the replacement query-builder call and checks it against the goldenSql
 * recorded for that site in tools/orm-migration/services/laravel/manifest.json,
 * via Canonical (Canonical.php). The Go counterpart is
 * iznik-server-go/ormharness/golden.go's AssertGoldenSQL; this is the design
 * ported to Laravel, not reinvented - read that file's own doc comment for
 * the parts that transfer unchanged (Gate 2's contract: a site cannot be
 * marked converted without a parity test bearing its ID; the approvedDiff
 * mechanism for a reviewed, justified divergence).
 *
 * NO DRY-RUN MODE NEEDED, UNLIKE GORM. golden.go has to open a *gorm.DB
 * against a DSN that is never dialled (dryRunDB, ~30 lines) because GORM
 * only finishes assembling Statement.SQL when a terminal method (Find,
 * Create, ...) actually runs, and running one against a real connection
 * would execute the query. Laravel's Query\Builder needs none of that: it
 * is lazy by construction, and toSql()/toRawSql()/getBindings() read the
 * builder's already-assembled state without executing anything - a real
 * database connection can be open (the harness runs inside the seeded test
 * DB's transaction like everything else) and no query reaches it just from
 * calling those three methods.
 *
 * HOW THE PLACEHOLDER RESOLUTION WORKS, AND WHY IT DIFFERS FROM GO'S:
 * golden.go resolves ONLY the LIMIT/OFFSET placeholders back into literals
 * (resolveLimitOffset) - GORM always binds those two, nothing else, so
 * every OTHER placeholder in both the golden and the rendered SQL stays as
 * a bare "?" and compares that way. The team lead's brief for this port
 * asked for something stronger: render "with fixed representative bind
 * values via toRawSql()" and compare against the golden - i.e. resolve
 * EVERY placeholder to a literal, not just two of them. Laravel makes this
 * cheap and exact rather than approximate: Query\Builder::toRawSql() is
 * itself just $this->grammar->substituteBindingsIntoRawSql($this->toSql(),
 * $this->getBindings()) (Query/Builder.php:3079-3083), a public Grammar
 * method that walks the SQL text and replaces each unescaped "?" with the
 * connection-escaped literal for the next binding, in positional order.
 * Rather than re-implement that substitution to also apply it to the
 * GOLDEN's placeholders, this calls the SAME grammar method on the golden
 * text with the SAME bindings array - true sharing of Laravel's own
 * quoting/escaping logic for literal substitution, the same principle
 * behind not writing a second canonicaliser, applied to a smaller piece
 * that happens to already be exposed as a public, standalone-callable
 * method.
 *
 * TWO PLACEHOLDER-COUNT GUARDS, NOT ONE. Go's assertRenderedSQL has a
 * single count check (rendered SQL's "?" count vs len(vars)) because it
 * never substitutes general placeholders on the golden side. This resolves
 * BOTH sides, so a mismatch can happen in two independent places: the
 * rendered SQL's placeholder count can disagree with the bindings the
 * builder actually collected (Go's original bug - the SQL text can look
 * right while the wrong number of arguments were bound), and separately
 * the GOLDEN's placeholder count can disagree with that same bindings
 * array (meaning the representative values the test chose do not match
 * the shape of the original call site). Both are checked, and reported
 * with distinct messages, before any text comparison runs - a partial or
 * misaligned substitution produces a confusing text diff that would bury
 * which of the two problems actually occurred.
 *
 * assert() ONLY WORKS FOR SELECT-SHAPED SITES (and WHERE-fragment sites
 * reached through a SELECT chain), and that is a real Laravel limitation,
 * not a design choice - found while writing the first real UPDATE-site
 * test for this class. Query\Builder::toSql() is HARDCODED to
 * $this->grammar->compileSelect($this) (Query/Builder.php:3067-3072) - it
 * is not "render whatever operation this chain represents", it always
 * compiles a SELECT, whatever methods were chained. Calling ->update([...])
 * or ->delete() instead of toSql() does not render-without-executing the
 * way GORM's dry-run session does for every verb: those methods on
 * Query\Builder ARE the execution, immediately, with no lazy/dry-run mode
 * to opt out of. assertUpdate()/assertDelete() below exist for exactly
 * this reason: they call the Grammar's compileUpdate()/compileDelete()
 * directly - the same public, non-executing methods Laravel's own
 * ->update()/->delete() call internally before running anything - so a
 * write-shaped site can be proven without touching the database. There is
 * no assertInsert() yet: this manifest has only 3 raw DB::insert() sites
 * (vs. dozens of UPDATE and DELETE), so it was not worth building ahead of
 * the first one that needs it - compileInsert(Builder, array) exists on
 * the same Grammar and the pattern below would extend to it directly.
 */
final class GoldenSql
{
    /**
     * @param  callable(): QueryBuilder|EloquentBuilder  $build  Builds (but
     *         must not execute) the replacement query, using fixed
     *         representative values for every bind point - e.g.
     *         fn () => DB::table('users')->where('id', 5)
     */
    public static function assert(string $siteId, callable $build): void
    {
        $query = self::normaliseBuiltQuery($siteId, $build());
        self::compareAndAssert($siteId, $query->toSql(), $query->getBindings());
    }

    /**
     * Layer 1 for an INSERT IGNORE site, i.e. one converted with
     * ->insertOrIgnore(). Same contract as assertInsert(): $build returns
     * [query, row].
     *
     * Separate from assertInsert because the modifier is not cosmetic - it is
     * the difference between skipping a unique-key collision and aborting the
     * statement - so it must be rendered and compared, not assumed.
     *
     * @param  callable(): array{0:QueryBuilder|EloquentBuilder,1:array}  $build
     */
    public static function assertInsertOrIgnore(string $siteId, callable $build): void
    {
        [$built, $values] = $build();
        $query = self::normaliseBuiltQuery($siteId, $built);
        $grammar = $query->getGrammar();

        $sql = $grammar->compileInsertOrIgnore($query, [$values]);
        $bindings = $query->cleanBindings(array_values($values));

        self::compareAndAssert($siteId, $sql, $bindings);
    }

    /**
     * Layer 1 for an upsert site, i.e. one converted with ->upsert().
     * $build returns [query, rows, uniqueBy, update].
     *
     * @param  callable(): array{0:QueryBuilder|EloquentBuilder,1:array,2:array,3:array}  $build
     */
    public static function assertUpsert(string $siteId, callable $build): void
    {
        [$built, $rows, $uniqueBy, $update] = $build();
        $query = self::normaliseBuiltQuery($siteId, $built);
        $grammar = $query->getGrammar();

        $sql = $grammar->compileUpsert($query, $rows, $uniqueBy, $update);

        // compileUpsert emits the VALUES binds followed by the update binds, in
        // that order - mirroring what Builder::upsert() passes to the
        // connection. Building them here rather than reading getBindings()
        // because the query itself carries none: everything is in $rows.
        $bindings = [];
        foreach ($rows as $row) {
            foreach ($row as $v) {
                $bindings[] = $v;
            }
        }
        foreach ($update as $k => $v) {
            if (! is_int($k)) {
                $bindings[] = $v;
            }
        }

        self::compareAndAssert($siteId, $sql, $query->cleanBindings($bindings));
    }

    /**
     * Layer 1 for an UPDATE-shaped site. $build returns [query, values]:
     * the query builder carrying the table and WHERE (built but, same
     * discipline as assert(), never executed - do not call ->update() on
     * it), and the $values array ->update() would have been given. e.g.
     *
     *   GoldenSql::assertUpdate($siteId, fn () => [
     *       DB::table('chat_rooms')->where('id', 5),
     *       ['latestmessage' => DB::raw('NOW()')],
     *   ]);
     *
     * @param  callable(): array{0:QueryBuilder|EloquentBuilder,1:array}  $build
     */
    public static function assertUpdate(string $siteId, callable $build): void
    {
        [$built, $values] = $build();
        $query = self::normaliseBuiltQuery($siteId, $built);
        $grammar = $query->getGrammar();

        $sql = $grammar->compileUpdate($query, $values);
        // getRawBindings(), not getBindings(): prepareBindingsForUpdate expects the
        // PER-TYPE structure ({select:[], from:[], join:[], where:[], ...}) - it
        // reads $bindings['join'] and Arr::except($bindings, ['select','join'])
        // directly - but getBindings() returns that structure already FLATTENED
        // into one list, so $bindings['join'] does not exist on it. Found the hard
        // way: "Undefined array key 'join'" on every real assertUpdate() call.
        // Laravel's own Builder::update() passes $this->bindings (the raw property,
        // i.e. the same shape getRawBindings() exposes) to this exact call - so this
        // is not a workaround, it is doing what production code does.
        //
        // cleanBindings() matters too, separately: prepareBindingsForUpdate flattens
        // $values into the bindings array UNCONDITIONALLY, including any DB::raw()
        // value (e.g. 'latestmessage' => DB::raw('NOW()')) - but compileUpdate
        // already inlined that same expression as literal text via parameter(), so
        // it is not a placeholder at all. Skipping cleanBindings() would count a raw
        // expression as a bind argument with no "?" for it, tripping the
        // placeholder-count guard on a case that is not actually wrong.
        $bindings = $query->cleanBindings($grammar->prepareBindingsForUpdate($query->getRawBindings(), $values));

        self::compareAndAssert($siteId, $sql, $bindings);
    }

    /**
     * Layer 1 for an INSERT-shaped site. $build returns [query, values]: the
     * query builder carrying the table (built but never executed - do not call
     * ->insert() on it), and the row ->insert() would have been given.
     *
     *   GoldenSql::assertInsert($siteId, fn () => [
     *       DB::table('memberships_history'),
     *       ['userid' => 1, 'groupid' => 2, 'collection' => 'Approved'],
     *   ]);
     *
     * Column ORDER matters and is not normalised away: compileInsert emits the
     * columns in the order the array gives them, and an INSERT whose columns
     * and values disagree writes the wrong data while still being valid SQL.
     * That is precisely the kind of mistake a golden comparison should catch,
     * so the array must be written in the raw statement's column order.
     *
     * @param  callable(): array{0:QueryBuilder|EloquentBuilder,1:array}  $build
     */
    public static function assertInsert(string $siteId, callable $build): void
    {
        [$built, $values] = $build();
        $query = self::normaliseBuiltQuery($siteId, $built);
        $grammar = $query->getGrammar();

        $sql = $grammar->compileInsert($query, [$values]);
        // compileInsert takes a LIST of rows; a single row is wrapped above so
        // the emitted statement has one VALUES tuple, matching the raw form.
        // cleanBindings() for the same reason assertUpdate needs it: a DB::raw
        // value is inlined into the SQL by parameter(), so it is not a bind and
        // counting it would trip the placeholder guard on a correct statement.
        $bindings = $query->cleanBindings(array_values($values));

        self::compareAndAssert($siteId, $sql, $bindings);
    }

    /**
     * Layer 1 for a DELETE-shaped site. $build returns the query builder
     * carrying the table and WHERE, un-executed (do not call ->delete() on
     * it - same reasoning as assertUpdate()).
     *
     * @param  callable(): QueryBuilder|EloquentBuilder  $build
     */
    public static function assertDelete(string $siteId, callable $build): void
    {
        $query = self::normaliseBuiltQuery($siteId, $build());
        $grammar = $query->getGrammar();

        $sql = $grammar->compileDelete($query);
        // getRawBindings(), not getBindings() - see assertUpdate()'s comment above;
        // prepareBindingsForDelete expects the same per-type structure.
        $bindings = $query->cleanBindings($grammar->prepareBindingsForDelete($query->getRawBindings()));

        self::compareAndAssert($siteId, $sql, $bindings);
    }

    private static function normaliseBuiltQuery(string $siteId, mixed $built): QueryBuilder
    {
        $query = $built instanceof EloquentBuilder ? $built->toBase() : $built;
        if (! $query instanceof QueryBuilder) {
            Assert::fail(sprintf(
                'ormharness: site "%s": build() must return a Query\Builder or Eloquent\Builder, got %s',
                $siteId,
                get_debug_type($built)
            ));
        }

        return $query;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private static function compareAndAssert(string $siteId, string $sql, array $bindings): void
    {
        $site = Manifest::site($siteId);
        $goldenSql = $site['goldenSql'] ?? '';
        if (trim($goldenSql) === '') {
            Assert::fail("ormharness: manifest site \"{$siteId}\" has no goldenSql to compare against");
        }

        $renderedPlaceholders = Canonical::countPlaceholders($sql);
        if ($renderedPlaceholders !== count($bindings)) {
            Assert::fail(sprintf(
                "ormharness: site \"%s\": the statement has %d placeholder(s) but %d bind argument(s) were supplied.\n".
                "The SQL text can still match the golden exactly while this is wrong, so it is checked separately.\n".
                "Look for a value passed to select/where/etc that the expression has no \"?\" for, or one bound twice.\n".
                'rendered: %s',
                $siteId,
                $renderedPlaceholders,
                count($bindings),
                $sql
            ));
        }

        // Not $query->getGrammar(): compareAndAssert no longer has a query
        // object (assertUpdate/assertDelete only pass through sql+bindings,
        // already compiled). Any Query\Builder's grammar would do the same
        // job here - substituteBindingsIntoRawSql takes plain sql+bindings,
        // not the builder - so the default connection's grammar is used
        // directly rather than threading one through three call shapes.
        $grammar = DB::connection()->getQueryGrammar();
        $renderedRaw = $grammar->substituteBindingsIntoRawSql($sql, $bindings);

        // The approvedDiff is checked HERE, before the golden placeholder-count
        // guard below, and the ordering is load-bearing.
        //
        // By far the commonest real divergence is that the raw statement INLINED
        // a constant and the builder BINDS it - `SET replyexpected = 0` becomes
        // `set replyexpected = ?`, `role IN ('Owner','Moderator')` becomes
        // `role in (?, ?)`. That is exactly a change in the number of bind
        // arguments, so the guard below fires on it and, when the check sat
        // after that guard, killed the test before the approved diff could be
        // consulted at all: a diff recorded for precisely this case could never
        // be honoured. Found the first time a real conversion needed one - Phase
        // B could not have caught it, because every site ProvenSitesTest proves
        // matches its golden exactly.
        //
        // Comparing here is sound: the approvedDiff IS the emitted statement, so
        // its placeholders and the bindings are 1:1 by construction, and the
        // golden's own count is irrelevant when we are deliberately not
        // comparing against the golden.
        $approvedDiff = $site['approvedDiff'] ?? '';
        if ($approvedDiff !== '') {
            $approvedPlaceholders = Canonical::countPlaceholders($approvedDiff);
            if ($approvedPlaceholders !== count($bindings)) {
                Assert::fail(sprintf(
                    "ormharness: site \"%s\": the recorded approvedDiff has %d placeholder(s) but the replacement ".
                    "bound %d argument(s). An approvedDiff must be the statement the builder actually emits, so its ".
                    "placeholders and the bindings have to line up 1:1.\n".
                    "  approvedDiff: %s\n".
                    "  rendered:     %s",
                    $siteId,
                    $approvedPlaceholders,
                    count($bindings),
                    $approvedDiff,
                    $sql
                ));
            }
            $approvedRaw = $grammar->substituteBindingsIntoRawSql($approvedDiff, $bindings);
            if (Canonical::normalise($approvedRaw) === Canonical::normalise($renderedRaw)) {
                Assert::assertTrue(true); // a reviewer already recorded and justified exactly this divergence
                return;
            }
        }

        $goldenPlaceholders = Canonical::countPlaceholders($goldenSql);
        if ($goldenPlaceholders !== count($bindings)) {
            Assert::fail(sprintf(
                "ormharness: site \"%s\": the recorded goldenSql has %d placeholder(s) but the replacement bound %d ".
                "argument(s), so they cannot be substituted 1:1 to compare literal-for-literal.\n".
                "  golden:   %s\n".
                "  rendered: %s\n".
                'Usually the representative bind values chosen for this test do not match the shape of the '.
                "original call site (extra/missing WHERE conditions, a different number of columns, etc).\n".
                'If instead the split genuinely changed - the raw statement inlined a constant and the builder '.
                'binds it, which is the commonest case - that is what an approvedDiff is for: record the emitted '.
                'statement in services/laravel/approved-diffs.json with a reason.',
                $siteId,
                $goldenPlaceholders,
                count($bindings),
                $goldenSql,
                $sql
            ));
        }
        $goldenRaw = $grammar->substituteBindingsIntoRawSql($goldenSql, $bindings);

        $wantCanon = Canonical::normalise($goldenRaw);
        $gotCanon = Canonical::normalise($renderedRaw);

        if ($wantCanon === $gotCanon) {
            Assert::assertSame($wantCanon, $gotCanon); // real assertion, so PHPUnit does not flag the test as risky
            return;
        }

        $verdict = $approvedDiff === ''
            ? 'no approvedDiff is recorded for this site'
            : 'the rendered SQL does not match the recorded approvedDiff either';

        Assert::fail(sprintf(
            "ormharness: site \"%s\": rendered SQL diverges from goldenSql and %s\n".
            "  golden SQL (source):     %s\n".
            "  golden SQL (resolved):   %s\n".
            "  rendered SQL (source):   %s\n".
            "  rendered SQL (resolved): %s\n".
            "  canonical golden:        %s\n".
            '  canonical rendered:      %s',
            $siteId,
            $verdict,
            $goldenSql,
            $goldenRaw,
            $sql,
            $renderedRaw,
            $wantCanon,
            $gotCanon
        ));
    }
}
