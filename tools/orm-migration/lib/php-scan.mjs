// Finds raw SQL call sites in PHP source (Laravel).
//
// Like the Go scanner this reads tokens rather than lines, so SQL inside a
// comment is never a site, a heredoc is one string, and a call that spans many
// lines of a fluent chain is read as one call.

import {
  normaliseSql, classify, statementKind, isPortableExpression, LEADING_KEYWORD, AMBIGUOUS_LEADING_KEYWORD,
} from './sql.mjs';
import { keepRawAt } from './go-scan.mjs';

// Whole-statement methods on the DB facade or a connection.
const CONNECTION_METHODS = new Set([
  'select', 'selectOne', 'scalar', 'cursor', 'insert', 'update', 'delete', 'statement',
  'unprepared', 'affectingStatement',
]);

// Query builder methods whose argument is a fragment of a statement.
const FRAGMENT_METHODS = new Set([
  'selectRaw', 'fromRaw', 'whereRaw', 'orWhereRaw', 'groupByRaw', 'havingRaw', 'orHavingRaw',
  'orderByRaw', 'orderByRawDesc', 'joinRaw', 'rawIndex', 'rawColumn',
]);

// PDO used directly, bypassing the facade.
const PDO_METHODS = new Set(['prepare', 'exec', 'query']);

const CASTS = new Set(['int', 'integer', 'string', 'float', 'double', 'bool', 'boolean', 'array']);

// ---------------------------------------------------------------- tokeniser

const OPS3 = ['<=>', '**=', '...', '<<=', '>>=', '===', '!==', '??=', '?->'];
const OPS2 = ['->', '::', '=>', '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '++', '--', '+=', '-=', '*=', '/=',
  '.=', '%=', '|=', '&=', '^=', '<<', '>>', '**'];

