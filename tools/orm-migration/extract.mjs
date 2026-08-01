#!/usr/bin/env node

/**
 * Raw SQL call-site extractor for the Freegle ORM migration programme.
 *
 * Scans iznik-server-go, iznik-batch, and iznik-routing-go for every raw SQL
 * call site and produces manifest.json — the machine-readable inventory that
 * drives the conversion programme and CI ratchet.
 *
 * Usage:  node tools/orm-migration/extract.mjs [--previous-manifest manifest.json]
 *
 * Plan: plans/database-migration-evaluation-2026-07.md Section 7.1
 */

import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { extname, join, relative, resolve } from 'node:path';
import { createHash } from 'node:crypto';

const ROOT = resolve(import.meta.dirname, '..', '..');

const SCANNERS = [
  { name: 'iznik-server-go', root: join(ROOT, 'iznik-server-go'), globs: ['**/*.go'],
    exclude: ['vendor/', '.git/', 'node_modules/'], type: 'go' },
  { name: 'iznik-batch', root: join(ROOT, 'iznik-batch'), globs: ['**/*.php'],
    exclude: ['vendor/', '.git/', 'node_modules/', 'storage/', 'bootstrap/cache/', 'database/migrations/'],
    type: 'php' },
  { name: 'iznik-routing-go', root: join(ROOT, 'iznik-routing-go'), globs: ['**/*.go'],
    exclude: ['vendor/', '.git/', 'node_modules/'], type: 'gorouting' },
];

