#!/usr/bin/env node
// One-command pipeline: fetch → analyze → render a PR walkthrough.
//
//   node pr-walkthrough.mjs <pr> [--repo owner/repo] [--analyzer manual|claude] [--skip-fetch]
//
// Example:
//   node pr-walkthrough.mjs 618 --repo Freegle/Iznik
//
// By default the analyzer is `manual` (uses the committed storyboard.json). Pass
// `--analyzer claude` to (re)generate the storyboard from the diff via the `claude` CLI.
import { execFileSync } from 'node:child_process';
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
  console.error('usage: node pr-walkthrough.mjs <pr> [--repo owner/repo] [--analyzer manual|claude] [--skip-fetch]');
  process.exit(1);
}
const repo = arg('--repo', 'Freegle/Iznik');
const analyzer = arg('--analyzer', 'manual');
const exampleDir = resolve(__dirname, `examples/pr-${pr}`);
const run = (script, extra) =>
  execFileSync('node', [join(__dirname, 'src', script), '--example', exampleDir, ...extra], { stdio: 'inherit' });

if (!has('--skip-fetch')) {
  execFileSync('node', [join(__dirname, 'src', 'fetch.mjs'), pr, '--repo', repo, '--example', exampleDir], { stdio: 'inherit' });
}
run('analyze.mjs', ['--analyzer', analyzer]);
run('render.mjs', []);