function unescapeSingle(body) {
  return body.replace(/\\([\\'])/g, '$1');
}

export function tokenisePhp(src) {
  const toks = [];
  let i = 0;
  let line = 1;
  const n = src.length;
  let inPhp = false;
  const countLines = (s) => (s.match(/\n/g) || []).length;

  while (i < n) {
    if (!inPhp) {
      const open = src.indexOf('<?', i);
      const end = open < 0 ? n : open;
      line += countLines(src.slice(i, end));
      if (open < 0) break;
      i = open + (src.startsWith('<?php', open) ? 5 : src.startsWith('<?=', open) ? 3 : 2);
      inPhp = true;
      continue;
    }
    const c = src[i];
    if (c === '\n') {
      line++;
      i++;
      continue;
    }
    if (/\s/.test(c)) {
      i++;
      continue;
    }
    if (c === '?' && src[i + 1] === '>') {
      inPhp = false;
      i += 2;
      continue;
    }
    if ((c === '/' && src[i + 1] === '/') || (c === '#' && src[i + 1] !== '[')) {
      let j = i;
      while (j < n && src[j] !== '\n' && !(src[j] === '?' && src[j + 1] === '>')) j++;
      i = j;
      continue;
    }
    if (c === '/' && src[i + 1] === '*') {
      let j = src.indexOf('*/', i + 2);
      j = j < 0 ? n : j + 2;
      line += countLines(src.slice(i, j));
      i = j;
      continue;
    }
    if (c === "'") {
      let j = i + 1;
      while (j < n && src[j] !== "'") j += src[j] === '\\' ? 2 : 1;
      toks.push({ t: 'str', v: unescapeSingle(src.slice(i + 1, j)), line, interp: false });
      line += countLines(src.slice(i, j + 1));
      i = j + 1;
      continue;
    }
    if (c === '"') {
      let j = i + 1;
      while (j < n && src[j] !== '"') j += src[j] === '\\' ? 2 : 1;
      const body = src.slice(i + 1, j);
      toks.push({ t: 'str', ...doubleQuoted(body), line });
      line += countLines(src.slice(i, j + 1));
      i = j + 1;
      continue;
    }
    if (c === '<' && src.startsWith('<<<', i)) {
      const m = /^<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/.exec(src.slice(i, i + 80));
      if (m) {
        const label = m[2];
        const bodyStart = i + m[0].length;
        const endRe = new RegExp(`^[ \\t]*${label}\\b`, 'm');
        const rest = src.slice(bodyStart);
        const em = endRe.exec(rest);
        const bodyEnd = em ? bodyStart + em.index : n;
        let body = src.slice(bodyStart, bodyEnd).replace(/\r?\n$/, '');
        const indent = em ? /^[ \t]*/.exec(em[0])[0].length : 0;
        if (indent) body = body.split('\n').map((l) => l.slice(Math.min(indent, /^[ \t]*/.exec(l)[0].length))).join('\n');
        const tok = m[1] === "'" ? { v: body, interp: false } : doubleQuoted(body, true);
        toks.push({ t: 'str', ...tok, line });
        const stop = em ? bodyStart + em.index + em[0].length : n;
        line += countLines(src.slice(i, stop));
        i = stop;
        continue;
      }
    }
    if (c === '$' && /[A-Za-z_]/.test(src[i + 1] || '')) {
      let j = i + 2;
      while (j < n && /[A-Za-z0-9_]/.test(src[j])) j++;
      toks.push({ t: 'var', v: src.slice(i, j), line });
      i = j;
      continue;
    }
    if (/[A-Za-z_\\\u0080-￿]/.test(c)) {
      let j = i + 1;
      while (j < n && /[A-Za-z0-9_\\\u0080-￿]/.test(src[j])) j++;
      toks.push({ t: 'id', v: src.slice(i, j), line });
      i = j;
      continue;
    }
    if (/[0-9]/.test(c)) {
      let j = i + 1;
      while (j < n && /[0-9A-Za-z_.]/.test(src[j])) j++;
      toks.push({ t: 'num', v: src.slice(i, j), line });
      i = j;
      continue;
    }
    const op = OPS3.find((o) => src.startsWith(o, i)) || OPS2.find((o) => src.startsWith(o, i));
    if (op) {
      toks.push({ t: 'op', v: op, line });
      i += op.length;
      continue;
    }
    toks.push({ t: 'op', v: c, line });
    i++;
  }
  return toks;
}

// A double-quoted or heredoc string. Interpolations become {{expr}} holes and
// mark the string as interpolated.
function doubleQuoted(body, heredoc = false) {
  let interp = false;
  const hole = () => {
    interp = true;
    return '{{expr}}';
  };
  let out = body
    .replace(/\{\$[^}]*\}/g, hole)
    .replace(/\$\{[^}]*\}/g, hole)
    .replace(/\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*|\[[^\]]*\])?/g, hole);
  const simple = { n: '\n', t: '\t', r: '\r', '\\': '\\', '"': heredoc ? '\\"' : '"', $: '$' };
  out = out.replace(/\\([ntr\\"$])/g, (m, k) => (k in simple ? simple[k] : m));
  return { v: out, interp };
}

// ------------------------------------------------------------ token helpers

const OPEN = new Set(['(', '[', '{']);
const CLOSE = new Set([')', ']', '}']);

function matching(toks, i) {
  let depth = 0;
  for (let j = i; j < toks.length; j++) {
    const t = toks[j];
    if (t.t !== 'op') continue;
    if (OPEN.has(t.v)) depth++;
    else if (CLOSE.has(t.v)) {
      depth--;
      if (depth === 0) return j;
    }
  }
  return toks.length - 1;
}

function splitArgs(toks, open) {
  const close = matching(toks, open);
  const args = [];
  let start = open + 1;
  let depth = 0;
  for (let j = open + 1; j < close; j++) {
    const t = toks[j];
    if (t.t !== 'op') continue;
    if (OPEN.has(t.v)) depth++;
    else if (CLOSE.has(t.v)) depth--;
    else if (t.v === ',' && depth === 0) {
      args.push([start, j]);
      start = j + 1;
    }
  }
  if (start < close) args.push([start, close]);
  return args;
}

// ------------------------------------------------------------------ folding

// Reconstructs the SQL of an expression made of strings joined by ".", class
// constants and sprintf. Anything else is a hole.
function fold(toks, from, to, consts) {
  let pos = from;
  let dynamic = false;
  let literals = 0;

  const operand = () => {
    const t = toks[pos];
    if (!t || pos >= to) return null;
    if (t.t === 'str') {
      pos++;
      literals++;
      if (t.interp) dynamic = true;
      return t.v;
    }
    if (t.t === 'num') {
      pos++;
      literals++;
      return t.v;
    }
    if (t.t === 'op' && t.v === '(') {
      const close = matching(toks, pos);
      // A cast such as (int)$delay is part of the operand that follows it.
      if (close === pos + 2 && toks[pos + 1].t === 'id' && CASTS.has(toks[pos + 1].v.toLowerCase())) {
        pos = close + 1;
        dynamic = true;
        return operand() ?? '{{expr}}';
      }
      const inner = fold(toks, pos + 1, close, consts);
      pos = close + 1;
      if (!inner) return '{{expr}}';
      literals += inner.literals;
      dynamic = dynamic || inner.dynamic;
      return inner.text;
    }
    if (t.t === 'id' || t.t === 'var') {
      // A name chain: Foo::BAR, $this->prop, helper(...), $x['k'].
      const start = pos;
      pos++;
      while (pos < to) {
        const u = toks[pos];
        if (u.t === 'op' && (u.v === '::' || u.v === '->' || u.v === '?->') && toks[pos + 1] && toks[pos + 1].t !== 'op') {
          pos += 2;
        } else if (u.t === 'op' && (u.v === '(' || u.v === '[')) {
          pos = matching(toks, pos) + 1;
        } else break;
      }
      const name = toks.slice(start, pos).map((x) => x.v).join('');
      const fn = /^(?:\\?)(sprintf|vsprintf)$/.exec(toks[start].v);
      if (fn && toks[start + 1] && toks[start + 1].v === '(') {
        const args = splitArgs(toks, start + 1);
        if (args.length) {
          const f = fold(toks, args[0][0], args[0][1], consts);
          if (f) {
            literals++;
            dynamic = true;
            return f.text;
          }
        }
      }
      const cm = /^(?:self|static)::([A-Z_][A-Z0-9_]*)$/.exec(name);
      if (cm && consts.has(cm[1])) {
        literals++;
        return consts.get(cm[1]);
      }
      dynamic = true;
      return '{{expr}}';
    }
    return null;
  };

  const parts = [];
  for (;;) {
    const part = operand();
    if (part === null) return null;
    parts.push(part);
    if (pos < to && toks[pos].t === 'op' && toks[pos].v === '.') {
      pos++;
      continue;
    }
    break;
  }
  if (pos !== to || literals === 0) return null;
  return { text: parts.join(''), dynamic, literals };
}

// class constants declared in the file: `const NAME = '...';`
function collectConsts(toks) {
  const out = new Map();
  for (let i = 0; i < toks.length; i++) {
    if (toks[i].t === 'id' && toks[i].v === 'const' && toks[i + 1] && toks[i + 2] && toks[i + 2].v === '=') {
      let j = i + 3;
      while (j < toks.length && !(toks[j].t === 'op' && toks[j].v === ';')) j++;
      const f = fold(toks, i + 3, j, out);
      if (f && !f.dynamic) out.set(toks[i + 1].v, f.text);
    }
  }
  return out;
}

// ------------------------------------------------------- class / function map

function structureRanges(toks) {
  const classes = [];
  const funcs = [];
  for (let i = 0; i < toks.length; i++) {
    const t = toks[i];
    if (t.t !== 'id') continue;
    if (['class', 'trait', 'interface', 'enum'].includes(t.v) && toks[i + 1] && toks[i + 1].t === 'id' && !(toks[i - 1] && toks[i - 1].v === '::')) {
      let k = i + 2;
      while (k < toks.length && !(toks[k].t === 'op' && toks[k].v === '{')) k++;
      if (k < toks.length) classes.push({ name: toks[i + 1].v, start: i, end: matching(toks, k) });
    } else if (t.v === 'function' && toks[i + 1] && toks[i + 1].t === 'id') {
      let k = i + 2;
      if (toks[k] && toks[k].v === '(') {
        k = matching(toks, k) + 1;
        while (k < toks.length && !(toks[k].t === 'op' && (toks[k].v === '{' || toks[k].v === ';'))) k++;
        if (toks[k] && toks[k].v === '{') funcs.push({ name: toks[i + 1].v, start: i, end: matching(toks, k) });
      }
    }
  }
  return { classes, funcs };
}

function innermost(ranges, idx) {
  let best = null;
  for (const r of ranges) {
    if (idx >= r.start && idx <= r.end && (!best || r.start >= best.start)) best = r;
  }
  return best;
}

// ------------------------------------------------------------------ scanning

export function scanPhpSource(src, rel) {
  const toks = tokenisePhp(src);
  const lines = src.split('\n');
  const consts = collectConsts(toks);
  const { classes, funcs } = structureRanges(toks);
  const sites = [];

  for (let i = 0; i < toks.length; i++) {
    const t = toks[i];
    if (t.t !== 'id') continue;
    const next = toks[i + 1];
    if (!next || next.t !== 'op' || next.v !== '(') continue;
    const prev = toks[i - 1];
    if (!prev || prev.t !== 'op') continue;

    // PDO::lastInsertId() needs a sequence name on PostgreSQL.
    if ((prev.v === '->' || prev.v === '?->') && t.v === 'lastInsertId') {
      const fn = innermost(funcs, i);
      const cls = innermost(classes, i);
      const site = {
        file: rel, line: t.line, class: cls ? cls.name : '', function: fn ? fn.name : '', receiver: '', method: 'lastInsertId',
        surface: 'result', sqlSource: 'literal', dynamic: false, args: 0, goldenSql: 'PDO::lastInsertId()',
      };
      classify(site);
      const kr = keepRawAt(lines, t.line);
      if (kr !== null) {
        site.annotation = kr.reason;
        site.annotationLine = kr.line;
      }
      sites.push(site);
      continue;
    }

    let surface = null;
    let receiver = '';
    if (prev.v === '::' && /^\\?(?:Illuminate\\Support\\Facades\\)?DB$/.test(toks[i - 2] ? toks[i - 2].v : '')) {
      receiver = 'DB';
      if (CONNECTION_METHODS.has(t.v)) surface = 'statement';
      else if (t.v === 'raw') surface = 'expression';
    } else if ((prev.v === '->' || prev.v === '?->') && CONNECTION_METHODS.has(t.v)) {
      // Only on a connection: DB::connection('x')->select(...).
      if (toks[i - 2] && toks[i - 2].v === ')') {
        let k = i - 2;
        let depth = 0;
        for (; k >= 0; k--) {
          const v = toks[k].t === 'op' ? toks[k].v : null;
          if (v && CLOSE.has(v)) depth++;
          else if (v && OPEN.has(v)) {
            depth--;
            if (depth === 0) break;
          }
        }
        const callee = toks[k - 1];
        if (callee && callee.t === 'id' && /^(connection|getConnection|getPdo)$/.test(callee.v)) {
          surface = 'statement';
          receiver = 'connection';
        }
      }
    } else if ((prev.v === '->' || prev.v === '?->') && FRAGMENT_METHODS.has(t.v)) {
      surface = 'fragment';
    } else if ((prev.v === '->' || prev.v === '?->') && PDO_METHODS.has(t.v)) {
      const before = toks.slice(Math.max(0, i - 6), i - 1).map((x) => x.v).join('');
      receiver = before.slice(-30);
      // A handle named for PDO, or any handle given text that starts like SQL:
      // prepare, exec and query are not Laravel vocabulary, so a SQL-shaped
      // argument is enough.
      surface = /pdo/i.test(before) ? 'pdo' : 'pdo?';
    }
    if (!surface) continue;

    const args = splitArgs(toks, i + 1);
    if (!args.length) continue;
    if (surface === 'pdo?') {
      const probe = fold(toks, args[0][0], args[0][1], consts);
      const text = probe ? normaliseSql(probe.text) : '';
      if (!(LEADING_KEYWORD.test(text) && !AMBIGUOUS_LEADING_KEYWORD.test(text))) continue;
      surface = 'pdo';
    }
    // selectRaw and friends take the SQL first; DB::raw also takes it first.
    const [a, b] = args[0];
    const folded = fold(toks, a, b, consts);
    let sql;
    let source;
    let dynamic;
    if (folded) {
      sql = normaliseSql(folded.text);
      dynamic = folded.dynamic;
      source = dynamic ? 'partial' : 'literal';
    } else {
      const argText = toks.slice(a, b).map((x) => (x.t === 'str' ? JSON.stringify(x.v) : x.v)).join('');
      sql = `{{built at runtime: ${argText.slice(0, 120)}}}`;
      dynamic = true;
      source = 'variable';
    }

    const fn = innermost(funcs, i);
    const cls = innermost(classes, i);
    const site = {
      file: rel,
      line: t.line,
      class: cls ? cls.name : '',
      function: fn ? fn.name : '',
      receiver,
      method: t.v,
      surface,
      sqlSource: source,
      dynamic,
      args: Math.max(0, args.length - 1),
      goldenSql: sql,
    };
    if (surface === 'fragment') site.kind = fragmentKind(t.v);
    if (surface === 'expression') site.kind = 'EXPRESSION';
    classify(site);
    const kr = keepRawAt(lines, t.line);
    if (kr !== null) {
      site.annotation = kr.reason;
      site.annotationLine = kr.line;
    }
    // A portable expression is not a site unless a keep-raw marker says it is raw SQL.
    if (kr === null && isPortableExpression(site)) continue;
    if (surface === 'statement' && source !== 'variable') site.kind = statementKind(sql);
    sites.push(site);
  }
  return sites;
}

function fragmentKind(method) {
  if (method.startsWith('select')) return 'SELECT-LIST';
  if (/where/i.test(method)) return 'WHERE';
  if (/having/i.test(method)) return 'HAVING';
  if (/order/i.test(method)) return 'ORDER';
  if (/group/i.test(method)) return 'GROUP';
  if (/from/i.test(method)) return 'FROM';
  return 'FRAGMENT';
}
