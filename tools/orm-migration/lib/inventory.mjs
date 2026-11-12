// Builds the raw SQL inventory for the whole repository and reconciles it with
// the committed manifest.

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { scanGoSource, collectConsts, tokeniseGo, KEEP_RAW_RE } from './go-scan.mjs';
import { scanPhpSource } from './php-scan.mjs';

// Production code is scanned in these trees. Each entry says which scanner
// reads it and which directories hold fixtures rather than production code.
export const TREES = [
  { root: 'iznik-server-go', lang: 'go' },
  { root: 'iznik-routing-go', lang: 'go' },
  { root: 'iznik-spatial-go', lang: 'go' },
  { root: 'iznik-batch', lang: 'php' },
];

const SKIP_DIRS = new Set(['vendor', 'node_modules', '.git', 'storage', '.idea']);

export function isTestPath(rel, lang) {
  if (lang === 'go') return rel.endsWith('_test.go') || /(^|\/)test\//.test(rel);
  return /^iznik-batch\/tests\//.test(rel);
}

function* walk(dir, base) {
  let entries;
  try {
    entries = fs.readdirSync(path.join(base, dir), { withFileTypes: true });
  } catch {
    return;
  }
  entries.sort((a, b) => (a.name < b.name ? -1 : 1));
  for (const e of entries) {
    const rel = dir ? `${dir}/${e.name}` : e.name;
    if (e.isDirectory()) {
      if (SKIP_DIRS.has(e.name) || rel === 'iznik-batch/bootstrap/cache') continue;
      yield* walk(rel, base);
    } else if (e.isFile()) yield rel;
  }
}

function makeId(file, sql, occurrence) {
  return crypto.createHash('sha256').update(`${file}\0${sql}\0${occurrence}`).digest('hex').slice(0, 12);
}

