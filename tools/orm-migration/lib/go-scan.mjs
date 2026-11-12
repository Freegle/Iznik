// Finds raw SQL call sites in Go source.
//
// This reads tokens, not text: SQL in this codebase is assembled by multi-line
// string concatenation and backtick literals, and a regular expression over
// lines gets the statement text, the enclosing function and the call
// boundaries wrong. The tokeniser understands strings, raw strings, runes and
// comments, so SQL inside a comment is never a site and a quote inside SQL
// never confuses a call boundary.

import { LEADING_KEYWORD, AMBIGUOUS_LEADING_KEYWORD, normaliseSql, suppliesOwnFrom, classify, isPortableExpression } from './sql.mjs';

// Methods that can carry a SQL string as their first argument: GORM's Raw and
// Exec, and database/sql for the services that use it directly.
const SQL_METHODS = new Set([
  'Raw', 'Exec', 'ExecContext', 'Query', 'QueryRow', 'QueryContext', 'QueryRowContext',
  'Prepare', 'PrepareContext',
]);

// Helpers that take a SQL string and run it, so the call looks like
// database.RetryExec(db, "INSERT ...") rather than db.Exec(...). The value is
// the position of the SQL argument.
const SQL_WRAPPERS = new Map([
  ['RetryExec', 1],
  ['RetryExecResult', 1],
  ['RetryQuery', 2],
  ['ExecInsertGetID', 1],
]);

// GORM chain methods whose string argument is a fragment of a statement, with
// the part of the statement each one holds. A fragment is inventoried only when
// it carries something the other dialect spells differently.
const CHAIN_FRAGMENT_METHODS = new Map([
  ['Where', 'WHERE'], ['Or', 'WHERE'], ['Not', 'WHERE'], ['Select', 'SELECT-LIST'], ['Order', 'ORDER'],
  ['Joins', 'JOIN'], ['Group', 'GROUP'], ['Having', 'HAVING'], ['Table', 'FROM'],
]);

// Receiver names that denote a database handle. Exec and Query are generic
// method names, so without a closed list a web framework's c.Query("uid")
// would flood the manifest.
const DB_HANDLES = new Set(['db', 'tx', 'sqlDB', 'gdb', 'stmt', 'conn', 'dbConn', 'DBConn', 'q']);

// A handle is also any name ending in "db" (mysqlDB, groupsDB, readDB): the spatial
// and routing loaders name their connections for the database they open.
export function isSqlHandle(method, receiver) {
  if (method === 'Raw') return true;
  const last = receiver.split('.').pop();
  if (DB_HANDLES.has(last)) return true;
  const lower = last.toLowerCase();
  return lower === 'tx' || lower.endsWith('db');
}

// ---------------------------------------------------------------- tokeniser

const OPERATORS = ['<<=', '>>=', '&^=', '...', '&&', '||', '<-', '++', '--', '==', '!=', '<=', '>=', ':=',
  '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<', '>>', '&^'];

const ESCAPES = { n: '\n', t: '\t', r: '\r', a: '\x07', b: '\b', f: '\f', v: '\v', '\\': '\\', "'": "'", '"': '"' };

function unquote(body) {
  let out = '';
  for (let i = 0; i < body.length; i++) {
    const c = body[i];
    if (c !== '\\') {
      out += c;
      continue;
    }
    const n = body[++i];
    if (n in ESCAPES) out += ESCAPES[n];
    else if (n === 'x') {
      out += String.fromCharCode(parseInt(body.slice(i + 1, i + 3), 16));
      i += 2;
    } else if (n === 'u') {
      out += String.fromCodePoint(parseInt(body.slice(i + 1, i + 5), 16));
      i += 4;
    } else if (n === 'U') {
      out += String.fromCodePoint(parseInt(body.slice(i + 1, i + 9), 16));
      i += 8;
    } else if (/[0-7]/.test(n)) {
      out += String.fromCharCode(parseInt(body.slice(i, i + 3), 8));
      i += 2;
    } else out += n;
  }
  return out;
}

