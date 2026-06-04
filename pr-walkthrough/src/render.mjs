#!/usr/bin/env node
// Render a storyboard to MP4.
//
//   node src/render.mjs [--pr-dir prs/pr-618] [--out <path>] [--no-mask]
//
// Steps: validate storyboard → bake PII masks → stage ONLY the referenced (masked)
// assets into public/ → remotion render. Raw screenshots are never copied into public/,
// so the PII pixels cannot reach the rendered frames.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, copyFileSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateStoryboard } from './storyboard-schema.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const hasFlag = (name) => process.argv.includes(name);

// Read PNG (IHDR) / JPEG dimensions without a dependency.
function imageSize(path) {
  const b = readFileSync(path);
  if (b.length > 24 && b[0] === 0x89 && b[1] === 0x50) {
    return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) };
  }
  // Minimal JPEG SOF scan.
  let i = 2;
  while (i < b.length) {
    if (b[i] !== 0xff) { i += 1; continue; }
    const marker = b[i + 1];
    if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
      return { h: b.readUInt16BE(i + 5), w: b.readUInt16BE(i + 7) };
    }
    i += 2 + b.readUInt16BE(i + 2);
  }
  return null;
}

function main() {
  const exampleDir = resolve(ROOT, arg('--pr-dir', 'prs/pr-618'));
  const storyboardPath = join(exampleDir, 'storyboard.json');
  if (!existsSync(storyboardPath)) throw new Error(`No storyboard at ${storyboardPath}`);
  const sb = JSON.parse(readFileSync(storyboardPath, 'utf8'));

  const tag = basename(exampleDir); // e.g. pr-618
  const publicDir = join(ROOT, 'public', tag);
  const assetsDir = join(exampleDir, 'assets');

  // 1. Bake PII masks (idempotent).
  if (!hasFlag('--no-mask') && existsSync(join(exampleDir, 'masks.json'))) {
    console.log('• masking PII…');
    execFileSync('python3', [join(__dirname, 'imageutil.py'), 'mask', exampleDir], { stdio: 'inherit' });
  }

  // 2. Stage ONLY the assets the storyboard references (the masked copies).
  rmSync(publicDir, { recursive: true, force: true });
  mkdirSync(publicDir, { recursive: true });
  const referenced = new Set(
    sb.scenes.filter((s) => s.type === 'screenshot' && s.src).map((s) => basename(s.src)),
  );
  for (const file of referenced) {
    const from = join(assetsDir, file);
    if (!existsSync(from)) throw new Error(`Referenced asset missing (did masking run?): ${from}`);
    copyFileSync(from, join(publicDir, file));
  }

  // 3. Auto-fill natW/natH from the staged images where absent.
  for (const scene of sb.scenes) {
    if (scene.type === 'screenshot' && (!scene.natW || !scene.natH)) {
      const sz = imageSize(join(publicDir, basename(scene.src)));
      if (sz) { scene.natW = sz.w; scene.natH = sz.h; }
    }
  }

  // 4. Validate (now that assets are staged).
  const { ok, errors } = validateStoryboard(sb, (src) => existsSync(join(ROOT, 'public', src)));
  if (!ok) {
    console.error('Storyboard invalid:\n' + errors.map((e) => '  - ' + e).join('\n'));
    process.exit(1);
  }
  console.log(`• storyboard valid: ${sb.scenes.length} scenes`);

  // 5. Render.
  const outDir = join(exampleDir, 'out');
  mkdirSync(outDir, { recursive: true });
  const out = resolve(arg('--out', join(outDir, `${tag}-walkthrough.mp4`)));
  const propsPath = join(outDir, '.props.json');
  writeFileSync(propsPath, JSON.stringify(sb));

  console.log('• rendering…');
  execFileSync(
    'npx',
    ['remotion', 'render', 'src/index.jsx', 'Walkthrough', out, `--props=${propsPath}`],
    { stdio: 'inherit', cwd: ROOT },
  );
  console.log(`\n✓ ${out}`);
}

main();