const HEX_ID = /['"`]([0-9a-f]{12})['"`]/g;

/**
 * Scans the repository.
 * @returns {{sites: object[], testFixtures: object, testIds: Set<string>, orphanAnnotations: object[]}}
 */
export function scanRepo(repoRoot) {
  const sites = [];
  const testFiles = {};
  const testIds = new Set();
  const orphanAnnotations = [];

  for (const tree of TREES) {
    const ext = tree.lang === 'go' ? '.go' : '.php';
    const files = [...walk(tree.root, repoRoot)].filter((f) => f.endsWith(ext));

    // Package-level constants, per directory and exported across packages.
    const consts = new Map();
    const crossConsts = new Map();
    if (tree.lang === 'go') {
      for (const f of files) {
        const dir = path.posix.dirname(f);
        const { toks } = tokeniseGo(fs.readFileSync(path.join(repoRoot, f), 'utf8'));
        const found = collectConsts(toks);
        if (!consts.has(dir)) consts.set(dir, new Map());
        for (const [k, v] of found) {
          consts.get(dir).set(k, v);
          if (/^[A-Z]/.test(k)) crossConsts.set(`${path.posix.basename(dir)}.${k}`, v);
        }
      }
    }

    for (const rel of files) {
      const src = fs.readFileSync(path.join(repoRoot, rel), 'utf8');
      const isTest = isTestPath(rel, tree.lang);
      if (isTest) {
        let m;
        HEX_ID.lastIndex = 0;
        while ((m = HEX_ID.exec(src)) !== null) testIds.add(m[1]);
      }
      const found =
        tree.lang === 'go'
          ? scanGoSource(src, rel, { consts: consts.get(path.posix.dirname(rel)), crossConsts })
          : scanPhpSource(src, rel);
      if (isTest) {
        if (found.length) testFiles[rel] = found.length;
        continue;
      }
      // A keep-raw marker that no site picked up: the code under it is not something
      // the scanner treats as raw SQL, so the marker is not in the manifest.
      const used = new Set(found.filter((s) => s.annotationLine).map((s) => s.annotationLine));
      src.split('\n').forEach((text, idx) => {
        const m = KEEP_RAW_RE.exec(text);
        if (m && !used.has(idx + 1)) orphanAnnotations.push({ file: rel, line: idx + 1, reason: m[1].trim().slice(0, 100) });
      });
      const seen = new Map();
      for (const s of found) {
        const key = `${s.file}\0${s.goldenSql}`;
        const n = seen.get(key) || 0;
        seen.set(key, n + 1);
        s.id = makeId(s.file, s.goldenSql, n);
        sites.push(s);
      }
    }
  }

  sites.sort((a, b) => (a.file < b.file ? -1 : a.file > b.file ? 1 : a.line - b.line));
  const total = Object.values(testFiles).reduce((a, b) => a + b, 0);
  return { sites, testFixtures: { total, files: testFiles }, testIds, orphanAnnotations };
}

// ------------------------------------------------------------------- rules

export function loadRules(rulesPath) {
  if (!fs.existsSync(rulesPath)) return [];
  const rules = JSON.parse(fs.readFileSync(rulesPath, 'utf8')).rules || [];
  rules.forEach((r, i) => {
    if (!r.file && !r.id) throw new Error(`${rulesPath}: rule ${i} names no file or id`);
    if (!r.reason || !r.reason.trim()) throw new Error(`${rulesPath}: rule ${i} (${r.file || r.id}) has no reason`);
    if (!['keep-raw', 'excluded'].includes(r.status || 'keep-raw')) {
      throw new Error(`${rulesPath}: rule ${i} has status ${r.status}; rules may only set keep-raw or excluded`);
    }
  });
  return rules;
}

function ruleFor(site, rules) {
  return rules.find((r) => {
    if (r.id) return r.id === site.id;
    const fileOk = site.file === r.file || (r.file.endsWith('/') && site.file.startsWith(r.file));
    return fileOk && (!r.function || r.function === site.function) && (!r.surface || r.surface === site.surface);
  });
}

// -------------------------------------------------------------------- merge

const CARRIED_FIELDS = ['porting', 'category', 'hot', 'approvedDiff'];

function locationKey(s) {
  return [s.file, s.class || '', s.function || '', s.surface, s.method].join('|');
}

/**
 * Reconciles freshly scanned sites with the previous manifest.
 *
 * A site's status is worked out from the code first (a rule, then an inline
 * "keep-raw:" annotation). A reviewer's decision recorded only in the manifest
 * (keep-raw, in-progress) is carried forward by id, and by location when the
 * statement's text changed but it is plainly the same site, so editing a
 * deliberate query needs a regenerate and not a new justification.
 */
export function reconcile(scan, previous, rules) {
  const prevSites = (previous && previous.sites) || [];
  const byId = new Map(prevSites.map((s) => [s.id, s]));
  const currentIds = new Set(scan.sites.map((s) => s.id));

  // Unmatched previous entries, grouped by location, for the text-changed case.
  const orphans = new Map();
  for (const p of prevSites) {
    if (currentIds.has(p.id) || p.presentInCode === false) continue;
    const k = locationKey(p);
    if (!orphans.has(k)) orphans.set(k, []);
    orphans.get(k).push(p);
  }
  const newByLocation = new Map();
  for (const s of scan.sites) {
    if (byId.has(s.id)) continue;
    const k = locationKey(s);
    if (!newByLocation.has(k)) newByLocation.set(k, []);
    newByLocation.get(k).push(s);
  }

  const claimed = new Set();
  const out = [];
  for (const s of scan.sites) {
    let prior = byId.get(s.id);
    if (!prior) {
      const k = locationKey(s);
      const o = orphans.get(k);
      const n = newByLocation.get(k);
      if (o && n && o.length === n.length) {
        prior = o[n.indexOf(s)];
        claimed.add(prior.id);
      }
    }
    const site = { ...s };
    delete site.annotation;
    delete site.annotationLine;
    const rule = ruleFor(s, rules);
    if (rule) {
      site.status = rule.status || 'keep-raw';
      site.reason = rule.reason;
      site.reasonSource = 'rule';
      for (const f of ['porting', 'category']) if (rule[f]) site[f] = rule[f];
    } else if (s.annotation !== undefined) {
      site.status = 'keep-raw';
      site.reason = s.annotation || 'No reason written after the keep-raw marker.';
      site.reasonSource = 'annotation';
    } else if (
      prior &&
      (prior.status === 'in-progress' ||
        (prior.status === 'keep-raw' && prior.reasonSource !== 'annotation' && prior.reasonSource !== 'rule'))
    ) {
      site.status = prior.status;
      if (prior.reason) site.reason = prior.reason;
      if (prior.reasonSource) site.reasonSource = prior.reasonSource;
    } else {
      site.status = 'raw';
    }
    if (prior) for (const f of CARRIED_FIELDS) if (prior[f] !== undefined && site[f] === undefined) site[f] = prior[f];
    out.push(site);
  }

  // Entries whose site is no longer in the code.
  for (const p of prevSites) {
    if (currentIds.has(p.id) || claimed.has(p.id)) continue;
    const gone = { ...p, presentInCode: false };
    if (p.status === 'converted' || p.status === 'retired') {
      // keep as recorded
    } else if (scan.testIds.has(p.id)) {
      gone.status = 'converted';
      delete gone.reason;
      delete gone.reasonSource;
    } else {
      gone.status = 'retired';
      gone.reason = 'No longer in the code and no parity test names it: deleted, or rewritten with new text.';
      delete gone.reasonSource;
    }
    out.push(gone);
  }

  out.sort((a, b) => (a.file < b.file ? -1 : a.file > b.file ? 1 : a.line - b.line));
  return out;
}

export function countStatuses(sites) {
  const counts = {};
  for (const s of sites) counts[s.status] = (counts[s.status] || 0) + 1;
  return Object.fromEntries(Object.entries(counts).sort());
}

export function openCount(sites) {
  return sites.filter((s) => s.presentInCode !== false && (s.status === 'raw' || s.status === 'in-progress')).length;
}

export function buildManifest(scan, previous, rules, { raiseBaseline = false } = {}) {
  const sites = reconcile(scan, previous, rules);
  const open = openCount(sites);
  const old = previous && previous.ratchet && Number.isInteger(previous.ratchet.baseline) ? previous.ratchet.baseline : null;
  let baseline = old === null ? open : Math.min(old, open);
  let refused = null;
  if (old !== null && open > old) {
    if (raiseBaseline) baseline = open;
    else refused = { open, baseline: old };
  }
  return {
    manifest: {
      version: 1,
      ratchet: { baseline },
      counts: countStatuses(sites),
      testFixtures: scan.testFixtures,
      sites,
    },
    refused,
  };
}

// ---------------------------------------------------------------- file format

const SITE_FIELD_ORDER = [
  'id', 'file', 'line', 'class', 'function', 'receiver', 'method', 'surface', 'sqlSource', 'kind', 'tables',
  'mysqlisms', 'complexity', 'wave', 'dynamic', 'args', 'goldenSql', 'presentInCode', 'status', 'reason',
  'reasonSource', 'porting', 'category', 'hot', 'approvedDiff',
];

function orderedSite(s) {
  const o = {};
  for (const k of SITE_FIELD_ORDER) {
    if (s[k] === undefined || s[k] === '' || (k === 'class' && !s[k])) continue;
    o[k] = s[k];
  }
  return o;
}

// One site per line, so a manifest diff reads as one changed line per site.
export function serialise(m) {
  const head = {
    version: m.version,
    ratchet: m.ratchet,
    counts: m.counts,
    testFixtures: m.testFixtures,
  };
  const headText = JSON.stringify(head, null, 2).replace(/\n}$/, '');
  const lines = m.sites.map((s) => '    ' + JSON.stringify(orderedSite(s)));
  return `${headText},\n  "sites": [\n${lines.join(',\n')}\n  ]\n}\n`;
}