export function tokeniseGo(src) {
  const toks = [];
  const comments = [];
  let i = 0;
  let line = 1;
  const n = src.length;
  while (i < n) {
    const c = src[i];
    if (c === '\n') {
      line++;
      i++;
      continue;
    }
    if (c === ' ' || c === '\t' || c === '\r') {
      i++;
      continue;
    }
    if (c === '/' && src[i + 1] === '/') {
      let j = src.indexOf('\n', i);
      if (j < 0) j = n;
      comments.push({ line, text: src.slice(i + 2, j) });
      i = j;
      continue;
    }
    if (c === '/' && src[i + 1] === '*') {
      let j = src.indexOf('*/', i + 2);
      j = j < 0 ? n : j + 2;
      const text = src.slice(i + 2, j - 2);
      comments.push({ line, text });
      line += (src.slice(i, j).match(/\n/g) || []).length;
      i = j;
      continue;
    }
    if (c === '"') {
      let j = i + 1;
      while (j < n && src[j] !== '"' && src[j] !== '\n') j += src[j] === '\\' ? 2 : 1;
      toks.push({ t: 'str', v: unquote(src.slice(i + 1, j)), line });
      i = j + 1;
      continue;
    }
    if (c === '`') {
      const j = src.indexOf('`', i + 1);
      const end = j < 0 ? n : j;
      toks.push({ t: 'str', v: src.slice(i + 1, end).replace(/\r/g, ''), line, raw: true });
      line += (src.slice(i, end + 1).match(/\n/g) || []).length;
      i = end + 1;
      continue;
    }
    if (c === "'") {
      let j = i + 1;
      while (j < n && src[j] !== "'" && src[j] !== '\n') j += src[j] === '\\' ? 2 : 1;
      toks.push({ t: 'rune', v: src.slice(i, j + 1), line });
      i = j + 1;
      continue;
    }
    if (/[A-Za-z_\u0080-￿]/.test(c)) {
      let j = i + 1;
      while (j < n && /[A-Za-z0-9_\u0080-￿]/.test(src[j])) j++;
      toks.push({ t: 'id', v: src.slice(i, j), line });
      i = j;
      continue;
    }
    if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(src[i + 1] || ''))) {
      let j = i + 1;
      while (j < n && /[0-9A-Za-z_.]/.test(src[j])) j++;
      toks.push({ t: 'num', v: src.slice(i, j), line });
      i = j;
      continue;
    }
    const op = OPERATORS.find((o) => src.startsWith(o, i));
    if (op) {
      toks.push({ t: 'op', v: op, line });
      i += op.length;
      continue;
    }
    toks.push({ t: 'op', v: c, line });
    i++;
  }
  return { toks, comments };
}

// ------------------------------------------------------------- token helpers

const OPEN = { '(': ')', '[': ']', '{': '}' };

// Index of the token that closes the bracket opened at toks[i].
function matching(toks, i) {
  let depth = 0;
  for (let j = i; j < toks.length; j++) {
    const v = toks[j].t === 'op' ? toks[j].v : null;
    if (v === '(' || v === '[' || v === '{') depth++;
    else if (v === ')' || v === ']' || v === '}') {
      depth--;
      if (depth === 0) return j;
    }
  }
  return toks.length - 1;
}

// Splits the arguments of the call whose "(" is at toks[open], returning
// [start, end) token ranges.
function splitArgs(toks, open) {
  const close = matching(toks, open);
  const args = [];
  let start = open + 1;
  let depth = 0;
  for (let j = open + 1; j < close; j++) {
    const t = toks[j];
    if (t.t !== 'op') continue;
    if (t.v in OPEN) depth++;
    else if (t.v === ')' || t.v === ']' || t.v === '}') depth--;
    else if (t.v === ',' && depth === 0) {
      args.push([start, j]);
      start = j + 1;
    }
  }
  if (start < close) args.push([start, close]);
  return { args, close };
}

