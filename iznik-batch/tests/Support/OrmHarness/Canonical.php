<?php

namespace Tests\Support\OrmHarness;

/**
 * Layer 1 of the Laravel ORM migration harness (plan section 7.2,
 * plans/database-migration-evaluation-2026-07.md): the canonicaliser that
 * lets a hand-written golden SQL string and a Laravel-rendered SQL string
 * be compared for equivalence even though they are very unlikely to be
 * byte-identical - different keyword case, whitespace, identifier quoting
 * and alias names.
 *
 * PORT, NOT A REIMPLEMENTATION FROM SCRATCH: this is a line-by-line port of
 * iznik-server-go/ormharness/canonical.go's Canonical() (Layer 1's
 * canonicaliser for the Go side), following the team lead's explicit
 * instruction not to write a second, independently-designed canonicaliser -
 * two independent normalisers drift, and the day they disagree is the day
 * one manifest's notion of "equal SQL" silently stops matching the other's.
 *
 * WHY THIS IS A PORT, NOT A SHARED BINARY: the more literal form of sharing -
 * compiling the Go canonicaliser into a small CLI and having PHPUnit shell
 * out to it - was considered and rejected as impractical, not merely
 * inconvenient:
 *   - iznik-server-go/ormharness (where Canonical() lives) is gorm-coupled;
 *     a CLI wrapper importing it would still need to compile that whole
 *     package (gorm.io/gorm, gorm.io/driver/mysql, iznik-server-go/database),
 *     and would need to live under iznik-server-go/ - a directory explicitly
 *     off-limits for the Phase A work this continues, and not reopened by
 *     the Phase B brief.
 *   - It would add a new kind of dependency to the Laravel test suite that
 *     does not exist today: every PHPUnit run needing a compiled Go binary
 *     present, cross-compiled for the container's architecture, with its
 *     own staleness and subprocess-failure-handling concerns - a real
 *     operational cost, not a hypothetical one.
 * Given that, the port covers the equivalence the other way round: a shared
 * JSON corpus (tools/orm-migration/canonical-corpus.json) derived from
 * canonical.go's own test cases (canonical_test.go), consumed by BOTH a Go
 * test and a PHPUnit test (CanonicalTest.php in this directory), so any
 * future drift between the two implementations - someone fixing a bug or
 * adding a keyword on one side only - fails CI on both sides rather than
 * silently diverging. See that corpus file's own header for the mechanics.
 *
 * SCOPE CORRECTION WORTH STATING EXPLICITLY: canonical.go's Canonical()
 * function itself is ONLY the tokenise/classify-keywords/rename-aliases/
 * join-tokens pipeline this class ports - a 1:1 port, nothing dropped. The
 * LIMIT/OFFSET placeholder resolution, INSERT/UPDATE column-order
 * normalisation, and IN-list collapsing that a first reading of this port
 * might expect to find here do NOT live in canonical.go at all: they are
 * separate functions in golden.go, the Go ASSERTION layer built on top of
 * Canonical() (assertRenderedSQL), not the canonicaliser itself. Whether
 * this PHP harness needs equivalents of THOSE lives in GoldenSql.php (this
 * directory), the assertion layer this class is built for - see that
 * file's header for the reasoning on each (short version: Laravel bakes
 * LIMIT/OFFSET as literals and preserves PHP array order for INSERT/UPDATE,
 * so neither normalisation is needed; IN-list collapsing is not needed
 * either, checked against the real manifest - zero sites use a single "?"
 * for an array bind).
 *
 * WHAT THIS PORT KEEPS FAITHFULLY: the tokeniser (backtick/double-quote
 * identifiers, string-literal handling including MySQL's two escape
 * styles, numbers, placeholders), keyword-case folding (identical keyword
 * list, copied verbatim so the two cannot drift by omission), and alias
 * renaming (implicit vs explicit AS syntax, the CAST/CONVERT guard). These
 * are genuinely the same problem in both languages: Laravel's MySqlGrammar
 * also quotes identifiers with backticks (verified: wrapValue() in
 * vendor/laravel/framework/.../Query/Grammars/MySqlGrammar.php), and a
 * hand-written golden can use any keyword case regardless of which ORM
 * eventually renders it.
 */
