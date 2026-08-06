#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * extract.php builds the raw-SQL inventory for iznik-batch (Laravel). See
 * plans/database-migration-evaluation-2026-07.md section 7.1 for the "why":
 * the inventory is a written contract, not anyone's memory.
 *
 * The Go half of this programme is FINISHED (iznik-server-go reached zero raw
 * sites), so its extractor, manifest, parity harness and the ci-ratchet.sh gate
 * that enforced them have all been removed - new raw SQL is now kept out at
 * authoring time by .claude/check-raw-sql.sh, which covers both stacks. This
 * tool and the Laravel manifest survive because the Laravel half is still in
 * progress. That work HAS started: sites have been converted and proven, and
 * a large part of the original keep-raw triage turned out to be wrong - JSON,
 * DDL, clock/date and redundant LOWER() calls all had builder equivalents the
 * triage had ruled out without checking. The inventory is the record of what
 * remains, not a claim that nothing has moved.
 *
 * WHY A SEPARATE STANDALONE TOOL, NOT A DEPENDENCY OF iznik-batch ITSELF
 * ------------------------------------------------------------------------
 * nikic/php-parser is already resolvable inside iznik-batch's own vendor/,
 * but only as a transitive dev-dependency of phpunit/php-code-coverage and
 * psy/psysh - exactly the kind of thing that breaks silently on an unrelated
 * `composer update`. This tool requires it explicitly and pins its own
 * composer.lock (composer.json beside this file). Keeping it standalone also
 * keeps it cheap to run: it needs one AST parser, not iznik-batch's whole
 * Laravel dependency tree.
 *
 * THE RAW-SQL METHOD SURFACE, AND HOW IT WAS ENUMERATED
 * ------------------------------------------------------------------------
 * Plan 7.1 names six: "DB::select/statement/insert/update/delete/raw,
 * whereRaw, selectRaw, orderByRaw, havingRaw". That list was written by
 * memory, the same way the Go inventory's method list was originally written
 * by memory and then found to miss RetryExec (12 invisible statements) and
 * the .Select()/BuildClauses smuggling pattern (5 more). So this list was not
 * copied from the plan: it was re-derived by grepping the actual method
 * signatures out of vendor/laravel/framework (v12.42.0, this repo's pinned
 * version) for every method whose signature takes a raw SQL/expression
 * string as its first argument. That check found four the plan's list
 * missed - DB::scalar() and DB::cursor() (Connection.php), and the
 * fragment-carrying orWhereRaw()/orHavingRaw()/fromRaw()/groupByRaw() beyond
 * the four the plan names - and one the "Raw" naming convention suggests but
 * is NOT raw SQL text at all (see EXCLUDED, below). Re-run this check after
 * any Laravel upgrade:
 *
 *   grep -n 'public function.*Raw(' vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php
 *   grep -nE 'public function (select|selectOne|scalar|cursor|insert|update|delete|statement|unprepared|raw)\(' \
 *       vendor/laravel/framework/src/Illuminate/Database/Connection.php
 *   grep -n 'public function raw' vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php
 *
 * CONNECTION_SQL_METHODS - static calls on the DB facade (or any injected
 * \Illuminate\Database\ConnectionInterface). Each carries a WHOLE statement
 * (or, for raw(), an expression meant to be embedded in one) as its first
 * argument. Connection.php line numbers as of v12.42.0:
 *   select (395), selectOne (341), scalar (358), cursor (458), insert (521),
 *   update (533), delete (545), statement (557), unprepared (611), raw (1095).
 *
 * BUILDER_FRAGMENT_METHODS - instance methods on Query\Builder, reachable via
 * DB::table(...)->, Model::query()->, or an Eloquent model forwarding through
 * __call to its underlying query builder. Each carries a FRAGMENT (a WHERE/
 * HAVING/ORDER BY/GROUP BY/SELECT-list/FROM piece), not a whole statement -
 * see "surface" in the Site shape below for why that is recorded, not just
 * noted here. Query/Builder.php line numbers as of v12.42.0: selectRaw (326),
 * fromRaw (360), whereRaw (1109), orWhereRaw (1125), groupByRaw (2458),
 * havingRaw (2652), orHavingRaw (2669), orderByRaw (2758).
 *
 * BLUEPRINT_DDL_METHODS - Schema\Blueprint methods, migrations only:
 * rawIndex (704), rawColumn (1676) in Schema/Blueprint.php.
 *
 * EXCLUDED, ON THE RECORD RATHER THAN BY OMISSION: whereIntegerInRaw,
 * orWhereIntegerInRaw, whereIntegerNotInRaw, orWhereIntegerNotInRaw
 * (Query/Builder.php 1286-1337). Despite the "Raw" name these do not take a
 * SQL string - their signature is ($column, array $values, ...) - they skip
 * the usual bind-type casting for a pre-validated integer array, which is a
 * performance detail, not a text-injection surface. Listed here so the
 * exclusion is a decision on record, not a gap the next person has to
 * rediscover by reading the source themselves.
 *
 * WHAT DOES NOT NEED PORTING FROM extract.go, AND WHY
 * ------------------------------------------------------------------------
 * Go's extractor spends real machinery filtering out non-SQL calls, because
 * Exec()/Query() are generic method names shared with net/http, os/exec,
 * template execution and Fiber's HTTP-query-parameter getter - hence
 * dbHandles, isSQLHandle and ambiguousLeadingKeyword (the "start" collision
 * with Fiber's c.Query("start") that produced 14 phantom sites). None of
 * that applies here: every name in the three method tables above is an
 * unambiguous Laravel facade or query-builder call - `DB::statement(` is
 * syntactically the DB class's own method, not a generic name shared with
 * something unrelated. Checked, not assumed: grepped app/ and tests/ for a
 * local class redefining whereRaw/selectRaw/havingRaw/orderByRaw/groupByRaw/
 * fromRaw/orWhereRaw/orHavingRaw, and grepped composer.json for an
 * Elasticsearch/Mongo/Scout-style client that might define a colliding
 * `raw()`/`query()` of its own - both came back empty. So this extractor
 * admits a call as a site whenever the METHOD NAME matches one of the three
 * tables, with no receiver-based gate, and no leading-keyword filter either:
 * a leading-keyword regex is still used, but only for CLASSIFICATION (is
 * DB::statement's content DDL or DML), never for ADMISSION - unlike Go's
 * leadingKeyword, which decides whether a call counts as SQL at all.
 *
 * PDO USED DIRECTLY, BYPASSING THE DB FACADE ENTIRELY
 * ------------------------------------------------------------------------
 * app/Services/EeeSqliteService.php and twelve files under
 * app/Console/Commands/Eee/ hold ~30 real SQL statements run against a
 * *separate SQLite database* (`config('freegle.eee.sqlite_path')`, an
 * experimental ML-classification store, explicitly documented in that file
 * as "Separate from MySQL... to keep the dataset portable for Python/pandas
 * analysis") via bare `\PDO::prepare()`/`::query()`/`::exec()`. Those method
 * names are not in any of the three tables above, so this extractor does not
 * flag them - correctly, by construction, since prepare/query/exec are not
 * Laravel vocabulary at all. But "not in my method list" and "does not
 * exist" are different claims, and the whole point of this tool is not
 * trusting the difference. So PDO_AUDIT_METHODS below is a SEPARATE,
 * best-effort pass that looks for prepare/query/exec calls whose argument
 * folds to something SQL-shaped, anywhere in the tree - and it is NOT
 * file-scoped to the known SQLite files. A file-level exclusion list would
 * have been wrong: app/Console/Commands/Eee/EeeBackfillClassifiedTableCommand.php
 * mixes both - it reads from the SQLite store AND writes real production
 * MySQL rows via `DB::table('eee_classified_attachments')` and
 * `DB::statement(...)` - so file-level exclusion would have silently dropped
 * genuine in-scope MySQL sites alongside the correctly-excluded SQLite ones.
 * Every PDO-audit hit is resolved per call site: if the receiver traces
 * (via a same-function, one-hop backward scan for `$var = <expr>`) to
 * something whose rendered source contains "sqlite", it is recorded with
 * status "excluded" and a reason; anything else is recorded as an ordinary
 * unresolved site (kind "PDO") rather than silently dropped, because a
 * database handle this tool cannot classify is exactly the case plan 7.1's
 * exhaustiveness promise exists for.
 *
 * "excluded" IS A NEW STATUS NOT IN extract.go's ENUM. This was a deliberate
 * instruction (not this tool's own idea): the Go side's closest precedent -
 * services/spatial/manifest.json's SQLite R-tree index sites - used
 * "keep-raw" with a reason, on the reasoning that keep-raw already means
 * "considered and excluded". That is a real, still-open inconsistency
 * between the two manifests worth reconciling later; it was not changed
 * here because doing so would mean editing services/spatial, which is out of
 * this tool's scope. "excluded" exists so a burn-down never counts a
 * different database engine's rows as outstanding ORM-migration work.
 */

require __DIR__ . '/vendor/autoload.php';

use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

// ---------------------------------------------------------------------------
// Status values a site may carry. Exactly one, always - same contract as
// extract.go's Status* constants.
// ---------------------------------------------------------------------------
const STATUS_RAW = 'raw';
const STATUS_IN_PROGRESS = 'in-progress';
const STATUS_CONVERTED = 'converted';
const STATUS_KEEP_RAW = 'keep-raw';
const STATUS_TEST_FIXTURE = 'test-fixture';
const STATUS_RETIRED = 'retired';
// Laravel-only addition - see the header comment.
const STATUS_EXCLUDED = 'excluded';

// ---------------------------------------------------------------------------
// The enumerated method surface (see header comment for how each table was
// derived). Values are unused (these are sets); PHP has no native set type.
// ---------------------------------------------------------------------------
const CONNECTION_SQL_METHODS = [
    'select' => true, 'selectOne' => true, 'scalar' => true, 'cursor' => true,
    'insert' => true, 'update' => true, 'delete' => true, 'statement' => true,
    'unprepared' => true, 'raw' => true,
];

const BUILDER_FRAGMENT_METHODS = [
    'selectRaw' => true, 'fromRaw' => true, 'whereRaw' => true, 'orWhereRaw' => true,
    'groupByRaw' => true, 'havingRaw' => true, 'orHavingRaw' => true, 'orderByRaw' => true,
];

const BLUEPRINT_DDL_METHODS = [
    'rawIndex' => true, 'rawColumn' => true,
];

// The best-effort PDO audit (see header comment). Deliberately requires an
// argument that folds to SQL-shaped text before admitting a hit, the same
// admission-vs-classification split leadingKeyword makes elsewhere - this
// keeps a call like $request->query('id') (zero args here, or a non-SQL
// first arg) from being misread as a database call.
const PDO_AUDIT_METHODS = ['prepare' => true, 'exec' => true, 'query' => true];

// Fragment methods report a Kind describing WHICH clause they contribute to,
// not a statement type - see the "surface" field.
const FRAGMENT_KIND = [
    'selectRaw' => 'SELECT-FRAGMENT', 'fromRaw' => 'FROM-FRAGMENT',
    'whereRaw' => 'WHERE-FRAGMENT', 'orWhereRaw' => 'WHERE-FRAGMENT',
    'groupByRaw' => 'GROUP-FRAGMENT', 'havingRaw' => 'HAVING-FRAGMENT',
    'orHavingRaw' => 'HAVING-FRAGMENT', 'orderByRaw' => 'ORDER-FRAGMENT',
];

// Leading-keyword verbs, used only to CLASSIFY DB::statement/DB::unprepared
// content as DDL vs DML (never to decide admission - see header comment).
// Broader than extract.go's leadingKeyword list: Go narrows this list because
// an over-broad match on an ambiguous receiver produces phantom sites; that
// risk does not exist here (every call is already an unambiguous DB::
// facade method), so there is no reason to under-recognise a real verb.
// RENAME is included deliberately - it does not appear in Go's list at all,
// and this codebase has two real `RENAME TABLE ... TO ...` sites via
// DB::statement (WhatJobsService.php, MigrateReachBoundsSchemaCommand.php)
// that a narrower list would have left mis-classified as OTHER.
const LEADING_KEYWORD_RE = '/^\s*(?:\(\s*)*(?:\/\*.*?\*\/\s*)?'
    . '(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME'
    . '|SET|SHOW|CALL|WITH|EXPLAIN|ANALYZE|OPTIMIZE|LOCK|UNLOCK|START|COMMIT'
    . '|ROLLBACK|BEGIN|GRANT|REVOKE|DESCRIBE|DESC|USE|KILL|FLUSH|CHECK|REPAIR|LOAD)\b/is';

const DDL_VERBS = ['CREATE' => true, 'ALTER' => true, 'DROP' => true, 'TRUNCATE' => true, 'RENAME' => true];
const DML_VERBS = ['INSERT' => true, 'UPDATE' => true, 'DELETE' => true, 'REPLACE' => true];

// Same dialect-feature list as extract.go's mysqlismPatterns (this is still
// MySQL syntax in a hand-written raw string, regardless of which language
// wraps it), plus nothing PHP-specific needs adding - the SQL text itself is
// the same MySQL either way.
const MYSQLISM_PATTERNS = [
    'backticks' => '/`/',
    'on-duplicate-key' => '/\bon\s+duplicate\s+key\b/i',
    'insert-ignore' => '/\binsert\s+ignore\b/i',
    'replace-into' => '/\breplace\s+into\b/i',
    'any-value' => '/\bany_value\s*\(/i',
    'date-format' => '/\bdate_format\s*\(/i',
    'timestampdiff' => '/\btimestampdiff\s*\(/i',
    'ifnull' => '/\bifnull\s*\(/i',
    'force-index' => '/\bforce\s+index\b/i',
    'straight-join' => '/\bstraight_join\b/i',
    'spatial' => '/\b(st_[a-z_]+|mbr[a-z_]*)\s*\(/i',
    'last-insert-id' => '/\blast_insert_id\s*\(/i',
    'group-concat' => '/\bgroup_concat\s*\(/i',
    'unix-timestamp' => '/\bunix_timestamp\s*\(/i',
    'limit-offset-comma' => '/\blimit\s+\?\s*,\s*\?/i',
];

const TABLE_RE = '/\b(?:from|join|into|update)\s+([`"]?[a-z_][a-z0-9_]*[`"]?)/i';
const DELETE_TABLE_RE = '/\bdelete\s+from\s+([`"]?[a-z_][a-z0-9_]*[`"]?)/i';
const JOIN_RE = '/\bjoin\b/i';
const UNION_RE = '/\bunion\b/i';
const SUBQUERY_RE = '/\(\s*select\b/i';

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------
function argOr(array $argv, string $name, string $default): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

$repoRoot = dirname(__DIR__, 3); // tools/orm-migration/php-extractor -> repo root
$root = argOr($argv, 'root', $repoRoot . '/iznik-batch');
$out = argOr($argv, 'out', $repoRoot . '/tools/orm-migration/services/laravel/manifest.json');
$repo = argOr($argv, 'repo', $repoRoot);
$rulesPath = argOr($argv, 'rules', $repoRoot . '/tools/orm-migration/services/laravel/keep-raw.json');

$root = rtrim(realpath($root) ?: $root, '/');
$repo = rtrim(realpath($repo) ?: $repo, '/');

// ---------------------------------------------------------------------------
// Site shape. Field names mirror extract.go's Site struct/JSON tags where
// the concept is shared, so burndown.mjs/completion-report.sh work against
// this manifest with only a path change - see README.md. "surface" and
// "class" have no Go equivalent; see the header comment for "surface", and
// "class" exists because PHP call sites live inside classes, not free
// functions, so the enclosing type is worth recording alongside the method.
// ---------------------------------------------------------------------------
function newSite(): array
{
    return [
        'id' => '',
        'file' => '',
        'line' => 0,
        'class' => '',
        'function' => '',
        'receiver' => '',
        'method' => '',
        'surface' => '', // statement | fragment | ddl | expression | pdo
        'sqlSource' => '', // literal | partial | variable
        'kind' => '',
        'tables' => [],
        'mysqlisms' => [],
        'complexity' => '',
        'wave' => 5,
        'dynamic' => false,
        'args' => 0,
        'goldenSql' => '',
        'presentInCode' => true,
        'hasParityTest' => false, // set from parityTestedIds() after the scan.
        'status' => STATUS_RAW,
        'reason' => '',
        'porting' => '',
        'category' => '',
        'approvedDiff' => '',
    ];
}

// ---------------------------------------------------------------------------
// File walk + classification. Path-based, same spirit as extract.go's
// isTest (`_test.go` / `/test/`) - here: tests/ is test-fixture,
// database/migrations/ is DDL/historical (handled via keep-raw.json rules,
// not hardcoded, so the reason is reviewable and can note per-file whether
// content is DDL or DML rather than assuming the directory is uniform - see
// README section on migrations).
// ---------------------------------------------------------------------------
function relPath(string $repo, string $abs): string
{
    $abs = str_starts_with($abs, '/') ? $abs : (getcwd() . '/' . $abs);
    if (str_starts_with($abs, $repo . '/')) {
        return substr($abs, strlen($repo) + 1);
    }
    return $abs;
}

function walkPhpFiles(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    $skipDirs = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap'];
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        $skip = false;
        foreach ($skipDirs as $d) {
            if (str_contains($path, "/{$d}/")) {
                $skip = true;
                break;
            }
        }
        if ($skip || !str_ends_with($path, '.php')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);
    return $files;
}

// ---------------------------------------------------------------------------
// SQL text folding. Mirrors extract.go's foldWith: reconstruct literal SQL
// from a PHP expression, marking anything unresolvable as a "{{expr}}" hole
// and the site dynamic. Deliberately does NOT attempt local-variable
// backward resolution (`$sql = "..."; ...; DB::statement($sql)`) - Go's
// extractor does not either (its *ast.Ident case only resolves PACKAGE-level
// consts, never function-local variables; a call fed a bare local variable
// is recorded sqlSource "variable" with a `{{built at runtime: expr}}`
// placeholder, and that is deliberately what this does too - see
// "isSQLHandle... The statement is built elsewhere and passed in" in
// extract.go). Consistency with that design was chosen over the extra
// resolving power, since it is what the rest of this port's readers will
// already expect.
// ---------------------------------------------------------------------------
function foldExpr(Node $e): array
{
    // Returns [text, dynamic, ok]
    if ($e instanceof Node\Scalar\String_) {
        return [$e->value, false, true];
    }
    if ($e instanceof Node\Scalar\InterpolatedString) {
        $text = '';
        $dynamic = false;
        foreach ($e->parts as $part) {
            if ($part instanceof Node\InterpolatedStringPart) {
                $text .= $part->value;
            } else {
                $text .= '{{expr}}';
                $dynamic = true;
            }
        }
        return [$text, $dynamic, true];
    }
    if ($e instanceof Node\Expr\BinaryOp\Concat) {
        [$l, $ld, $lok] = foldExpr($e->left);
        [$r, $rd, $rok] = foldExpr($e->right);
        if (!$lok) {
            $l = '{{expr}}';
            $ld = true;
        }
        if (!$rok) {
            $r = '{{expr}}';
            $rd = true;
        }
        if (!$lok && !$rok) {
            return ['', false, false];
        }
        return [$l . $r, $ld || $rd, true];
    }
    if ($e instanceof Node\Expr\ClassConstFetch && $e->name instanceof Node\Identifier) {
        // self::COLUMNS-style constants are not resolved (no cross-class
        // constant table is built here, unlike extract.go's packageConsts/
        // crossPackageConsts) - deliberately left as an unresolved hole
        // rather than a silently wrong guess. Flagged in the README as a
        // known gap worth closing if it turns out to be common; a spot
        // check while writing this extractor did not find the pattern in
        // production code, only the fold-quality risk of assuming that
        // stays true.
        return ['{{expr}}', true, true];
    }
    return ['', false, false];
}

function normaliseGolden(string $text): string
{
    $sql = preg_replace('/\s+/', ' ', trim($text));
    return rtrim(trim($sql), ';');
}

function leadingVerb(string $sql): string
{
    if (preg_match(LEADING_KEYWORD_RE, $sql, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

function classifyMysqlisms(string $sql): array
{
    $hits = [];
    foreach (MYSQLISM_PATTERNS as $name => $re) {
        if (preg_match($re, $sql)) {
            $hits[] = $name;
        }
    }
    sort($hits);
    return $hits;
}

function extractTables(string $sql): array
{
    $set = [];
    $add = function (array $matches) use (&$set) {
        foreach ($matches[1] as $t) {
            $t = trim($t, '`"');
            $u = strtoupper($t);
            if ($u === 'SELECT' || $u === 'DUAL' || $u === '') {
                continue;
            }
            $set[strtolower($t)] = true;
        }
    };
    if (preg_match_all(TABLE_RE, $sql, $m)) {
        $add($m);
    }
    if (preg_match_all(DELETE_TABLE_RE, $sql, $m)) {
        $add($m);
    }
    $out = array_keys($set);
    sort($out);
    return $out;
}

/**
 * Mirrors extract.go's classify()/wave(): derive kind/tables/mysqlisms/
 * complexity/wave from the resolved SQL text and the call's surface.
 * Fragment/DDL/expression/PDO sites are not whole statements, so they skip
 * straight to wave 5 (triage) the same way Go sends dynamic sites there -
 * there is no "wave 1 simple SELECT" for a WHERE fragment, because a
 * fragment was never a candidate for mechanical single-statement conversion
 * in the first place; the ORM call it lives inside already exists.
 */
function classify(array &$site): void
{
    $sql = $site['goldenSql'];

    if ($site['sqlSource'] === 'variable') {
        $site['kind'] = 'DYNAMIC';
        $site['complexity'] = 'complex';
        $site['wave'] = 5;
        $site['tables'] = [];
        return;
    }

    if ($site['surface'] === 'fragment') {
        $site['kind'] = FRAGMENT_KIND[$site['method']] ?? 'FRAGMENT';
        $site['mysqlisms'] = classifyMysqlisms($sql);
        $site['tables'] = extractTables($sql);
        $site['complexity'] = $site['dynamic'] ? 'complex' : 'moderate';
        $site['wave'] = 5;
        return;
    }

    if ($site['surface'] === 'pdo') {
        $site['kind'] = 'PDO';
        $site['mysqlisms'] = classifyMysqlisms($sql);
        $site['tables'] = extractTables($sql);
        $site['complexity'] = 'complex';
        $site['wave'] = 5;
        return;
    }

    if ($site['method'] === 'raw') {
        // DB::raw() wraps an expression for embedding elsewhere; it is not
        // itself a statement, and unlike whereRaw/selectRaw etc. it has no
        // fixed clause role, so it gets its own kind rather than borrowing
        // FRAGMENT_KIND.
        $site['kind'] = 'EXPRESSION';
        $site['mysqlisms'] = classifyMysqlisms($sql);
        $site['tables'] = extractTables($sql);
        $site['complexity'] = 'complex';
        $site['wave'] = 5;
        return;
    }

    // Fixed-kind Connection methods: the Laravel API contract already says
    // what these run, so no verb-sniffing is needed or wanted for them.
    $fixedKind = [
        'select' => 'SELECT', 'selectOne' => 'SELECT', 'scalar' => 'SELECT', 'cursor' => 'SELECT',
        'insert' => 'INSERT', 'update' => 'UPDATE', 'delete' => 'DELETE',
    ];
    if (isset($fixedKind[$site['method']])) {
        $site['kind'] = $fixedKind[$site['method']];
    } else {
        // statement() and unprepared() are genuinely generic - classify by
        // the content's own leading verb (this is the DDL-vs-DML split the
        // scoping report flagged as real, unavoidable triage work).
        $verb = leadingVerb($sql);
        if (isset(DDL_VERBS[$verb])) {
            $site['kind'] = 'DDL';
        } elseif (isset(DML_VERBS[$verb])) {
            $site['kind'] = $verb;
        } elseif ($verb === 'SELECT' || $verb === 'WITH') {
            $site['kind'] = 'SELECT';
        } else {
            $site['kind'] = 'OTHER';
        }
    }

    $site['mysqlisms'] = classifyMysqlisms($sql);
    $site['tables'] = extractTables($sql);

    $multiTable = count($site['tables']) > 1 || preg_match(JOIN_RE, $sql) === 1;
    $hard = $site['dynamic']
        || preg_match(UNION_RE, $sql) === 1
        || preg_match(SUBQUERY_RE, $sql) === 1;
    foreach ($site['mysqlisms'] as $m) {
        if ($m !== 'backticks') {
            $hard = true;
        }
    }

    if ($hard) {
        $site['complexity'] = 'complex';
    } elseif ($multiTable) {
        $site['complexity'] = 'moderate';
    } else {
        $site['complexity'] = 'simple';
    }

    $site['wave'] = wave($site);
}

function wave(array $site): int
{
    if ($site['complexity'] === 'complex') {
        if (in_array('replace-into', $site['mysqlisms'], true) || $site['kind'] === 'INSERT') {
            return 3;
        }
        return 5;
    }
    switch ($site['kind']) {
        case 'SELECT':
            return $site['complexity'] === 'simple' ? 1 : 4;
        case 'INSERT':
        case 'UPDATE':
        case 'DELETE':
            return $site['complexity'] === 'simple' ? 2 : 5;
        case 'REPLACE':
            return 3;
        case 'DDL':
            return 5;
    }
    return 5;
}

function stableId(string $key, int $occurrence): string
{
    return substr(hash('sha256', $key . "\x00" . $occurrence), 0, 12);
}

// Textual rendering of a receiver expression, for the "receiver" field and
// for the PDO-audit "does this trace back to sqlite" heuristic. Not a real
// pretty-printer - just enough to read in a manifest diff and to substring-
// match against, mirroring extract.go's exprString.
function exprText(Node $e): string
{
    if ($e instanceof Node\Expr\Variable && is_string($e->name)) {
        return '$' . $e->name;
    }
    if ($e instanceof Node\Expr\PropertyFetch && $e->name instanceof Node\Identifier) {
        return exprText($e->var) . '->' . $e->name->name;
    }
    if ($e instanceof Node\Expr\StaticPropertyFetch && $e->name instanceof Node\Identifier) {
        return exprText($e->class) . '::$' . $e->name->name;
    }
    if ($e instanceof Node\Name) {
        return $e->toString();
    }
    if ($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier) {
        return exprText($e->var) . '->' . $e->name->name . '()';
    }
    if ($e instanceof Node\Expr\StaticCall && $e->class instanceof Node\Name && $e->name instanceof Node\Identifier) {
        return $e->class->toString() . '::' . $e->name->name . '()';
    }
    return '?';
}

// ---------------------------------------------------------------------------
// AST walk. One visitor per file; tracks the nearest enclosing NAMED
// function/method (never a closure) as "function", matching extract.go's
// per-FuncDecl walk, which likewise lets ast.Inspect recurse through nested
// closures without giving them their own scope. This also sets the scope
// the occurrence-index (site ID disambiguator) is keyed to - see the header
// comment in generateManifest() for why that is narrower than Go's
// file-wide scope, and is a deliberate improvement, not an oversight.
// ---------------------------------------------------------------------------
final class SiteCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{class:string,function:string}> */
    private array $scopeStack = [];
    public array $sites = [];
    /** @var array<string,int> occurrence counters keyed by file\0function\0sql */
    private array $seen = [];

    public function __construct(
        private readonly string $relFile,
        private readonly bool $isTest,
    ) {
        $this->scopeStack[] = ['class' => '', 'function' => ''];
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
            $top = end($this->scopeStack);
            $this->scopeStack[] = ['class' => $node->name->toString(), 'function' => $top['function']];
            return;
        }
        if (($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) && $node->name !== null) {
            $top = end($this->scopeStack);
            $this->scopeStack[] = ['class' => $top['class'], 'function' => $node->name->toString()];
            return;
        }

        $call = null;
        if ($node instanceof Node\Expr\StaticCall) {
            $call = $node;
        } elseif ($node instanceof Node\Expr\MethodCall) {
            $call = $node;
        }
        if ($call === null || $call->name === null || !($call->name instanceof Node\Identifier)) {
            return;
        }
        $methodName = $call->name->name;
        $args = $call->getArgs();

        $surface = null;
        if ($node instanceof Node\Expr\StaticCall && isset(CONNECTION_SQL_METHODS[$methodName])) {
            // Require the class to resolve to DB (Illuminate\Support\Facades\DB)
            // so an unrelated class with a same-named static method (none
            // found in this codebase, but this is the same discipline Go
            // applies via isSQLHandle) is not swept in.
            $resolved = $node->class->getAttribute('resolvedName');
            $className = $resolved instanceof Node\Name ? $resolved->toString() : ($node->class instanceof Node\Name ? $node->class->toString() : '');
            if ($className === 'Illuminate\\Support\\Facades\\DB' || $className === 'DB') {
                $surface = $methodName === 'raw' ? 'expression' : 'statement';
            }
        } elseif ($node instanceof Node\Expr\MethodCall && isset(BUILDER_FRAGMENT_METHODS[$methodName])) {
            $surface = 'fragment';
        } elseif ($node instanceof Node\Expr\MethodCall && isset(BLUEPRINT_DDL_METHODS[$methodName])) {
            $surface = 'ddl';
        } elseif ($node instanceof Node\Expr\MethodCall && isset(PDO_AUDIT_METHODS[$methodName])) {
            $surface = 'pdo-candidate';
        }
        if ($surface === null || count($args) === 0) {
            return;
        }

        $argExpr = $args[0]->value;
        [$text, $dynamic, $ok] = foldExpr($argExpr);

        if ($surface === 'pdo-candidate') {
            // Admission for the PDO audit requires the argument to actually
            // look like SQL (see header comment) - this is the one place
            // admission and classification share a check, because
            // ->query()/->exec()/->prepare() are common enough English/API
            // verbs that an unfiltered scan would be noisy in a way
            // DB::statement() etc. never are.
            if (!$ok || $dynamic || leadingVerb($text) === '') {
                return;
            }
            $surface = 'pdo';
        }

        $site = newSite();
        $site['file'] = $this->relFile;
        $site['line'] = $node->getStartLine();
        $top = end($this->scopeStack);
        $site['class'] = $top['class'];
        $site['function'] = $top['function'] !== '' ? $top['function'] : '(top-level)';
        $site['receiver'] = $node instanceof Node\Expr\MethodCall ? exprText($node->var) : exprText($node->class);
        $site['method'] = $methodName;
        $site['surface'] = $surface;
        $site['args'] = count($args) - 1;

        if ($ok && !$dynamic) {
            $site['goldenSql'] = normaliseGolden($text);
            $site['sqlSource'] = 'literal';
            $site['dynamic'] = false;
        } elseif ($ok && $dynamic) {
            $site['goldenSql'] = normaliseGolden($text);
            $site['sqlSource'] = 'partial';
            $site['dynamic'] = true;
        } else {
            // Cannot fold at all - a bare variable, a method call result, a
            // function call. Recorded rather than dropped, same as
            // extract.go's isSQLHandle fallback branch.
            $site['goldenSql'] = '{{built at runtime: ' . exprText($argExpr) . '}}';
            $site['sqlSource'] = 'variable';
            $site['dynamic'] = true;
        }

        classify($site);

        $key = $this->relFile . "\x00" . $site['function'] . "\x00" . $site['goldenSql'];
        $occurrence = $this->seen[$key] ?? 0;
        $site['id'] = stableId($key, $occurrence);
        $this->seen[$key] = $occurrence + 1;

        $site['presentInCode'] = true;
        $site['status'] = $this->isTest ? STATUS_TEST_FIXTURE : STATUS_RAW;

        $this->sites[] = $site;
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
            array_pop($this->scopeStack);
            return;
        }
        if (($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) && $node->name !== null) {
            array_pop($this->scopeStack);
        }
    }
}

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------
/**
 * Site ids named by a Layer 1/2 parity assertion, i.e. the PHP counterpart of
 * extract.go's parityTestedIDs.
 *
 * Parsed, not grepped, and deliberately so: matching the id anywhere in a file
 * would let a COMMENT saying "site abc123 is deliberately not converted"
 * satisfy the very gate that is supposed to assert it WAS converted. Only a
 * string literal in the first argument position of one of the harness's
 * assertions counts.
 *
 * A site whose raw SQL is gone AND which such a test names is promoted to
 * converted. One that vanished with no test to vouch for it keeps its old
 * status, so "it disappeared" is never a route out of the inventory.
 */
const PARITY_ASSERTIONS = [
    'GoldenSql::assert',
    'GoldenSql::assertInsert',
    'GoldenSql::assertInsertOrIgnore',
    'GoldenSql::assertUpsert',
    'GoldenSql::assertExists',
    'GoldenSql::assertUpdate',
    'GoldenSql::assertDelete',
    'ResultParity::assertForSite',
];

/**
 * The harness classes whose assertions vouch for a site, and where they live.
 * Used by assertParityAssertionsCurrent() below.
 */
const PARITY_HARNESS_FILES = [
    'GoldenSql'    => 'iznik-batch/tests/Support/OrmHarness/GoldenSql.php',
    'ResultParity' => 'iznik-batch/tests/Support/OrmHarness/ResultParity.php',
];

/**
 * Fail if the harness has grown an assertion that takes a $siteId which
 * PARITY_ASSERTIONS does not list.
 *
 * This is the PHP counterpart of extract.go's gate (m), and it exists because
 * the failure it prevents is invisible: an unlisted assertion means every site
 * proved only through it silently reports as unproven, and the natural reading
 * of that - "the manifest is stale, I should fix up the statuses" - is wrong in
 * a way that would paper over the real bug. On the Go side that exact thing
 * happened once and 68 sites with real passing tests reported as unproven.
 *
 * Note ResultParity::assert is deliberately NOT listed: its first argument is
 * $originalSql, not a site id, so treating it as one would record SQL strings
 * as site ids. Only methods whose first parameter is $siteId qualify.
 */
function assertParityAssertionsCurrent(string $repo): void
{
    $missing = [];
    foreach (PARITY_HARNESS_FILES as $class => $rel) {
        $path = $repo . '/' . $rel;
        if (!is_file($path)) {
            continue;
        }
        $src = file_get_contents($path);
        if (!preg_match_all('/public static function ([a-zA-Z]+)\(string \$siteId/', $src, $m)) {
            continue;
        }
        foreach ($m[1] as $method) {
            $qualified = $class . '::' . $method;
            if (!in_array($qualified, PARITY_ASSERTIONS, true)) {
                $missing[] = $qualified;
            }
        }
    }
    if ($missing !== []) {
        fwrite(STDERR, "extract: PARITY_ASSERTIONS is out of date - the harness has assertion(s) it does not list:\n");
        foreach ($missing as $q) {
            fwrite(STDERR, "  {$q}\n");
        }
        fwrite(STDERR, "  Add them to PARITY_ASSERTIONS in extract.php. Until you do, every site\n");
        fwrite(STDERR, "  proved only through them reports as unproven.\n");
        exit(1);
    }
}

final class ParityTestVisitor extends NodeVisitorAbstract
{
    /** @var array<string,true> */
    public array $ids = [];

    /**
     * Class constants declared in this file whose value is a literal string,
     * so `GoldenSql::assert(self::SITE_FOO, ...)` resolves.
     *
     * This is NOT general constant resolution. It is the same deliberately
     * narrow trick extract.go uses for its forwarding helpers, and for the same
     * reason: an id the test COMPUTES is not the id written at the call site,
     * and crediting it would vouch for something the test never asserted. Only
     * a `const NAME = '<literal>';` in the same file counts.
     *
     * Without this the detector silently reports zero: the existing
     * ProvenSitesTest names every site through a self:: constant, so a
     * literals-only matcher finds nothing and the whole promotion path looks
     * like it does not work.
     *
     * @var array<string,string>
     */
    private array $constants = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $c) {
                if ($c->value instanceof Node\Scalar\String_) {
                    $this->constants[$c->name->toString()] = $c->value->value;
                }
            }
            return null;
        }

        if (!$node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return null;
        }
        // NameResolver has already expanded the class to its FQN, so compare on
        // the trailing segment - the harness is always imported by short name.
        $parts = $node->class->getParts();
        $short = end($parts) . '::' . $node->name->toString();
        if (!in_array($short, PARITY_ASSERTIONS, true)) {
            return null;
        }

        $first = $node->args[0]->value ?? null;
        if ($first instanceof Node\Scalar\String_) {
            $this->ids[$first->value] = true;
            return null;
        }
        if ($first instanceof Node\Expr\ClassConstFetch && $first->name instanceof Node\Identifier) {
            $name = $first->name->toString();
            if (isset($this->constants[$name])) {
                $this->ids[$this->constants[$name]] = true;
            }
        }
        return null;
    }
}

/**
 * @return array<string,true> site ids a parity test names
 */
function parityTestedIds(string $root): array
{
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $ids = [];
    foreach (walkPhpFiles($root) as $path) {
        if (!str_contains($path, '/tests/')) {
            continue;
        }
        $src = file_get_contents($path);
        // Cheap reject before paying for a parse.
        $interesting = false;
        foreach (PARITY_ASSERTIONS as $a) {
            if (str_contains($src, explode('::', $a)[0] . '::')) {
                $interesting = true;
                break;
            }
        }
        if (!$interesting) {
            continue;
        }
        try {
            $ast = $parser->parse($src);
        } catch (ParseError $e) {
            fwrite(STDERR, "extract: parse {$path}: {$e->getMessage()}\n");
            exit(1);
        }
        if ($ast === null) {
            continue;
        }
        $t = new NodeTraverser();
        $t->addVisitor(new NameResolver());
        $v = new ParityTestVisitor();
        $t->addVisitor($v);
        $t->traverse($ast);
        foreach ($v->ids as $id => $_) {
            $ids[$id] = true;
        }
    }
    return $ids;
}

/**
 * Apply reviewer-approved divergences from services/laravel/approved-diffs.json.
 *
 * Declarative, like keep-raw.json and like the Go side's approved-diffs.json, so
 * the decision survives regeneration and is reviewed as a diff rather than
 * hand-edited into a 1,151-entry manifest where nobody would find it.
 */
function applyApprovedDiffs(array &$sites, string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $decoded = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $diffs = [];
    foreach ($decoded['diffs'] ?? [] as $i => $d) {
        if (trim($d['id'] ?? '') === '' || trim($d['sql'] ?? '') === '') {
            fwrite(STDERR, "extract: {$path}: diff {$i} needs both an id and a sql\n");
            exit(1);
        }
        if (trim($d['reason'] ?? '') === '') {
            fwrite(STDERR, "extract: {$path}: diff {$i} ({$d['id']}) has no reason; an approved diff asserts two statements are equivalent and that has to be justified\n");
            exit(1);
        }
        $diffs[$d['id']] = $d['sql'];
    }

    $applied = 0;
    $seen = [];
    foreach ($sites as &$s) {
        if (isset($diffs[$s['id']])) {
            $s['approvedDiff'] = $diffs[$s['id']];
            $seen[$s['id']] = true;
            $applied++;
        }
    }
    unset($s);

    foreach ($diffs as $id => $_) {
        if (!isset($seen[$id])) {
            fwrite(STDERR, "warning: approved diff for site {$id} matched no site - it may have been renumbered\n");
        }
    }
    return $applied;
}

function scan(string $root, string $repo): array
{
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $sites = [];

    foreach (walkPhpFiles($root) as $path) {
        $rel = relPath($repo, $path);
        $isTest = str_starts_with($rel, 'iznik-batch/tests/');

        $src = file_get_contents($path);
        try {
            $ast = $parser->parse($src);
        } catch (ParseError $e) {
            // Fail loudly rather than silently under-reporting - the same
            // choice extract.go makes (sitesInFile returns an error rather
            // than skipping a file it cannot parse).
            fwrite(STDERR, "extract: parse {$rel}: {$e->getMessage()}\n");
            exit(1);
        }
        if ($ast === null) {
            continue;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $collector = new SiteCollectorVisitor($rel, $isTest);
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        foreach ($collector->sites as $s) {
            $sites[] = $s;
        }
    }

    return $sites;
}

// ---------------------------------------------------------------------------
// keep-raw.json rules - same shape and same two-required-fields contract as
// extract.go's applyKeepRaw (id/file/function match, trailing "/" on file
// means directory, reason AND porting both required non-empty).
// ---------------------------------------------------------------------------
function applyKeepRaw(array &$sites, string $path): int
{
    if (!file_exists($path)) {
        return 0;
    }
    $decoded = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $rules = $decoded['rules'] ?? [];

    foreach ($rules as $i => $r) {
        if (trim($r['reason'] ?? '') === '') {
            fwrite(STDERR, "extract: {$path}: rule {$i} has no reason; deferral must always carry a justification\n");
            exit(1);
        }
        if (trim($r['porting'] ?? '') === '') {
            fwrite(STDERR, "extract: {$path}: rule {$i} has no porting note\n");
            exit(1);
        }
    }

    $applied = 0;
    $hits = array_fill(0, count($rules), 0);
    foreach ($sites as &$s) {
        if ($s['status'] === STATUS_CONVERTED || $s['status'] === STATUS_TEST_FIXTURE) {
            continue;
        }
        foreach ($rules as $ri => $r) {
            if (!empty($r['id'])) {
                if ($s['id'] !== $r['id']) {
                    continue;
                }
            } else {
                $file = $r['file'] ?? '';
                // A rule with no file is a construct rule: it selects purely on
                // surface + sqlMatches, wherever the construct appears.
                if ($file !== '') {
                    $matchFile = $s['file'] === $file
                        || (str_ends_with($file, '/') && str_starts_with($s['file'], $file));
                    if (!$matchFile) {
                        continue;
                    }
                } elseif (empty($r['sqlMatches'])) {
                    fwrite(STDERR, "extract: keep-raw rule {$ri} has neither id, file nor sqlMatches - it would match everything\n");
                    exit(1);
                }
                if (!empty($r['function']) && $s['function'] !== $r['function']) {
                    continue;
                }
                // Optional "surface" filter, used for the Eee/SQLite cluster:
                // app/Console/Commands/Eee/ genuinely mixes excluded SQLite
                // reads with real, in-scope MySQL writes (see
                // EeeBackfillClassifiedTableCommand.php, which does both), so
                // a directory rule with no surface filter would incorrectly
                // exclude real MySQL sites alongside the SQLite ones. This
                // narrows a directory/file rule to only the PDO-surface
                // sites within it, leaving DB::/builder-fragment sites in
                // the same file or directory to normal triage.
                if (!empty($r['surface']) && $s['surface'] !== $r['surface']) {
                    continue;
                }
                // Optional "sqlMatches" regex over the recorded golden SQL.
                // Needed for the fragment/expression surfaces, where the reason
                // to stay raw is a property of the SQL CONSTRUCT (a JSON_EXTRACT
                // predicate, an aliased aggregate, a spatial function) rather
                // than of the file it appears in. One argued rule per construct
                // beats two hundred near-identical paragraphs keyed by id, and
                // unlike a blanket surface rule it cannot silently absorb the
                // fragments that ARE convertible (whereColumn, plain
                // comparisons, whereNotExists) - those keep failing triage
                // until someone converts them.
                if (!empty($r['sqlMatches']) && !preg_match($r['sqlMatches'], (string) $s['goldenSql'])) {
                    continue;
                }
            }
            $s['status'] = $r['status'] ?? STATUS_KEEP_RAW;
            $s['reason'] = $r['reason'];
            $s['porting'] = $r['porting'];
            $s['category'] = $r['category'] ?? '';
            $applied++;
            $hits[$ri]++;
            break;
        }
    }
    unset($s);

    foreach ($rules as $i => $r) {
        if ($hits[$i] === 0) {
            $label = !empty($r['id']) ? "site {$r['id']}" : (($r['file'] ?? '') . ' ' . ($r['function'] ?? ''));
            fwrite(STDERR, "warning: keep-raw rule {$i} matched no sites: {$label}\n");
        }
    }
    return $applied;
}

// ---------------------------------------------------------------------------
// Merge-forward. Mirrors extract.go's merge(): only human-owned fields
// survive regeneration (status limited to the set a human can have set,
// reason/porting/category/approvedDiff). The top-level "ratchet" key is
// carried through as a raw, untyped value on purpose - extract.go's own
// history is the reason: "the struct had no field for it, so every run
// silently dropped whatever was set... A ratchet that re-derives its own
// limit from the thing it is limiting is not a ratchet." This never
// constructs the output manifest by re-typing the input one; it always
// starts from the previous decoded array (if any) and only overwrites
// "sites"/"counts"/"generated".
// ---------------------------------------------------------------------------
function mergeForward(array $sites, ?array $prevManifest): array
{
    if ($prevManifest === null) {
        return $sites;
    }
    $prevSites = $prevManifest['sites'] ?? [];

    $carryableStatuses = [STATUS_CONVERTED, STATUS_IN_PROGRESS, STATUS_KEEP_RAW, STATUS_RETIRED, STATUS_EXCLUDED];
    foreach ($sites as &$s) {
        $old = $prevSites[$s['id']] ?? null;
        if ($old === null) {
            continue;
        }
        if (in_array($old['status'] ?? '', $carryableStatuses, true)) {
            $s['status'] = $old['status'];
        }
        $s['reason'] = $old['reason'] ?? '';
        $s['porting'] = $old['porting'] ?? '';
        $s['category'] = $old['category'] ?? '';
        $s['approvedDiff'] = $old['approvedDiff'] ?? '';
    }
    unset($s);

    $seenIds = [];
    foreach ($sites as $s) {
        $seenIds[$s['id']] = true;
    }
    foreach ($prevSites as $id => $old) {
        if (isset($seenIds[$id]) || ($old['status'] ?? '') === STATUS_TEST_FIXTURE) {
            continue;
        }
        $gone = $old;
        $gone['presentInCode'] = false;
        $sites[] = $gone;
    }

    return $sites;
}

/**
 * Stamp hasParityTest, and promote a site whose raw SQL is gone AND which a
 * parity test names to "converted".
 *
 * Mirrors extract.go. The promotion is deliberately narrow: absence alone never
 * promotes, because a deleted call site and a converted one look identical to
 * an extractor, and "it vanished" must not be a route out of the inventory. A
 * site that disappears unproven keeps whatever status it had, so it stays
 * visible as raw and unaccounted for.
 *
 * @param array<string,true> $tested
 */
function applyParityTests(array &$sites, array $tested): int
{
    $promoted = 0;
    foreach ($sites as &$s) {
        $s['hasParityTest'] = isset($tested[$s['id']]);
        if (!$s['hasParityTest'] || $s['presentInCode']) {
            continue;
        }
        if (in_array($s['status'], [STATUS_RAW, STATUS_IN_PROGRESS], true)) {
            $s['status'] = STATUS_CONVERTED;
            $promoted++;
        }
    }
    unset($s);
    return $promoted;
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
fwrite(STDERR, "extract: scanning {$root}\n");
$sites = scan($root, $repo);

$prevManifest = null;
if (file_exists($out)) {
    $prevManifest = json_decode(file_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
}
$sites = mergeForward($sites, $prevManifest);

assertParityAssertionsCurrent($repo);
$tested = parityTestedIds($root);
$promoted = applyParityTests($sites, $tested);

$applied = applyKeepRaw($sites, $rulesPath);
$diffsPath = dirname($out) . '/approved-diffs.json';
$approvedDiffs = applyApprovedDiffs($sites, $diffsPath);

usort($sites, function ($a, $b) {
    return $a['file'] <=> $b['file'] ?: $a['line'] <=> $b['line'];
});

$counts = [];
$sitesById = [];
foreach ($sites as $s) {
    $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;
    $sitesById[$s['id']] = $s;
}

$manifest = [
    'generated' => gmdate('Y-m-d\TH:i:s\Z'),
    'root' => relPath($repo, $root),
    'counts' => $counts,
];
// Preserve the ratchet key verbatim if the previous manifest had one -
// see the mergeForward doc comment for why this must never be reconstructed
// from a typed field that might not exist yet.
if ($prevManifest !== null && array_key_exists('ratchet', $prevManifest)) {
    $manifest['ratchet'] = $prevManifest['ratchet'];
}
$manifest['sites'] = $sitesById;

$outDir = dirname($out);
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents(
    $out,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

fwrite(STDERR, sprintf(
    "%d sites written to %s (%d keep-raw/excluded by rule, %d promoted to converted by a parity test, %d approved diffs)\n",
    count($sites),
    $out,
    $applied,
    $promoted,
    $approvedDiffs
));
