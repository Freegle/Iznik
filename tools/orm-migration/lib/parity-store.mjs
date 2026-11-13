// The golden stores the parity harness reads.
//
// The Go tests run in a container that holds only iznik-server-go and the Laravel tests in one that
// holds only iznik-batch, so neither can read tools/orm-migration/manifest.json. Each stack gets a
// copy of the part of the manifest its harness needs, inside its own tree. The copies are generated
// by `extract.mjs update` and `extract.mjs check` fails when one is stale, so they cannot drift.

import fs from 'node:fs';
import path from 'node:path';

const STATUSES = new Set(['raw', 'in-progress', 'converted', 'keep-raw']);

export const STORES = [
  {
    path: 'iznik-server-go/parity/goldens.json',
    include: (s) => s.file.startsWith('iznik-server-go/'),
  },
  {
    path: 'iznik-batch/tests/fixtures/orm-parity/goldens.json',
    // Migrations are frozen history with no parity test to write.
    include: (s) => s.file.startsWith('iznik-batch/') && !s.file.startsWith('iznik-batch/database/migrations/'),
  },
];

function entry(s) {
  const o = { file: s.file, function: s.function || '', surface: s.surface, status: s.status, goldenSql: s.goldenSql };
  if (s.approvedDiff) o.approvedDiff = s.approvedDiff;
  return o;
}

/**
 * @param {object[]} sites the manifest's sites
 * @param {{include: Function}} store
 * @returns {string} the file text: one site per line, so a diff reads as one line per change
 */
export function storeText(sites, store) {
  const picked = sites
    .filter((s) => STATUSES.has(s.status) && store.include(s))
    .sort((a, b) => (a.file < b.file ? -1 : a.file > b.file ? 1 : a.id < b.id ? -1 : 1));
  const lines = picked.map((s) => `    ${JSON.stringify(s.id)}: ${JSON.stringify(entry(s))}`);
  return (
    '{\n  "generatedBy": "node tools/orm-migration/extract.mjs update. Do not edit.",\n  "sites": {\n' +
    lines.join(',\n') +
    '\n  }\n}\n'
  );
}

export function writeStores(repoRoot, sites) {
  const written = [];
  for (const store of STORES) {
    const file = path.join(repoRoot, store.path);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, storeText(sites, store));
    written.push(store.path);
  }
  return written;
}

/** Failures for any store that is missing or differs from what the manifest gives. */
export function checkStores(repoRoot, sites) {
  const failures = [];
  for (const store of STORES) {
    const file = path.join(repoRoot, store.path);
    const want = storeText(sites, store);
    let have = null;
    try {
      have = fs.readFileSync(file, 'utf8');
    } catch {
      // reported below
    }
    if (have !== want) {
      failures.push(`${store.path} is ${have === null ? 'missing' : 'out of date'}: run node tools/orm-migration/extract.mjs update`);
    }
  }
  return failures;
}

/** A recorded approved diff must say what the converted code renders and why that is the same statement. */
export function checkApprovedDiffs(sites) {
  const failures = [];
  for (const s of sites) {
    if (s.approvedDiff === undefined) continue;
    const d = s.approvedDiff;
    const ok = d && typeof d === 'object' && typeof d.sql === 'string' && d.sql.trim() && typeof d.reason === 'string' && d.reason.trim();
    if (!ok) failures.push(`entry ${s.id} has an approvedDiff without both "sql" and "reason": ${s.file}:${s.line}`);
  }
  return failures;
}

export const CORPUS_COPIES = [
  'iznik-server-go/parity/canonical-corpus.json',
  'iznik-batch/tests/Support/OrmHarness/canonical-corpus.json',
];

/** The Go and PHP canonicalisers check themselves against one corpus, so the two copies must be the same bytes. */
export function checkCorpusCopies(repoRoot) {
  const texts = CORPUS_COPIES.map((p) => {
    try {
      return fs.readFileSync(path.join(repoRoot, p), 'utf8');
    } catch {
      return null;
    }
  });
  if (texts.every((t) => t === null)) return []; // a tree with neither harness
  if (texts.some((t) => t === null) || texts[0] !== texts[1]) {
    return [`the canonicaliser corpus copies differ or one is missing: ${CORPUS_COPIES.join(' and ')} must be byte-identical`];
  }
  return [];
}
