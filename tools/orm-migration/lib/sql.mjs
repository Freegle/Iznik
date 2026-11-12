// Text analysis shared by the Go and PHP scanners: normalising the SQL that was
// folded out of source, and working out what a statement is made of.

export const STATUSES = [
  'raw',
  'in-progress',
  'converted',
  'keep-raw',
  'test-fixture',
  'excluded',
  'retired',
];

// Collapse whitespace and drop a trailing statement separator. A semicolon is
// not part of the statement, so it must not make two renderings differ.
export function normaliseSql(text) {
  return text.replace(/\s+/g, ' ').trim().replace(/;+\s*$/, '').trim();
}

// The first word of a statement. The leading "(" is not cosmetic: unions of
// parenthesised subqueries start with one.
export const LEADING_KEYWORD =
  /^[\s(]*(?:\/\*[\s\S]*?\*\/\s*)?(select|insert|update|delete|replace|create|alter|drop|truncate|set|show|call|with|explain|analyze|optimize|lock|unlock|start|commit|rollback|begin)\b/i;

// Verbs that are also ordinary words or API vocabulary. For these a leading
// keyword alone is not evidence that a call is SQL (a web framework's
// c.Query("start") reads a URL parameter), so the receiver has to look like a
// database handle too.
export const AMBIGUOUS_LEADING_KEYWORD =
  /^[\s(]*(?:\/\*[\s\S]*?\*\/\s*)?(?:start|set|show|call|with|begin|commit|rollback|lock|unlock|analyze|optimize|explain)\b/i;

// Dialect features that have no PostgreSQL spelling, or a different one.
// Backticks alone are a quoting detail and are recorded but do not make a
// site complex.
export const MYSQLISMS = [
  ['backticks', /`/],
  ['on-duplicate-key', /\bon\s+duplicate\s+key\b/i],
  ['insert-ignore', /\binsert\s+ignore\b/i],
  ['update-ignore', /\bupdate\s+ignore\b/i],
  ['replace-into', /\breplace\s+into\b/i],
  ['any-value', /\bany_value\s*\(/i],
  ['date-format', /\bdate_format\s*\(/i],
  ['timestampdiff', /\btimestampdiff\s*\(/i],
  ['datediff', /\bdatediff\s*\(/i],
  ['date-add-sub', /\b(?:date_add|date_sub|adddate|subdate)\s*\(/i],
  ['interval-unit', /\binterval\s+(?:\?|\d+|[a-z_.]+)\s+(?:second|minute|hour|day|week|month|year)s?\b/i],
  ['ifnull', /\bifnull\s*\(/i],
  ['force-index', /\b(?:force|use|ignore)\s+index\b/i],
  ['straight-join', /\bstraight_join\b/i],
  ['spatial', /\b(?:st_[a-z_0-9]+|mbr[a-z_]*|geomfromtext|astext|point|linestring|polygon)\s*\(/i],
  ['last-insert-id', /\blast_insert_id\s*\(/i],
  ['group-concat', /\bgroup_concat\s*\(/i],
  ['unix-timestamp', /\b(?:unix_timestamp|from_unixtime)\s*\(/i],
  ['limit-offset-comma', /\blimit\s+(?:\?|\d+)\s*,\s*(?:\?|\d+)/i],
  ['json-functions', /\bjson_(?:extract|unquote|contains|set|insert|replace|remove|table|search|arrayagg|objectagg|array|object|length|type|valid|keys)\b|->>?\s*['"]\$/i],
  ['get-lock', /\b(?:get_lock|release_lock|is_free_lock)\s*\(/i],
  ['user-variable', /(?:^|[^@\w])@[a-z_]\w*\s*:?=/i],
  ['if-function', /\bif\s*\(/i],
  ['rand', /\brand\s*\(/i],
  ['regexp', /\b(?:regexp|rlike)\b/i],
  ['match-against', /\bmatch\s*\([^)]*\)\s*against\b/i],
  ['sql-calc-found-rows', /\bsql_calc_found_rows\b|\bfound_rows\s*\(/i],
  ['collate-or-charset', /\b(?:collate|character\s+set|charset)\b/i],
  ['engine-clause', /\bengine\s*=|\balgorithm\s*=|\block\s*=/i],
  ['now-precision', /\b(?:curdate|curtime|sysdate)\s*\(|\bnow\(\d/i],
  ['update-join', /\bupdate\s+(?:ignore\s+)?[`\w.]+(?:\s+(?:as\s+)?\w+)?\s+(?:inner\s+|left\s+|right\s+|cross\s+)?join\b/i],
  ['delete-join', /\bdelete\s+(?!from\b)[`\w.]+(?:\s*,\s*[`\w.]+)*\s+from\b/i],
  ['database-function', /\b(?:database|schema)\s*\(\s*\)/i],
  ['concat-ws-or-field', /\b(?:field|elt|find_in_set)\s*\(/i],
];

const JOIN_RE = /\bjoin\b/i;
const UNION_RE = /\bunion\b/i;
const SUBQUERY_RE = /\(\s*select\b/i;
const TABLE_RE = /\b(?:from|join|into|update(?:\s+ignore)?)\s+([`"]?[a-z_][a-z0-9_]*[`"]?)/gi;
const DELETE_RE = /\bdelete\s+from\s+([`"]?[a-z_][a-z0-9_]*[`"]?)/gi;
const TOP_LEVEL_CLAUSE_RE = /\b(from|union|group\s+by|order\s+by|having)\b/i;

export function mysqlismsOf(sql) {
  const out = [];
  for (const [name, re] of MYSQLISMS) {
    if (re.test(sql)) out.push(name);
  }
  return out.sort();
}

export function tablesOf(sql) {
  const set = new Set();
  for (const re of [TABLE_RE, DELETE_RE]) {
    re.lastIndex = 0;
    let m;
    while ((m = re.exec(sql)) !== null) {
      const t = m[1].replace(/[`"]/g, '').toLowerCase();
      if (t && !['select', 'dual', 'set', 'values', 'where'].includes(t)) set.add(t);
    }
  }
  return [...set].sort();
}

// True when the text, outside any parentheses, has a clause that makes it a
// whole statement rather than a column list.
export function suppliesOwnFrom(text) {
  let top = '';
  let depth = 0;
  for (const ch of text) {
    if (ch === '(') depth++;
    else if (ch === ')') {
      if (depth > 0) depth--;
    } else if (depth === 0) top += ch;
  }
  return TOP_LEVEL_CLAUSE_RE.test(top);
}

export function statementKind(sql) {
  const m = LEADING_KEYWORD.exec(sql);
  const verb = m ? m[1].toUpperCase() : '';
  switch (verb) {
    case 'SELECT':
    case 'WITH':
      return 'SELECT';
    case 'INSERT':
    case 'UPDATE':
    case 'DELETE':
    case 'REPLACE':
      return verb;
    case 'CREATE':
    case 'ALTER':
    case 'DROP':
    case 'TRUNCATE':
      return 'DDL';
    default:
      return 'OTHER';
  }
}

// Fill in the derived fields of a site: kind, tables, dialect features,
// complexity and the suggested conversion wave (plan 7.3).
export function classify(site) {
  const sql = site.goldenSql;
  site.mysqlisms = mysqlismsOf(sql);
  if (site.sqlSource === 'variable') {
    // Nothing can be inferred from a statement that does not exist until runtime.
    site.kind = 'DYNAMIC';
    site.tables = [];
    site.complexity = 'complex';
    site.wave = 5;
    return site;
  }
  site.tables = tablesOf(sql);
  if (site.surface === 'expression' || site.surface === 'fragment') {
    // A fragment is a piece of a statement. It cannot be a whole-statement
    // conversion, so its kind says which piece it is.
    site.kind = site.kind || 'FRAGMENT';
    const dialect = site.mysqlisms.filter((m) => m !== 'backticks');
    site.complexity = site.dynamic || dialect.length > 0 ? 'complex' : 'simple';
    site.wave = site.complexity === 'simple' ? 1 : 5;
    return site;
  }
  site.kind = site.sqlSource === 'partial' ? 'DYNAMIC' : statementKind(sql);
  const multiTable = site.tables.length > 1 || JOIN_RE.test(sql);
  let hard = UNION_RE.test(sql) || SUBQUERY_RE.test(sql) || site.dynamic;
  for (const m of site.mysqlisms) {
    if (m !== 'backticks') hard = true;
  }
  site.complexity = hard ? 'complex' : multiTable ? 'moderate' : 'simple';
  site.wave = waveOf(site);
  return site;
}

function waveOf(s) {
  if (s.complexity === 'complex') {
    if (s.mysqlisms.length > 0 && s.kind === 'INSERT') return 3;
    return 5;
  }
  switch (s.kind) {
    case 'SELECT':
      return s.complexity === 'simple' ? 1 : 4;
    case 'INSERT':
    case 'UPDATE':
    case 'DELETE':
      return s.complexity === 'simple' ? 2 : 5;
    case 'REPLACE':
      return 3;
    default:
      return 5;
  }
}

// A bare expression (gorm.Expr, DB::raw) is a site only when it carries
// something the target dialect would spell differently, or when its text is not
// known. NOW(), NULL, COALESCE(...) and arithmetic are the same on both engines
// and would bury the sites that matter.
export function isPortableExpression(site) {
  if (site.surface !== 'expression' || site.dynamic) return false;
  return site.mysqlisms.every((m) => m === 'backticks');
}