const MYSQL_ISMS = [
  { name: 'ON DUPLICATE KEY UPDATE', re: /ON\s+DUPLICATE\s+KEY\s+UPDATE/i },
  { name: 'INSERT IGNORE', re: /INSERT\s+IGNORE/i },
  { name: 'REPLACE INTO', re: /REPLACE\s+INTO/i },
  { name: 'FORCE INDEX', re: /FORCE\s+INDEX/i },
  { name: 'USE INDEX', re: /USE\s+INDEX/i },
  { name: 'STRAIGHT_JOIN', re: /STRAIGHT_JOIN/i },
  { name: 'ST_Contains', re: /ST_Contains/i },
  { name: 'ST_Within', re: /ST_Within/i },
  { name: 'ST_Intersects', re: /ST_Intersects/i },
  { name: 'ST_Distance', re: /ST_Distance\b/i },
  { name: 'ST_GeomFromText', re: /ST_GeomFromText/i },
  { name: 'ST_AsText', re: /ST_AsText/i },
  { name: 'MBRContains', re: /MBRContains/i },
  { name: 'MBRWithin', re: /MBRWithin/i },
  { name: 'ANY_VALUE', re: /ANY_VALUE\s*\(/i },
  { name: 'DATE_FORMAT', re: /DATE_FORMAT\s*\(/i },
  { name: 'TIMESTAMPDIFF', re: /TIMESTAMPDIFF\s*\(/i },
  { name: 'DATEDIFF', re: /DATEDIFF\s*\(/i },
  { name: 'IFNULL', re: /IFNULL\s*\(/i },
  { name: 'JSON_EXTRACT', re: /JSON_EXTRACT\s*\(/i },
  { name: 'JSON_UNQUOTE', re: /JSON_UNQUOTE\s*\(/i },
  { name: 'JSON_CONTAINS', re: /JSON_CONTAINS\s*\(/i },
  { name: 'UNIX_TIMESTAMP', re: /UNIX_TIMESTAMP\s*\(/i },
  { name: 'FROM_UNIXTIME', re: /FROM_UNIXTIME\s*\(/i },
  { name: 'GROUP_CONCAT', re: /GROUP_CONCAT\s*\(/i },
  { name: 'LAST_INSERT_ID', re: /LAST_INSERT_ID\s*\(/i },
  { name: 'NOW()', re: /\bNOW\s*\(\s*\)/i },
  { name: 'CURDATE', re: /\bCURDATE\s*\(\s*\)/i },
  { name: 'COALESCE', re: /COALESCE\s*\(/i },
  { name: 'GREATEST', re: /GREATEST\s*\(/i },
  { name: 'backticks', re: /`[^`]+`/ },
  { name: 'FOR UPDATE', re: /\bFOR\s+UPDATE\b/i },
];

const TABLE_RE = /(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?/gi;

function extractTables(sql) {
  const tables = new Set();
  let m;
  while ((m = TABLE_RE.exec(sql)) !== null) tables.add(m[1].toLowerCase());
  return [...tables].sort();
}

function classifyComplexity(sql) {
  const upper = sql.toUpperCase();
  const joins = (upper.match(/\bJOIN\b/g) || []).length;
  const subs = (upper.match(/\(\s*SELECT\b/g) || []).length;
  const unions = (upper.match(/\bUNION\b/g) || []).length;
  const ctes = (upper.match(/\bWITH\b/g) || []).length;
  const score = joins * 2 + subs * 3 + unions * 3 + ctes * 3;
  if (score === 0) return 'simple';
  if (score <= 3) return 'moderate';
  return 'complex';
}

function classifyKind(sql) {
  const upper = sql.trim().toUpperCase();
  if (/^\s*SELECT\b/.test(upper)) return 'SELECT';
  if (/^\s*INSERT\b/.test(upper)) return 'INSERT';
  if (/^\s*UPDATE\b/.test(upper)) return 'UPDATE';
  if (/^\s*DELETE\b/.test(upper)) return 'DELETE';
  if (/^\s*REPLACE\b/.test(upper)) return 'REPLACE';
  if (/^\s*WITH\b/.test(upper)) return 'CTE';
  if (/^\s*CREATE\b/.test(upper)) return 'CREATE';
  if (/^\s*ALTER\b/.test(upper)) return 'ALTER';
  if (/^\s*DROP\b/.test(upper)) return 'DROP';
  return 'OTHER';
}

function stableId(project, file, fn, sql) {
  const n = sql.replace(/\s+/g, ' ').replace(/'[^']*'/g, '?').replace(/"[^"]*"/g, '?').trim().toLowerCase();
  return createHash('sha256').update(`${project}:${file}:${fn}:${n}`).digest('hex').slice(0, 12);
}

function detectIsms(sql) {
  return MYSQL_ISMS.filter(({ re }) => re.test(sql)).map(({ name }) => name);
}

// ─── file walker ─────────────────────────────────────────────────────────────

function* walkFiles(dir, exclude, globs) {
  const extSet = new Set(globs.map(g => g.replace('**/*', '')));
  const stack = [dir];
  while (stack.length) {
    const current = stack.pop();
    let entries;
    try { entries = readdirSync(current, { withFileTypes: true }); } catch { continue; }
    for (const ent of entries) {
      const full = join(current, ent.name);
      const relPath = relative(dir, full);
      if (exclude.some(e => relPath.startsWith(e) || relPath.includes('/' + e))) continue;
      if (ent.isDirectory()) { stack.push(full); }
      else if (ent.isFile() && extSet.has(extname(ent.name))) { yield full; }
    }
  }
}

// ─── Go scanner ──────────────────────────────────────────────────────────────

function scanGoFile(filePath, projectRoot, isRouting) {
  const content = readFileSync(filePath, 'utf8');
  const rel = relative(projectRoot, filePath);
  const sites = [];

  // .Raw(`...`) and .Exec(`...`) — first string arg is SQL
  const rawRe = /\.(\w*)Raw\s*\(\s*(`(?:[^`\\]|\\.)*`|"(?:[^"\\]|\\.)*")/gs;
  const execRe = /\.(\w*)Exec\s*\(\s*(`(?:[^`\\]|\\.)*`|"(?:[^"\\]|\\.)*")/gs;

  for (const [regex, method] of [[rawRe, 'Raw'], [execRe, 'Exec']]) {
    let m;
    while ((m = regex.exec(content)) !== null) {
      const receiver = m[1] || 'db';
      const sql = extractGoSql(m[2]);
      if (!sql || isCommentOrLog(content, m.index)) continue;
      const line = content.slice(0, m.index).split('\n').length;
      const fn = findGoFunc(content, m.index);
      sites.push({
        file: rel, line, function: fn,
        kind: classifyKind(sql), method, receiver,
        sql: trunc(sql), tables: extractTables(sql),
        mysqlIsms: detectIsms(sql), complexity: classifyComplexity(sql),
      });
    }
  }

  // routing-go: also catch database/sql Query/QueryRow/Prepare
  if (isRouting) {
    const dbRe = /\.(\w*)(Query|QueryRow|Prepare)\s*\(\s*(`(?:[^`\\]|\\.)*`|"(?:[^"\\]|\\.)*")/gs;
    let dm;
    while ((dm = dbRe.exec(content)) !== null) {
      const sql = extractGoSql(dm[3]);
      if (!sql || isCommentOrLog(content, dm.index)) continue;
      const line = content.slice(0, dm.index).split('\n').length;
      const fn = findGoFunc(content, dm.index);
      sites.push({
        file: rel, line, function: fn,
        kind: classifyKind(sql), method: dm[2], receiver: dm[1] || 'db',
        sql: trunc(sql), tables: extractTables(sql),
        mysqlIsms: detectIsms(sql), complexity: classifyComplexity(sql),
      });
    }
  }

  return sites;
}

function extractGoSql(arg) {
  if (arg.startsWith('`')) return arg.slice(1, -1);
  if (arg.startsWith('"')) return arg.slice(1, -1).replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\\\/g, '\\');
  return null;
}

function findGoFunc(content, pos) {
  const lines = content.slice(0, pos).split('\n');
  for (let i = lines.length - 1; i >= 0; i--) {
    const m = lines[i].trim().match(/^func\s+(?:\(\w+\s+\*?\w+\)\s+)?(\w+)\s*\(/);
    if (m) return m[1];
  }
  return '<top>';
}

// ─── PHP scanner ─────────────────────────────────────────────────────────────

function scanPhpFile(filePath, projectRoot) {
  const content = readFileSync(filePath, 'utf8');
  const rel = relative(projectRoot, filePath);
  const sites = [];

  // DB::select / statement / insert / update / delete / raw / selectOne / unprepared / affectingStatement
  const methods = ['select', 'statement', 'insert', 'update', 'delete', 'raw', 'selectOne', 'unprepared', 'affectingStatement'];
  const facadeRe = new RegExp(`DB\\s*::\\s*(${methods.join('|')})\\s*\\(\\s*(.+?)\\s*\\)\\s*;`, 'gs');

  let m;
  while ((m = facadeRe.exec(content)) !== null) {
    const sql = extractPhpSql(m[2]);
    if (!sql || isCommentOrLog(content, m.index)) continue;
    const line = content.slice(0, m.index).split('\n').length;
    const fn = findPhpFunc(content, m.index);
    sites.push({
      file: rel, line, function: fn,
      kind: classifyKind(sql), method: 'DB::' + m[1], receiver: 'DB',
      sql: trunc(sql), tables: extractTables(sql),
      mysqlIsms: detectIsms(sql), complexity: classifyComplexity(sql),
    });
  }

  // ->whereRaw / selectRaw / orderByRaw / havingRaw / orWhereRaw / groupByRaw
  const builderMethods = ['whereRaw', 'selectRaw', 'orderByRaw', 'havingRaw', 'orWhereRaw', 'groupByRaw'];
  const builderRe = new RegExp(`->\\s*(${builderMethods.join('|')})\\s*\\(\\s*(.+?)\\s*\\)`, 'gs');

  let bm;
  while ((bm = builderRe.exec(content)) !== null) {
    const sql = extractPhpSql(bm[2]);
    if (!sql || isCommentOrLog(content, bm.index)) continue;
    const line = content.slice(0, bm.index).split('\n').length;
    const fn = findPhpFunc(content, bm.index);
    sites.push({
      file: rel, line, function: fn,
      kind: 'FRAGMENT', method: bm[1], receiver: 'Builder',
      sql: trunc(sql), tables: extractTables(sql),
      mysqlIsms: detectIsms(sql), complexity: 'simple',
    });
  }

  return sites;
}

function extractPhpSql(args) {
  let m = args.match(/^\s*'((?:[^'\\]|\\.)*)'/s);
  if (m) return m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
  m = args.match(/^\s*"((?:[^"\\]|\\.)*)"/s);
  if (m) return m[1];
  m = args.match(/<<<\s*(['"]?)(\w+)\1\s*\n(.*?)\n\s*\2/s);
  if (m) return m[3];
  if (args.includes('$') || args.includes('<<<')) return '<<DYNAMIC>>';
  return null;
}

function findPhpFunc(content, pos) {
  const lines = content.slice(0, pos).split('\n');
  for (let i = lines.length - 1; i >= 0; i--) {
    let m = lines[i].trim().match(/function\s+(\w+)\s*\(/);
    if (m) return m[1];
    m = lines[i].trim().match(/class\s+(\w+)/);
    if (m) return m[1] + '::<method>';
  }
  return '<top>';
}

// ─── helpers ─────────────────────────────────────────────────────────────────

function isCommentOrLog(content, pos) {
  const lineStart = content.lastIndexOf('\n', pos) + 1;
  const line = content.slice(lineStart, content.indexOf('\n', pos));
  const t = line.trim();
  return t.startsWith('//') || t.startsWith('/*') || t.startsWith('*') ||
    t.startsWith('#') || t.includes('log.') || t.includes('Log.') ||
    t.includes('.Info(') || t.includes('.Debug(') || t.includes('logger.');
}

function trunc(sql, max = 500) {
  const s = sql.replace(/\s+/g, ' ').trim();
  return s.length <= max ? s : s.slice(0, max) + '...';
}

// ─── main ────────────────────────────────────────────────────────────────────

function main() {
  const prevIdx = process.argv.indexOf('--previous-manifest');
  const prevPath = prevIdx >= 0 ? process.argv[prevIdx + 1] : null;
  let prev = null;
  if (prevPath) {
    try { prev = JSON.parse(readFileSync(prevPath, 'utf8')); } catch { /* fresh */ }
  }

  const all = [];
  const stats = {};

  for (const sc of SCANNERS) {
    console.error(`Scanning ${sc.name}...`);
    let count = 0;
    const seen = new Set();
    const scanFn = sc.type === 'php' ? scanPhpFile : (f, r) => scanGoFile(f, r, sc.type === 'gorouting');

    for (const fp of walkFiles(sc.root, sc.exclude, sc.globs)) {
      for (const site of scanFn(fp, sc.root)) {
        const dk = `${site.file}:${site.line}:${site.sql}`;
        if (seen.has(dk)) continue;
        seen.add(dk);
        const id = stableId(sc.name, site.file, site.function, site.sql);
        const isTest = site.file.includes('_test.') || site.file.includes('/test/') || site.file.includes('/tests/');
        let status = isTest ? 'test-fixture' : 'raw';
        if (prev?.entries) {
          const pe = prev.entries.find(e => e.id === id);
          if (pe && pe.status !== 'test-fixture') status = pe.status;
        }
        const entry = { id, project: sc.name, ...site, status };
        if (prev?.entries) {
          const pe = prev.entries.find(e => e.id === id);
          if (pe?.reason) entry.reason = pe.reason;
        }
        all.push(entry);
        count++;
      }
    }
    stats[sc.name] = count;
    console.error(`  → ${count} sites`);
  }

  const byStatus = {}, byComplexity = { simple: 0, moderate: 0, complex: 0, FRAGMENT: 0 }, ismCounts = {};
  for (const s of all) {
    byStatus[s.status] = (byStatus[s.status] || 0) + 1;
    byComplexity[s.kind === 'FRAGMENT' ? 'FRAGMENT' : s.complexity] =
      (byComplexity[s.kind === 'FRAGMENT' ? 'FRAGMENT' : s.complexity] || 0) + 1;
    for (const ism of s.mysqlIsms) ismCounts[ism] = (ismCounts[ism] || 0) + 1;
  }

  const manifest = {
    $schema: 'tools/orm-migration/manifest.schema.json',
    generated: new Date().toISOString(),
    summary: {
      total: all.length,
      byStatus, byComplexity, byProject: stats,
      testFixtures: all.filter(s => s.status === 'test-fixture').length,
      rawProduction: all.filter(s => s.status === 'raw').length,
      dynamicSql: all.filter(s => s.sql === '<<DYNAMIC>>').length,
      topMysqlIsms: Object.entries(ismCounts).sort((a, b) => b[1] - a[1]).slice(0, 25)
        .reduce((acc, [k, v]) => ({ ...acc, [k]: v }), {}),
    },
    entries: all,
  };

  const out = join(ROOT, 'tools', 'orm-migration', 'manifest.json');
  writeFileSync(out, JSON.stringify(manifest, null, 2) + '\n');
  console.error(`\nWrote ${all.length} entries → tools/orm-migration/manifest.json`);
  console.error(`  Production raw: ${manifest.summary.rawProduction}  |  Test: ${manifest.summary.testFixtures}  |  Dynamic: ${manifest.summary.dynamicSql}`);
}

main();