// Receiver text for the call whose "." is at toks[dot]: the dotted name or call
// chain just before it.
function receiverBefore(toks, dot) {
  let j = dot - 1;
  const parts = [];
  while (j >= 0) {
    const t = toks[j];
    if (t.t === 'id') {
      parts.unshift(t.v);
      if (j >= 1 && toks[j - 1].t === 'op' && toks[j - 1].v === '.') {
        j -= 2;
        continue;
      }
      break;
    }
    if (t.t === 'op' && (t.v === ')' || t.v === ']')) {
      // A call or index: walk back to its opener and keep going to the callee.
      let depth = 0;
      let k = j;
      for (; k >= 0; k--) {
        const v = toks[k].t === 'op' ? toks[k].v : null;
        if (v === ')' || v === ']' || v === '}') depth++;
        else if (v === '(' || v === '[' || v === '{') {
          depth--;
          if (depth === 0) break;
        }
      }
      parts.unshift(t.v === ')' ? '()' : '[]');
      j = k - 1;
      continue;
    }
    break;
  }
  return parts.join('.').replace(/\.\(\)/g, '()').replace(/\.\[\]/g, '[]');
}

// ------------------------------------------------------------------ folding

function applySprintf(format, args) {
  let idx = 0;
  let ok = true;
  const out = format.replace(/%(%|[-+# 0]*\d*(?:\.\d+)?[a-zA-Z])/g, (m, spec) => {
    if (spec === '%') return '%';
    if (idx >= args.length) {
      ok = false;
      return m;
    }
    return args[idx++];
  });
  return ok && idx === args.length ? out : null;
}

// Reconstructs the SQL text of an expression made of string literals joined by
// "+", package constants and fmt.Sprintf. Anything else becomes a {{expr}}
// hole and marks the site dynamic. Returns null when the expression is not a
// string expression at all.
function fold(toks, from, to, ctx) {
  let pos = from;
  let dynamic = false;
  let literals = 0;

  function operand() {
    const t = toks[pos];
    if (!t) return null;
    if (t.t === 'str') {
      pos++;
      literals++;
      return t.v;
    }
    if (t.t === 'num') {
      pos++;
      return t.v;
    }
    if (t.t === 'op' && t.v === '(') {
      const close = matching(toks, pos);
      const inner = fold(toks, pos + 1, close, ctx);
      pos = close + 1;
      if (!inner) return '{{expr}}';
      literals += inner.literals;
      dynamic = dynamic || inner.dynamic;
      return inner.text;
    }
    if (t.t === 'id') {
      // A dotted name, possibly followed by a call and index operations.
      const names = [t.v];
      pos++;
      while (toks[pos] && toks[pos].t === 'op' && toks[pos].v === '.' && toks[pos + 1] && toks[pos + 1].t === 'id') {
        names.push(toks[pos + 1].v);
        pos += 2;
      }
      const name = names.join('.');
      if (toks[pos] && toks[pos].t === 'op' && toks[pos].v === '(') {
        const close = matching(toks, pos);
        const open = pos;
        pos = close + 1;
        while (toks[pos] && toks[pos].t === 'op' && (toks[pos].v === '[' || toks[pos].v === '(')) pos = matching(toks, pos) + 1;
        if (name === 'fmt.Sprintf' || name === 'Sprintf') {
          const { args } = splitArgs(toks, open);
          if (args.length) {
            const f = fold(toks, args[0][0], args[0][1], ctx);
            if (f) {
              if (!f.dynamic) {
                const vals = [];
                let resolved = true;
                for (const [a, b] of args.slice(1)) {
                  const v = fold(toks, a, b, ctx);
                  if (!v || v.dynamic) {
                    resolved = false;
                    break;
                  }
                  vals.push(v.text);
                }
                const out = resolved ? applySprintf(f.text, vals) : null;
                if (out !== null) {
                  literals++;
                  return out;
                }
              }
              literals++;
              dynamic = true;
              return f.text;
            }
          }
        }
        dynamic = true;
        return '{{expr}}';
      }
      while (toks[pos] && toks[pos].t === 'op' && toks[pos].v === '[') pos = matching(toks, pos) + 1;
      if (ctx.consts.has(name)) {
        literals++;
        return ctx.consts.get(name);
      }
      const bare = names.length === 1 ? null : names.slice(-2).join('.');
      if (bare && ctx.crossConsts.has(bare)) {
        literals++;
        return ctx.crossConsts.get(bare);
      }
      dynamic = true;
      return '{{expr}}';
    }
    return null;
  }

  const parts = [];
  for (;;) {
    const part = operand();
    if (part === null) return null;
    parts.push(part);
    const t = toks[pos];
    if (pos < to && t && t.t === 'op' && t.v === '+') {
      pos++;
      continue;
    }
    break;
  }
  if (pos !== to || literals === 0) return null;
  return { text: parts.join(''), dynamic, literals };
}

// ------------------------------------------------------ package-level consts

// String and number constants declared at package level, so that
// "SELECT " + columns + " FROM t" folds to real SQL. Only single-name specs
// with a fully static value are recorded.
export function collectConsts(toks) {
  const out = new Map();
  let depth = 0;
  for (let i = 0; i < toks.length; i++) {
    const t = toks[i];
    if (t.t === 'op') {
      if (t.v in OPEN) depth++;
      else if (t.v === ')' || t.v === ']' || t.v === '}') depth--;
      continue;
    }
    if (depth !== 0 || t.t !== 'id' || (t.v !== 'const' && t.v !== 'var')) continue;
    const grouped = toks[i + 1] && toks[i + 1].t === 'op' && toks[i + 1].v === '(';
    const end = grouped ? matching(toks, i + 1) : specEnd(toks, i + 1);
    const specs = grouped ? splitSpecs(toks, i + 2, end) : [[i + 1, end]];
    for (const [a, b] of specs) {
      const eq = findEquals(toks, a, b);
      if (eq < 0 || eq !== a + 1 && !(toks[a + 1] && toks[a + 1].t === 'id' && eq === a + 2)) continue;
      if (toks[a].t !== 'id') continue;
      const v = fold(toks, eq + 1, b, { consts: out, crossConsts: new Map() });
      if (v && !v.dynamic) out.set(toks[a].v, v.text);
      else if (b === eq + 2 && toks[eq + 1].t === 'num') out.set(toks[a].v, toks[eq + 1].v);
    }
    i = grouped ? end : end - 1;
  }
  return out;
}

function findEquals(toks, a, b) {
  for (let j = a; j < b; j++) if (toks[j].t === 'op' && toks[j].v === '=') return j;
  return -1;
}

// End (exclusive) of a statement starting at toks[from]: the first token on a
// later line once the previous token does not continue the expression.
function specEnd(toks, from) {
  let depth = 0;
  let j = from;
  for (; j < toks.length; j++) {
    const t = toks[j];
    if (t.t === 'op') {
      if (t.v in OPEN) depth++;
      else if (t.v === ')' || t.v === ']' || t.v === '}') depth--;
    }
    const next = toks[j + 1];
    if (!next) {
      j++;
      break;
    }
    const continues = t.t === 'op' && ['+', ',', '(', '.', '-', '*', '/', '&&', '||', '='].includes(t.v);
    if (depth <= 0 && next.line > t.line && !continues) {
      j++;
      break;
    }
  }
  return j;
}

function splitSpecs(toks, from, to) {
  const specs = [];
  let a = from;
  while (a < to) {
    const b = Math.min(specEnd(toks, a), to);
    specs.push([a, b]);
    a = b;
  }
  return specs;
}

// ------------------------------------------------------------ function map

// Ranges of top-level function declarations, with a name that says which type
// a method belongs to.
function functionRanges(toks) {
  const ranges = [];
  let depth = 0;
  for (let i = 0; i < toks.length; i++) {
    const t = toks[i];
    if (t.t === 'op') {
      if (t.v in OPEN) depth++;
      else if (t.v === ')' || t.v === ']' || t.v === '}') depth--;
      continue;
    }
    if (depth !== 0 || t.t !== 'id' || t.v !== 'func') continue;
    let j = i + 1;
    let recv = '';
    if (toks[j] && toks[j].t === 'op' && toks[j].v === '(') {
      const close = matching(toks, j);
      const ids = toks.slice(j + 1, close).filter((x) => x.t === 'id');
      recv = ids.length ? ids[ids.length - 1].v : '';
      j = close + 1;
    }
    if (!toks[j] || toks[j].t !== 'id') continue;
    const name = recv ? `${recv}.${toks[j].v}` : toks[j].v;
    let k = j + 1;
    let paren = 0;
    for (; k < toks.length; k++) {
      const v = toks[k].t === 'op' ? toks[k].v : null;
      if (v === '(' || v === '[') paren++;
      else if (v === ')' || v === ']') paren--;
      else if (v === '{' && paren === 0) break;
      else if (v === ';') break;
    }
    if (k >= toks.length || toks[k].v !== '{') continue;
    const close = matching(toks, k);
    ranges.push({ name, start: i, open: k, end: close });
    i = close;
  }
  return ranges;
}

// ------------------------------------------------------------------ scanning

function sourceLines(src) {
  return src.split('\n');
}

// The text of a "// keep-raw: reason" annotation within the guard's window of
// the line, or null. Mirrors .claude/check-raw-sql.sh: the line itself or the
// 25 lines above it.
export const KEEP_RAW_WINDOW = 25;
export const KEEP_RAW_RE = /(?:\/\/|#|\*)\s*keep-raw:\s*(.*)$/i;

export function keepRawNear(lines, lineNo) {
  const found = keepRawAt(lines, lineNo);
  return found ? found.reason : null;
}

// As keepRawNear, and says which line the marker is on, so a marker that no site
// uses can be reported.
export function keepRawAt(lines, lineNo) {
  const first = Math.max(1, lineNo - KEEP_RAW_WINDOW);
  for (let n = lineNo; n >= first; n--) {
    const m = KEEP_RAW_RE.exec(lines[n - 1] || '');
    if (!m) continue;
    // Following comment lines continue the reason.
    let reason = m[1].trim();
    for (let k = n + 1; k <= lineNo && /^\s*(?:\/\/|#|\*)\s?(?!keep-raw:)\S/.test(lines[k - 1] || ''); k++) {
      reason += ' ' + lines[k - 1].replace(/^\s*(?:\/\/|#|\*)\s?/, '').trim();
    }
    return { reason: reason.replace(/\s+/g, ' ').trim(), line: n };
  }
  return null;
}

/**
 * Scans one Go file.
 * @param {string} src source text
 * @param {string} rel repository-relative path, recorded on each site
 * @param {object} opts {consts: Map, crossConsts: Map}
 */
export function scanGoSource(src, rel, opts = {}) {
  const { toks } = tokeniseGo(src);
  const lines = sourceLines(src);
  const ctx = { consts: opts.consts || new Map(), crossConsts: opts.crossConsts || new Map() };
  const funcs = functionRanges(toks);
  const sites = [];

  const enclosing = (idx) => funcs.find((f) => idx >= f.start && idx <= f.end);

  const annotate = (site, line) => {
    const kr = keepRawAt(lines, line);
    if (kr !== null) {
      site.annotation = kr.reason;
      site.annotationLine = kr.line;
    }
  };

  for (let i = 0; i < toks.length; i++) {
    const t = toks[i];
    if (t.t !== 'id') continue;
    const next = toks[i + 1];

    // clause.OnConflict{...} and clause.Insert{Modifier: ...}: GORM builds the SQL,
    // and each needs rewriting for PostgreSQL.
    if (
      t.v === 'clause' && next && next.t === 'op' && next.v === '.' && toks[i + 2] && toks[i + 2].t === 'id' &&
      ['OnConflict', 'Insert'].includes(toks[i + 2].v) && toks[i + 3] && toks[i + 3].t === 'op' && toks[i + 3].v === '{'
    ) {
      const close = matching(toks, i + 3);
      const inner = toks.slice(i + 4, close).map((x) => (x.t === 'str' ? JSON.stringify(x.v) : x.v)).join(' ');
      const text = normaliseSql(`clause.${toks[i + 2].v} { ${inner} }`);
      if (toks[i + 2].v === 'Insert' && !/\bModifier\b/.test(text)) continue;
      const fn = enclosing(i);
      const site = {
        file: rel, line: t.line, function: fn ? fn.name : '', receiver: 'clause', method: toks[i + 2].v,
        surface: 'clause', sqlSource: 'literal', dynamic: false, args: 0, goldenSql: text,
      };
      classify(site);
      annotate(site, t.line);
      sites.push(site);
      // Keep scanning inside the literal: its values are often gorm.Expr sites.
      continue;
    }

    if (!next || next.t !== 'op' || next.v !== '(') continue;
    const prev = toks[i - 1];
    const dotted = prev && prev.t === 'op' && prev.v === '.';

    // sql.Result.LastInsertId(): PostgreSQL drivers do not provide it.
    if (dotted && t.v === 'LastInsertId') {
      const fn = enclosing(i);
      const site = {
        file: rel, line: t.line, function: fn ? fn.name : '', receiver: receiverBefore(toks, i - 1), method: 'LastInsertId',
        surface: 'result', sqlSource: 'literal', dynamic: false, args: 0, goldenSql: 'sql.Result.LastInsertId()',
      };
      classify(site);
      annotate(site, t.line);
      sites.push(site);
      continue;
    }

    let argIdx;
    let receiver = '';
    if (dotted && SQL_METHODS.has(t.v)) {
      argIdx = 0;
      receiver = receiverBefore(toks, i - 1);
    } else if (SQL_WRAPPERS.has(t.v)) {
      argIdx = SQL_WRAPPERS.get(t.v);
      receiver = dotted ? receiverBefore(toks, i - 1) : '';
    } else if (dotted && CHAIN_FRAGMENT_METHODS.has(t.v)) {
      argIdx = 0;
      receiver = receiverBefore(toks, i - 1);
    } else if (t.v === 'Expr' && dotted && prev && toks[i - 2] && ['gorm', 'clause'].includes(toks[i - 2].v)) {
      argIdx = 0;
    } else continue;

    const fn = enclosing(i);
    const overridesBuildClauses = !!fn && toks.slice(fn.open, fn.end).some((x) => x.t === 'id' && x.v === 'BuildClauses');
    const { args } = splitArgs(toks, i + 1);
    if (args.length <= argIdx) continue;
    const [a, b] = args[argIdx];

    const isExpr = t.v === 'Expr';
    // Select in a function that replaces GORM's clause builders can carry a
    // whole statement; anywhere else a chain method carries a fragment.
    const isSelect = t.v === 'Select' && overridesBuildClauses;
    const isFragment = CHAIN_FRAGMENT_METHODS.has(t.v) && !isSelect;

    const folded = fold(toks, a, b, ctx);
    const text = folded ? normaliseSql(folded.text) : '';
    let dynamic = folded ? folded.dynamic : false;
    let sql;
    let source;
    let surface = 'statement';

    if (isFragment) {
      if (!folded) continue;
      surface = 'fragment';
      sql = text;
      source = dynamic ? 'partial' : 'literal';
    } else if (isExpr) {
      if (!folded) continue;
      surface = 'expression';
      sql = text;
      source = dynamic ? 'partial' : 'literal';
    } else if (
      folded &&
      LEADING_KEYWORD.test(text) &&
      (!AMBIGUOUS_LEADING_KEYWORD.test(text) || isSqlHandle(t.v, receiver))
    ) {
      sql = text;
      source = 'literal';
    } else if (isSelect && folded && dynamic && suppliesOwnFrom(text)) {
      sql = text;
      source = 'partial';
    } else if (folded && dynamic && isSqlHandle(t.v, receiver) && !isSelect) {
      sql = text;
      source = 'partial';
    } else if (isSelect && folded && suppliesOwnFrom(text)) {
      sql = text;
      source = 'literal';
    } else if (folded) {
      continue;
    } else if (!isSelect && isSqlHandle(t.v, receiver)) {
      const argText = toks.slice(a, b).map((x) => x.v).join('');
      sql = `{{built at runtime: ${argText}}}`;
      dynamic = true;
      source = 'variable';
    } else continue;

    const site = {
      file: rel,
      line: t.line,
      function: fn ? fn.name : '',
      receiver,
      method: isExpr ? `${toks[i - 2].v}.Expr` : t.v,
      surface,
      sqlSource: source,
      dynamic,
      args: args.length - argIdx - 1,
      goldenSql: sql,
    };
    if (surface === 'fragment') site.kind = CHAIN_FRAGMENT_METHODS.get(t.v);
    classify(site);
    annotate(site, t.line);
    // A portable expression or fragment is not a site unless someone wrote a
    // keep-raw marker for it: that is a human saying it is raw SQL.
    if (site.annotation === undefined && (isPortableExpression(site) || (isFragment && site.mysqlisms.every((m) => m === 'backticks')))) continue;
    sites.push(site);
  }
  return sites;
}
