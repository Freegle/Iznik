#!/usr/bin/env node
// One-command pipeline for a PR walkthrough.
//
//   node pr-walkthrough.mjs <pr> [--repo owner/repo] [--analyzer manual|claude]
//                                [--base-url <running worktree URL>] [--skip-fetch]
//
// Stages: fetch → [capture] → analyze → render.
//
// The tool NEVER builds the target PR. To film it against live code, point --base-url at a
// PREEXISTING running worktree for that PR; the capture step drives it to each screen in the
// PR's capture-plan.json and saves fresh screenshots. Without --base-url, capture is skipped
// and whatever screenshots are already in prs/pr-<n>/assets/ are used.
//
// Example (live):
//   node pr-walkthrough.mjs 618 --repo Freegle/Iznik \
//        --base-url http://freegle-dev-live.localhost:12023
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const has = (n) => process.argv.includes(n);

const pr = process.argv[2];
if (!pr || pr.startsWith('--')) {
  console.error('usage: node pr-walkthrough.mjs <pr> [--repo owner/repo] [--analyzer manual|claude] [--base-url <url>] [--skip-fetch]');
  process.exit(1);
}
const repo = arg('--repo', 'Freegle/Iznik');
const analyzer = arg('--analyzer', 'manual');
const baseUrl = arg('--base-url', null);
const prDir = resolve(__dirname, `prs/pr-${pr}`);
const run = (script, extra) =>
  execFileSync('node', [join(__dirname, 'src', script), '--pr-dir', prDir, ...extra], { stdio: 'inherit' });

// 1. Fetch PR material (metadata, diff, body images as fallback).
if (!has('--skip-fetch')) {
  execFileSync('node', [join(__dirname, 'src', 'fetch.mjs'), pr, '--repo', repo, '--pr-dir', prDir], { stdio: 'inherit' });
}

// 2. Capture fresh screenshots from a live worktree (only when a base URL is given).
if (baseUrl) {
  if (!existsSync(join(prDir, 'capture-plan.json'))) {
    console.error(`No capture-plan.json in ${prDir} — needed for --base-url capture.`);
    process.exit(1);
  }
  run('capture.mjs', ['--base-url', baseUrl]);
} else {
  console.log('• capture skipped (no --base-url) — using existing screenshots in prs/pr-' + pr + '/assets/');
}

// 3. Analyze: validate the storyboard + report test-derived coverage.
run('analyze.mjs', ['--analyzer', analyzer]);

// 4. Render: mask PII + Remotion render.
run('render.mjs', []);