export function readManifest(file) {
  if (!fs.existsSync(file)) return null;
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

// -------------------------------------------------------------------- check

// The comparable form of an entry: everything but the line number, which
// drifts with every unrelated edit above the site.
function comparable(s) {
  const o = orderedSite(s);
  delete o.line;
  return JSON.stringify(o);
}

/**
 * The ratchet. Returns a list of failures (empty when the committed manifest
 * is exactly what the code and rules produce) and warnings.
 */
export function checkManifest(scan, committed, rules, { baseManifest = null } = {}) {
  const failures = [];
  const warnings = [];
  if (!committed) {
    return { failures: ['There is no manifest. Run: node tools/orm-migration/extract.mjs update'], warnings };
  }

  const { manifest: expected } = buildManifest(scan, committed, rules);
  const have = new Map(committed.sites.map((s) => [s.id, s]));
  const want = new Map(expected.sites.map((s) => [s.id, s]));

  const loc = (s) => `${s.file}:${s.line}${s.function ? ` (${s.class ? s.class + '::' : ''}${s.function})` : ''}`;
  const brief = (s) => s.goldenSql.slice(0, 110);

  // (a) raw SQL in the code that the manifest does not know about.
  for (const [id, s] of want) {
    if (s.presentInCode === false) continue;
    const c = have.get(id);
    if (!c || c.presentInCode === false) {
      failures.push(`NEW raw SQL with no manifest entry: ${loc(s)} [${s.surface}] ${brief(s)}`);
    }
  }
  // (b) entries whose SQL was changed or removed without the manifest following.
  for (const [id, c] of have) {
    if (c.presentInCode === false) continue;
    if (!want.has(id) || want.get(id).presentInCode === false) {
      failures.push(`manifest entry ${id} no longer matches the code (SQL changed or removed): ${loc(c)} ${brief(c)}`);
    }
  }
  // (c) recorded golden SQL, status or reason drifted from what the code says.
  for (const [id, w] of want) {
    const c = have.get(id);
    if (!c || w.presentInCode === false || c.presentInCode === false) continue;
    if (comparable(c) !== comparable(w)) {
      const what = ['goldenSql', 'status', 'reason', 'function', 'kind', 'surface'].filter((k) => c[k] !== w[k]);
      failures.push(`manifest entry ${id} is out of date (${what.join(', ') || 'derived fields'}): ${loc(w)}`);
    }
  }
  // (d) keep-raw and excluded need a written reason.
  for (const c of committed.sites) {
    if ((c.status === 'keep-raw' || c.status === 'excluded') && !(c.reason && c.reason.trim())) {
      failures.push(`${c.status} entry ${c.id} has no reason: ${loc(c)}`);
    }
  }
  // (e) converted needs a parity test bearing the id.
  for (const c of committed.sites) {
    if (c.status === 'converted' && !scan.testIds.has(c.id)) {
      failures.push(`converted entry ${c.id} has no parity test naming it: ${loc(c)}`);
    }
  }
  // (f) the count of open sites must not rise, and must be recorded tightly.
  const open = openCount(expected.sites);
  const baseline = committed.ratchet && committed.ratchet.baseline;
  if (!Number.isInteger(baseline)) failures.push('manifest has no ratchet.baseline');
  else {
    if (open > baseline) failures.push(`raw plus in-progress sites rose to ${open}, above the baseline of ${baseline}`);
    if (open < baseline) failures.push(`baseline ${baseline} is stale, the code has ${open} raw plus in-progress sites: run update to lower it`);
  }
  // (g) the baseline never rises against the target branch.
  if (baseManifest && baseManifest.ratchet && Number.isInteger(baseManifest.ratchet.baseline) && Number.isInteger(baseline)) {
    if (baseline > baseManifest.ratchet.baseline) {
      failures.push(`baseline rose from ${baseManifest.ratchet.baseline} on the base branch to ${baseline}. New raw SQL needs a "keep-raw:" reason, not a higher baseline.`);
    }
  }
  // (h) keep-raw markers that no site picked up are not inventoried.
  if (scan.orphanAnnotations && scan.orphanAnnotations.length) {
    warnings.push(
      `${scan.orphanAnnotations.length} keep-raw markers sit on code the extractor does not treat as raw SQL, so they are not in the manifest (list them with: extract.mjs orphans).`,
    );
  }
  // (i) a retired entry carries a reason; surface the ones the tool wrote itself.
  const autoRetired = committed.sites.filter((s) => s.status === 'retired' && /^No longer in the code/.test(s.reason || ''));
  if (autoRetired.length) warnings.push(`${autoRetired.length} retired entries have the generic reason; replace it where the removal was deliberate.`);

  return { failures, warnings, expected };
}