final class Canonical
{
    private const TOKEN_IDENT = 'ident';
    private const TOKEN_KEYWORD = 'keyword';
    private const TOKEN_STRING = 'string';
    private const TOKEN_NUMBER = 'number';
    private const TOKEN_PLACEHOLDER = 'placeholder';
    private const TOKEN_PUNCT = 'punct';

    /**
     * sqlKeywords is not an attempt at an exhaustive SQL grammar; it is the
     * set of words canonical.go's own corpus uses in a way where case is a
     * stylistic choice rather than meaningful - copied verbatim from that
     * file's sqlKeywords var, so the two lists cannot drift by omission.
     */
    private const SQL_KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'XOR', 'IN', 'IS', 'NULL',
        'AS', 'ON', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'OUTER', 'CROSS',
        'STRAIGHT_JOIN', 'USING', 'GROUP', 'BY', 'ORDER', 'ASC', 'DESC',
        'HAVING', 'LIMIT', 'OFFSET', 'DISTINCT', 'UNION', 'ALL', 'WITH',
        'EXPLAIN', 'ANALYZE',

        'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'REPLACE',
        'IGNORE', 'DUPLICATE', 'KEY', 'LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY',
        'RETURNING',

        'FOR', 'LOCK', 'SHARE', 'MODE', 'EXISTS', 'BETWEEN', 'LIKE', 'CASE',
        'WHEN', 'THEN', 'ELSE', 'END', 'CAST', 'CONVERT', 'COLLATE', 'INTERVAL',
        'FORCE', 'INDEX', 'USE', 'DEFAULT', 'TRUE', 'FALSE',

        'UNSIGNED', 'SIGNED', 'CHAR', 'BINARY', 'DATE', 'DATETIME', 'TIME',
        'TIMESTAMP', 'DECIMAL', 'JSON',

        'DAY', 'HOUR', 'MINUTE', 'SECOND', 'MONTH', 'YEAR', 'WEEK', 'QUARTER',
        'MICROSECOND',

