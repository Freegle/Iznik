#!/usr/bin/env node
// Turn fetched PR material into a storyboard.json (the script handed to Remotion).
//
//   node src/analyze.mjs [--example dir] [--analyzer manual|claude]
//
// manual (default): validate the existing hand-authored storyboard.json. Spends nothing.
// claude          : build the prompt from prompts/analyze.md + the PR material and ask the
//                   `claude` CLI to write a storyboard. OPT-IN only — it spends tokens, so it
//                   is never run unless you pass `--analyzer claude`.
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateStoryboard } from './storyboard-schema.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

function imageSize(path) {
  const b = readFileSync(path);
  if (b.length > 24 && b[0] === 0x89 && b[1] === 0x50) return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) };
  return null;
}

function listAssets(assetsDir) {
  if (!existsSync(assetsDir)) return [];
  return readdirSync(assetsDir)
    .filter((f) => /\.(png|jpe?g)$/i.test(f))
    .map((f) => {
      const sz = imageSize(join(assetsDir, f));
      return { file: f, ...(sz || {}) };
    });
}

function buildPrompt(exampleDir) {
  const tmpl = readFileSync(join(ROOT, 'prompts', 'analyze.md'), 'utf8');
  const metaFile = readdirSync(exampleDir).find((f) => /^pr-\d+\.json$/.test(f));
  const diffFile = readdirSync(exampleDir).find((f) => /^pr-\d+\.diff$/.test(f));
  const meta = metaFile ? readFileSync(join(exampleDir, metaFile), 'utf8') : '{}';
  const assets = listAssets(join(exampleDir, 'assets'));
  const diff = diffFile ? readFileSync(join(exampleDir, diffFile), 'utf8') : '';

  return [
    tmpl.replace('{{META}}', meta).replace('{{ASSETS}}', JSON.stringify(assets, null, 2)),
    '\n## PR diff (context only — do NOT show on screen)\n',
    '```diff',
    diff.slice(0, 120000),
    '```',
  ].join('\n');
}

function runClaude(prompt) {
  const probe = spawnSync('claude', ['--version'], { encoding: 'utf8' });
  if (probe.status !== 0) throw new Error('`claude` CLI not found on PATH');
  const res = spawnSync('claude', ['-p', prompt], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
  if (res.status !== 0) throw new Error(`claude failed: ${res.stderr || res.stdout}`);
  // Extract the JSON object from the response.
  const text = res.stdout;
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start < 0 || end < 0) throw new Error('no JSON object in claude output');
  return JSON.parse(text.slice(start, end + 1));
}

function main() {
  const exampleDir = resolve(ROOT, arg('--example', 'examples/pr-618'));
  const analyzer = arg('--analyzer', 'manual');
  const storyboardPath = join(exampleDir, 'storyboard.json');
  const assetExists = (src) => existsSync(join(exampleDir, 'assets', basename(src)));

  let sb;
  if (analyzer === 'claude') {
    console.log('• analyzer: claude (opt-in, spends tokens)');
    sb = runClaude(buildPrompt(exampleDir));
    writeFileSync(storyboardPath, JSON.stringify(sb, null, 2));
    console.log(`• wrote ${storyboardPath}`);
  } else {
    if (!existsSync(storyboardPath)) {
      throw new Error(`No storyboard.json in ${exampleDir}. Author one, or run with --analyzer claude.`);
    }
    sb = JSON.parse(readFileSync(storyboardPath, 'utf8'));
    console.log('• analyzer: manual (using existing storyboard.json)');
  }

  const { ok, errors } = validateStoryboard(sb, assetExists);
  if (!ok) {
    console.error('Storyboard invalid:\n' + errors.map((e) => '  - ' + e).join('\n'));
    process.exit(1);
  }
  const secs = sb.scenes.reduce((a, s) => a + s.seconds, 0);
  console.log(`✓ storyboard valid: ${sb.scenes.length} scenes, ~${secs}s`);
}

main();
