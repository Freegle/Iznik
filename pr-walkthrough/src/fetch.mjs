#!/usr/bin/env node
// Fetch raw material for a PR walkthrough.
//
//   node src/fetch.mjs <pr> [--repo owner/repo] [--example <dir>]
//
// Writes <example>/pr-<n>.json (metadata), <example>/pr-<n>.diff (full diff) and
// downloads every image referenced in the PR body into <example>/assets/.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync, createWriteStream } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

// Pull out image URLs from markdown ![alt](url) and <img src="url">.
export function extractImageUrls(body) {
  const urls = new Set();
  const md = /!\[[^\]]*\]\(([^)\s]+)/g;
  const html = /<img[^>]+src=["']([^"']+)["']/gi;
  let m;
  while ((m = md.exec(body))) urls.add(m[1]);
  while ((m = html.exec(body))) urls.add(m[1]);
  return [...urls].filter((u) => /^https?:\/\//.test(u));
}

async function download(url, dest) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  const buf = Buffer.from(await res.arrayBuffer());
  writeFileSync(dest, buf);
  return buf.length;
}

async function main() {
  const pr = process.argv[2];
  if (!pr || pr.startsWith('--')) {
    console.error('usage: node src/fetch.mjs <pr> [--repo owner/repo] [--example dir]');
    process.exit(1);
  }
  const repo = arg('--repo', 'Freegle/Iznik');
  const exampleDir = resolve(ROOT, arg('--example', `examples/pr-${pr}`));
  const assets = join(exampleDir, 'assets');
  mkdirSync(assets, { recursive: true });

  console.log(`• fetching PR #${pr} from ${repo}…`);
  const meta = execFileSync(
    'gh',
    ['pr', 'view', pr, '--repo', repo, '--json',
      'number,title,body,headRefName,baseRefName,additions,deletions,changedFiles,url,author'],
    { encoding: 'utf8' },
  );
  writeFileSync(join(exampleDir, `pr-${pr}.json`), meta);

  const diff = execFileSync('gh', ['pr', 'diff', pr, '--repo', repo], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  writeFileSync(join(exampleDir, `pr-${pr}.diff`), diff);

  const body = JSON.parse(meta).body || '';
  const urls = extractImageUrls(body);
  console.log(`• ${urls.length} image(s) in PR body`);
  for (const url of urls) {
    const name = basename(new URL(url).pathname) || `image-${Math.abs(hash(url))}.png`;
    const dest = join(assets, name);
    try {
      const n = await download(url, dest);
      console.log(`  ↓ ${name} (${(n / 1024).toFixed(0)} KB)`);
    } catch (e) {
      console.warn(`  ! failed: ${url} — ${e.message}`);
    }
  }
  console.log(`✓ ${exampleDir}`);
}

function hash(s) {
  let h = 0;
  for (let i = 0; i < s.length; i += 1) h = (h * 31 + s.charCodeAt(i)) | 0;
  return h;
}

main();