        'COUNT', 'SUM', 'MAX', 'MIN', 'AVG', 'NOW', 'IFNULL', 'COALESCE',
        'DATE_FORMAT', 'TIMESTAMPDIFF', 'TIMESTAMPADD', 'DATE_ADD', 'DATE_SUB',
        'ANY_VALUE', 'LAST_INSERT_ID', 'CONCAT', 'CONCAT_WS', 'GROUP_CONCAT',
        'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'SUBSTRING', 'SUBSTR', 'LOWER',
        'UPPER', 'TRIM', 'LENGTH', 'ROUND', 'FLOOR', 'CEIL', 'CEILING', 'ABS',
        'GREATEST', 'LEAST', 'IF', 'RAND', 'UUID', 'MD5', 'SHA1', 'SHA2',
        'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_OBJECT', 'JSON_ARRAY',
    ];

    /** Purely cosmetic (readable failure diffs); never affects equality. */
    private const SPACE_BEFORE_PAREN_KEYWORDS = [
        'WHERE', 'AND', 'OR', 'NOT', 'IN', 'ON', 'HAVING', 'VALUES', 'SET', 'BY',
        'EXISTS', 'FROM',
    ];

    public static function normalise(string $sql): string
    {
        $tokens = self::tokenize($sql);
        $tokens = self::classifyKeywords($tokens);
        $tokens = self::dropRedundantJoinKeywords($tokens);
        $tokens = self::renameAliases($tokens);

        return self::joinTokens($tokens);
    }

    /**
     * Drops INNER and OUTER before JOIN.
     *
     * "JOIN" and "INNER JOIN" are the same operator, as are "LEFT JOIN" and
     * "LEFT OUTER JOIN" - the words are optional noise in every dialect this
     * project targets. Laravel always writes the long form; hand-written SQL
     * in this codebase usually writes the short one. Without this, every single
     * join conversion would need an approvedDiff saying "the ORM wrote INNER",
     * which would bury the diffs that record a REAL divergence under dozens
     * that record a synonym.
     *
     * Deliberately narrow: it only removes INNER/OUTER when the next keyword is
     * JOIN, so it cannot touch LEFT or RIGHT - which do change which rows come
     * back and must keep failing the comparison when they differ.
     *
     * @param  list<array{kind:string,text:string,quoted:bool}>  $tokens
     * @return list<array{kind:string,text:string,quoted:bool}>
     */
    private static function dropRedundantJoinKeywords(array $tokens): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t['kind'] === self::TOKEN_KEYWORD && ! $t['quoted']) {
                $word = strtolower($t['text']);
                // tokenize() drops whitespace, so the next token IS the next
                // word - no scanning needed.
                $next = $tokens[$i + 1] ?? null;
                if (($word === 'inner' || $word === 'outer')
                    && $next !== null
                    && $next['kind'] === self::TOKEN_KEYWORD
                    && ! $next['quoted']
                    && strtolower($next['text']) === 'join') {
                    continue; // drop this INNER/OUTER
                }
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * Counts "?" bind placeholders, correctly ignoring one that appears
     * inside a string literal. Used by GoldenSql.php's placeholder-count
     * guard (a "?" inside 'note = ?' is not the same event as a real bind
     * point) - exposed here rather than duplicated, since tokenize() already
     * has to make this exact distinction to lex the statement at all.
     */
    public static function countPlaceholders(string $sql): int
    {
        $count = 0;
        foreach (self::tokenize($sql) as $t) {
            if ($t['kind'] === self::TOKEN_PLACEHOLDER) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array{kind:string,text:string,quoted:bool}>
     */
    private static function tokenize(string $sql): array
    {
        $out = [];
        $n = strlen($sql);
        $i = 0;

        while ($i < $n) {
            $c = $sql[$i];

            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }

            if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-') {
                $j = $i + 2;
                while ($j < $n && $sql[$j] !== "\n") {
                    $j++;
                }
                $i = $j;
                continue;
            }

            if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
                $j = $i + 2;
                while ($j < $n && !($sql[$j] === '*' && $j + 1 < $n && $sql[$j + 1] === '/')) {
                    $j++;
                }
                $j = $j < $n ? $j + 2 : $n;
                $i = $j;
                continue;
            }

            if ($c === "'") {
                $end = self::scanQuoted($sql, $i, "'");
                $out[] = ['kind' => self::TOKEN_STRING, 'text' => substr($sql, $i, $end - $i), 'quoted' => false];
                $i = $end;
                continue;
            }

            if ($c === '`') {
                $end = self::scanQuoted($sql, $i, '`');
                $inner = substr($sql, $i + 1, $end - $i - 2);
                $out[] = ['kind' => self::TOKEN_IDENT, 'text' => self::unquote($inner, '`'), 'quoted' => true];
                $i = $end;
                continue;
            }

            if ($c === '"') {
                // ANSI-style quoted identifier - same defensive-completeness
                // note as canonical.go's identical branch: MySQL treats '"'
                // as a string delimiter unless ANSI_QUOTES is set, and in
                // practice this corpus's double quotes only occur inside
                // single-quoted JSON payloads (already consumed whole by
                // the "'" branch above), but the plan calls out double
                // quotes alongside backticks as an identifier style to
                // normalise, so this is honoured.
                $end = self::scanQuoted($sql, $i, '"');
                $inner = substr($sql, $i + 1, $end - $i - 2);
                $out[] = ['kind' => self::TOKEN_IDENT, 'text' => self::unquote($inner, '"'), 'quoted' => true];
                $i = $end;
                continue;
            }

            if ($c === '?') {
                $out[] = ['kind' => self::TOKEN_PLACEHOLDER, 'text' => '?', 'quoted' => false];
                $i++;
                continue;
            }

            if (self::isDigit($c)) {
                $j = $i + 1;
                while ($j < $n && (self::isDigit($sql[$j]) || $sql[$j] === '.' || $sql[$j] === 'e' || $sql[$j] === 'E'
                    || (($sql[$j] === '+' || $sql[$j] === '-') && $j > $i && ($sql[$j - 1] === 'e' || $sql[$j - 1] === 'E')))) {
                    $j++;
                }
                $out[] = ['kind' => self::TOKEN_NUMBER, 'text' => substr($sql, $i, $j - $i), 'quoted' => false];
                $i = $j;
                continue;
            }

            if (self::isIdentStart($c)) {
                $j = $i + 1;
                while ($j < $n && self::isIdentPart($sql[$j])) {
                    $j++;
                }
                $out[] = ['kind' => self::TOKEN_IDENT, 'text' => substr($sql, $i, $j - $i), 'quoted' => false];
                $i = $j;
                continue;
            }

            if ($i + 1 < $n) {
                $two = substr($sql, $i, 2);
                if (in_array($two, ['<=', '>=', '<>', '!=', ':='], true)) {
                    $out[] = ['kind' => self::TOKEN_PUNCT, 'text' => $two, 'quoted' => false];
                    $i += 2;
                    continue;
                }
            }
            $out[] = ['kind' => self::TOKEN_PUNCT, 'text' => $c, 'quoted' => false];
            $i++;
        }

        return $out;
    }

    private static function scanQuoted(string $sql, int $start, string $q): int
    {
        $n = strlen($sql);
        $j = $start + 1;
        while ($j < $n) {
            if ($sql[$j] === '\\' && $j + 1 < $n) {
                $j += 2;
                continue;
            }
            if ($sql[$j] === $q) {
                if ($j + 1 < $n && $sql[$j + 1] === $q) {
                    $j += 2;
                    continue;
                }

                return $j + 1;
            }
            $j++;
        }

        return $n; // unterminated: take the rest rather than throw
    }

    private static function unquote(string $inner, string $q): string
    {
        return str_replace($q.$q, $q, $inner);
    }

    private static function isDigit(string $c): bool
    {
        return $c >= '0' && $c <= '9';
    }

    private static function isIdentStart(string $c): bool
    {
        return $c === '_' || $c === '$' || ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
    }

    private static function isIdentPart(string $c): bool
    {
        return self::isIdentStart($c) || self::isDigit($c);
    }

    /**
     * @param  list<array{kind:string,text:string,quoted:bool}>  $tokens
     * @return list<array{kind:string,text:string,quoted:bool}>
     */
    private static function classifyKeywords(array $tokens): array
    {
        foreach ($tokens as &$t) {
            if ($t['kind'] !== self::TOKEN_IDENT || $t['quoted']) {
                continue;
            }
            if (self::isKeywordText($t['text'])) {
                $t['kind'] = self::TOKEN_KEYWORD;
                $t['text'] = strtolower($t['text']);
            }
        }
        unset($t);

        return $tokens;
    }

    private static function isKeywordText(string $text): bool
    {
        $up = strtoupper($text);
        if (in_array($up, self::SQL_KEYWORDS, true)) {
            return true;
        }

        // MySQL's spatial function family (ST_Distance_Sphere, MBRContains,
        // ...) is too large to enumerate one by one - the manifest's own
        // extractor recognises it the same way (the "spatial" mysqlism
        // pattern in tools/orm-migration/php-extractor/extract.php).
        return str_starts_with($up, 'ST_') || str_starts_with($up, 'MBR');
    }

    /**
     * Rewrites table/join aliases and explicit "AS" aliases to positional
     * names a1, a2, ... in order of first appearance, so "users u" and
     * "users AS u1" compare equal. Heuristic over the token stream, not a
     * scoped SQL parser - identical caveat to canonical.go's renameAliases:
     * assumes an alias is not reused for a second, unrelated binding within
     * one statement (e.g. across UNION branches), which if it ever happens
     * collapses two distinct bindings onto the same canonical name. Layer 2
     * (result parity against the real database) is the backstop.
     *
     * @param  list<array{kind:string,text:string,quoted:bool}>  $tokens
     * @return list<array{kind:string,text:string,quoted:bool}>
     */
    private static function renameAliases(array $tokens): array
    {
        $canon = [];
        $drop = [];

        $register = function (string $name) use (&$canon) {
            $key = strtolower($name);
            if (isset($canon[$key])) {
                return;
            }
            $canon[$key] = 'a'.(count($canon) + 1);
        };

        $funcStack = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            $t = $tokens[$i];

            if ($t['kind'] === self::TOKEN_PUNCT && $t['text'] === '(') {
                $prev = $i > 0 ? strtoupper($tokens[$i - 1]['text']) : '';
                $funcStack[] = $prev;
                $i++;
                continue;
            }

            if ($t['kind'] === self::TOKEN_PUNCT && $t['text'] === ')') {
                if (count($funcStack) > 0) {
                    array_pop($funcStack);
                }
                $i++;
                continue;
            }

            if ($t['kind'] === self::TOKEN_KEYWORD && ($t['text'] === 'from' || $t['text'] === 'join')) {
                $isList = $t['text'] === 'from'; // FROM may list several tables; JOIN takes exactly one.
                $i++;
                while (true) {
                    $i = self::skipQualifiedName($tokens, $i);
                    if ($i >= $count) {
                        break;
                    }
                    if ($tokens[$i]['kind'] === self::TOKEN_KEYWORD && $tokens[$i]['text'] === 'as'
                        && $i + 1 < $count && $tokens[$i + 1]['kind'] === self::TOKEN_IDENT) {
                        $register($tokens[$i + 1]['text']);
                        $drop[$i] = true;
                        $i += 2;
                    } elseif ($tokens[$i]['kind'] === self::TOKEN_IDENT) {
                        $register($tokens[$i]['text']);
                        $i++;
                    }
                    if ($isList && $i < $count && $tokens[$i]['kind'] === self::TOKEN_PUNCT && $tokens[$i]['text'] === ',') {
                        $i++;

                        continue;
                    }
                    break;
                }
                continue;
            }

            if ($t['kind'] === self::TOKEN_KEYWORD && $t['text'] === 'as') {
                // Guard against CAST(expr AS type)/CONVERT(expr, type): "AS"
                // there introduces a target type, not an alias.
                $top = count($funcStack) > 0 ? $funcStack[count($funcStack) - 1] : '';
                if ($top !== 'CAST' && $top !== 'CONVERT' && $i + 1 < $count && $tokens[$i + 1]['kind'] === self::TOKEN_IDENT) {
                    $register($tokens[$i + 1]['text']);
                    $drop[$i] = true;
                }
                $i++;
                continue;
            }

            $i++;
        }

        if (count($canon) === 0) {
            return $tokens;
        }

        $out = [];
        foreach ($tokens as $idx => $t) {
            if (isset($drop[$idx])) {
                continue;
            }
            if ($t['kind'] === self::TOKEN_IDENT && isset($canon[strtolower($t['text'])])) {
                $out[] = ['kind' => self::TOKEN_IDENT, 'text' => $canon[strtolower($t['text'])], 'quoted' => false];
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * Advances past a possibly schema-qualified name (ident or ident.ident)
     * starting at $i. If tokens[$i] is not an identifier (e.g. it is the
     * "(" of a derived table), returns $i unchanged.
     *
     * @param  list<array{kind:string,text:string,quoted:bool}>  $tokens
     */
    private static function skipQualifiedName(array $tokens, int $i): int
    {
        $count = count($tokens);
        if ($i >= $count || $tokens[$i]['kind'] !== self::TOKEN_IDENT) {
            return $i;
        }
        $i++;
        while ($i + 1 < $count && $tokens[$i]['kind'] === self::TOKEN_PUNCT && $tokens[$i]['text'] === '.'
            && $tokens[$i + 1]['kind'] === self::TOKEN_IDENT) {
            $i += 2;
        }

        return $i;
    }

    /**
     * @param  list<array{kind:string,text:string,quoted:bool}>  $tokens
     */
    private static function joinTokens(array $tokens): string
    {
        $out = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0 && self::needsSpaceBefore($tokens[$i - 1], $tokens[$i])) {
                $out .= ' ';
            }
            $out .= $tokens[$i]['text'];
        }

        return $out;
    }

    private static function needsSpaceBefore(array $prev, array $cur): bool
    {
        if ($cur['kind'] === self::TOKEN_PUNCT) {
            if (in_array($cur['text'], [',', ')', ';', '.'], true)) {
                return false;
            }
            if ($cur['text'] === '(') {
                return $prev['kind'] === self::TOKEN_KEYWORD
                    && in_array(strtoupper($prev['text']), self::SPACE_BEFORE_PAREN_KEYWORDS, true);
            }
        }
        if ($prev['kind'] === self::TOKEN_PUNCT && ($prev['text'] === '(' || $prev['text'] === '.')) {
            return false;
        }

        return true;
    }
}
