#!/usr/bin/env node
// Help get the rendered MP4 into the PR.
//
//   node src/publish.mjs --example examples/pr-618                     # print embed instructions
//   node src/publish.mjs --example examples/pr-618 --release owner/repo [--tag <tag>]
//
// GitHub renders an inline <video> player only for files uploaded as PR/issue ATTACHMENTS
// (user-attachments), and that upload endpoint isn't exposed by the gh CLI/API — so the
// reliable inline embed is a one-time drag-drop into the PR description. The --release path
// is the automatable fallback: it uploads the MP4 as a Release asset and prints a URL.
import { execFileSync } from 'node:child_process';
import { existsSync, statSync, readdirSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const exampleDir = resolve(ROOT, arg('--example', 'examples/pr-618'));
const outDir = join(exampleDir, 'out');
if (!existsSync(outDir)) throw new Error(`No out/ dir in ${exampleDir} — render first.`);
const mp4 = readdirSync(outDir).find((f) => f.endsWith('.mp4'));
if (!mp4) throw new Error(`No .mp4 in ${outDir} — render first.`);
const mp4Path = join(outDir, mp4);
const sizeMB = (statSync(mp4Path).size / (1024 * 1024)).toFixed(1);

const release = arg('--release', null);

if (release) {
  const tag = arg('--tag', basename(exampleDir) + '-walkthrough');
  console.log(`• uploading ${mp4} (${sizeMB} MB) to ${release} release ${tag}…`);
  try {
    execFileSync('gh', ['release', 'view', tag, '--repo', release], { stdio: 'ignore' });
    execFileSync('gh', ['release', 'upload', tag, mp4Path, '--repo', release, '--clobber'], { stdio: 'inherit' });
  } catch {
    execFileSync('gh', ['release', 'create', tag, mp4Path, '--repo', release, '--title', `Walkthrough: ${tag}`, '--notes', 'Auto-generated PR walkthrough video.'], { stdio: 'inherit' });
  }
  const url = `https://github.com/${release}/releases/download/${tag}/${mp4}`;
  console.log(`\n✓ ${url}\n\nPaste into the PR description:\n\n${url}\n`);
} else {
  console.log(`\nRendered walkthrough: ${mp4Path} (${sizeMB} MB)\n`);
  console.log('To embed an inline player in the PR (recommended):');
  console.log('  1. Open the PR (or a comment) in the browser and Edit.');
  console.log(`  2. Drag ${mp4} into the text box. GitHub uploads it to user-attachments`);
  console.log('     and inserts a player automatically. Save.\n');
  console.log('GitHub turns the dropped file into markup like:');
  console.log('  https://github.com/user-attachments/assets/<uuid>\n');
  console.log('Automatable fallback (Release asset, stable URL but a download link, not a player):');
  console.log('  node src/publish.mjs --example ' + arg('--example', 'examples/pr-618') + ' --release <owner/repo>\n');
}
