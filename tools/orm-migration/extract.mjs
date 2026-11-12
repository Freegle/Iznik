#!/usr/bin/env node
// Raw SQL inventory and ratchet (plans/database-migration-evaluation-2026-07.md
// section 7.1). See tools/orm-migration/README.md.
//
//   node tools/orm-migration/extract.mjs update [--raise-baseline]
//   node tools/orm-migration/extract.mjs check [--base REF]
//   node tools/orm-migration/extract.mjs summary
//   node tools/orm-migration/extract.mjs list [--status raw] [--file TEXT]
//   node tools/orm-migration/extract.mjs orphans

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  scanRepo, loadRules, buildManifest, readManifest, serialise, checkManifest, openCount,
} from './lib/inventory.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));

function parseArgs(argv) {
  const opts = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const key = a.slice(2);
      if (['raise-baseline'].includes(key)) opts[key] = true;
      else opts[key] = argv[++i];
    } else opts._.push(a);
  }
  return opts;
}

function baseManifest(repoRoot, ref, manifestRel) {
  try {
    const text = execFileSync('git', ['show', `${ref}:${manifestRel}`], {
      cwd: repoRoot, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'],
    });
    return JSON.parse(text);
  } catch {
    return null;
  }
}

export function main(argv, out = console) {
  const opts = parseArgs(argv);
  const cmd = opts._[0] || 'summary';
  const repoRoot = path.resolve(opts.root || path.join(here, '..', '..'));
  const manifestPath = path.resolve(opts.manifest || path.join(repoRoot, 'tools/orm-migration/manifest.json'));
  const rulesPath = path.resolve(opts.rules || path.join(repoRoot, 'tools/orm-migration/rules.json'));
  const rules = loadRules(rulesPath);
  const scan = scanRepo(repoRoot);
  const committed = readManifest(manifestPath);

  if (cmd === 'update') {
    const { manifest, refused } = buildManifest(scan, committed, rules, { raiseBaseline: !!opts['raise-baseline'] });
    if (refused) {
      out.error(
        `Refusing to write: the code has ${refused.open} raw plus in-progress sites and the baseline is ${refused.baseline}.\n` +
          'New raw SQL needs a "keep-raw: <reason>" comment above it, which makes it a keep-raw entry.\n' +
          'If the rise is deliberate and reviewed, run update again with --raise-baseline.',
      );
      return 1;
    }
    fs.writeFileSync(manifestPath, serialise(manifest));
    out.log(`${manifest.sites.length} entries written to ${path.relative(repoRoot, manifestPath)}`);
    out.log(`status counts: ${JSON.stringify(manifest.counts)}; baseline ${manifest.ratchet.baseline}`);
    return 0;
  }

  if (cmd === 'check') {
    let base = null;
    if (opts.base) base = baseManifest(repoRoot, opts.base, path.relative(repoRoot, manifestPath).split(path.sep).join('/'));
    const { failures, warnings } = checkManifest(scan, committed, rules, { baseManifest: base });
    for (const w of warnings) out.log(`RAW-SQL-RATCHET: warning: ${w}`);
    if (failures.length) {
      out.error(`RAW-SQL-RATCHET: ${failures.length} problem(s)`);
      for (const f of failures.slice(0, 60)) out.error(`  ${f}`);
      if (failures.length > 60) out.error(`  ... and ${failures.length - 60} more`);
      out.error(
        '\nTo fix: give new raw SQL a "keep-raw: <reason>" comment (the same escape hatch check-raw-sql uses) or convert it,\n' +
          'then run: node tools/orm-migration/extract.mjs update   and commit tools/orm-migration/manifest.json.',
      );
      return 1;
    }
    out.log(`RAW-SQL-RATCHET: ok (${scan.sites.length} production sites, ${openCount(committed.sites)} raw or in-progress, baseline ${committed.ratchet.baseline})`);
    return 0;
  }

  if (cmd === 'list') {
    const sites = (committed || buildManifest(scan, null, rules).manifest).sites.filter(
      (s) => (!opts.status || s.status === opts.status) && (!opts.file || s.file.includes(opts.file)),
    );
    for (const s of sites) out.log(`${s.id}  ${s.status.padEnd(11)} ${s.file}:${s.line}  [${s.surface}] ${s.goldenSql.slice(0, 100)}`);
    return 0;
  }

  if (cmd === 'orphans') {
    for (const o of scan.orphanAnnotations) out.log(`${o.file}:${o.line}  ${o.reason}`);
    out.log(`${scan.orphanAnnotations.length} keep-raw markers have no site`);
    return 0;
  }

  if (cmd === 'summary') {
    const { manifest } = buildManifest(scan, committed, rules);
    out.log(JSON.stringify({ counts: manifest.counts, baseline: manifest.ratchet.baseline, testFixtures: manifest.testFixtures.total, keepRawMarkersWithoutSite: scan.orphanAnnotations.length }, null, 2));
    return 0;
  }

  out.error(`unknown command ${cmd}`);
  return 2;
}

if (process.argv[1] && fs.realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
  process.exitCode = main(process.argv.slice(2));
}
