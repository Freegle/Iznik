#!/usr/bin/env node
// Capture fresh screenshots by driving a LIVE, already-running app.
//
//   node src/capture.mjs --pr-dir prs/pr-618 --base-url http://freegle-dev-live.localhost:12023
//
// The tool never builds the target PR and never edits the target worktree. It assumes a
// preexisting running worktree, points a real browser at its URL, executes the capture
// plan (derived from the PR's flows / tests) and writes screenshots into <pr-dir>/assets/.
//
// Read-only by design: a capture plan may fill fields and toggle controls to reach a
// state worth filming, but it MUST NOT submit/save — so capturing never writes to the
// target's database or touches its files. (Submit-style controls are refused below.)
//
// Uses the system Chrome via playwright-core (no extra browser download).
import { chromium } from 'playwright-core';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateCapturePlan } from './capture-plan-schema.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const hasFlag = (n) => process.argv.includes(n);

function chromePath() {
  if (process.env.CHROME && existsSync(process.env.CHROME)) return process.env.CHROME;
  for (const p of ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser']) {
    if (existsSync(p)) return p;
  }
  return undefined; // let playwright try its own
}

// Substitute ${VAR} from the environment, so seeded ids / credentials / message routes
// are supplied at capture time (e.g. BULK_MSG_ID=123) rather than hardcoded.
function subst(s) {
  return typeof s === 'string' ? s.replace(/\$\{([A-Z0-9_]+)\}/g, (_, k) => process.env[k] ?? '') : s;
}

// PNG natural size from the IHDR header (no dependency).
function pngSize(path) {
  const b = readFileSync(path);
  return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) };
}

// Auto-derive callout coordinates: ask Playwright for each annotated element's bounding
// box and express it as FRACTIONS of the captured (full-page) screenshot. This is the part
// a human used to measure off a grid — the tool now produces it deterministically.
async function measureAnnotations(page, annotate, imgW, imgH) {
  const boxes = {};
  for (const a of annotate) {
    try {
      // DOCUMENT-relative box (getBoundingClientRect is viewport-relative; add scroll) so it
      // matches the full-page screenshot's coordinate system regardless of current scroll.
      const bb = await locator(page, a.selector).first().evaluate((node) => {
        const r = node.getBoundingClientRect();
        return { x: r.x + window.scrollX, y: r.y + window.scrollY, width: r.width, height: r.height };
      });
      if (!bb || !bb.width || !bb.height) continue;
      boxes[a.label] = {
        label: a.label,
        arrow: a.arrow || 'up',
        box: { x: +(bb.x / imgW).toFixed(4), y: +(bb.y / imgH).toFixed(4), w: +(bb.width / imgW).toFixed(4), h: +(bb.height / imgH).toFixed(4) },
      };
    } catch {
      // element not present in this state — skip it
    }
  }
  return boxes;
}

// "testid=foo" → locator by test id; "text=Bar" → by text; else raw CSS.
function locator(page, sel) {
  if (sel.startsWith('testid=')) return page.locator(`[data-testid="${sel.slice(7)}"]`);
  if (sel.startsWith('text=')) return page.getByText(sel.slice(5), { exact: false });
  return page.locator(sel);
}

// Capture must not mutate the target. Refuse clicks on submit/save controls so a plan
// can never post a clearance, register interest, send a message, etc.
const MUTATING = /(submit|register-interest|post these items|register interest|\bsend\b|\bsave\b|confirm|\bdelete\b)/i;
export function isMutating(target) {
  return MUTATING.test(String(target));
}
function guardClick(target) {
  if (isMutating(target)) {
    throw new Error(`refused mutating click on "${target}" — capture is read-only (fill/toggle only, never submit)`);
  }
}

async function runStep(page, step, baseUrl) {
  if (step.goto != null) return page.goto(baseUrl + subst(step.goto), { waitUntil: 'networkidle' });
  if (step.fill != null) return locator(page, step.fill).first().fill(subst(step.value));
  if (step.type != null) return locator(page, step.type).first().pressSequentially(subst(step.value), { delay: 8 });
  if (step.select != null) return locator(page, step.select).first().selectOption(subst(step.value));
  if (step.click != null) { guardClick(step.click); return locator(page, step.click).first().click(); }
  if (step.clickText != null) { guardClick(step.clickText); return page.getByText(step.clickText, { exact: false }).first().click(); }
  if (step.press != null) return page.keyboard.press(step.press);
  if (step.waitFor != null) return locator(page, step.waitFor).first().waitFor({ state: 'visible', timeout: 15000 });
  if (step.waitForText != null) return page.getByText(step.waitForText, { exact: false }).first().waitFor({ state: 'visible', timeout: 15000 });
  if (step.waitMs != null) return page.waitForTimeout(step.waitMs);
  if (step.scrollTo != null) return locator(page, step.scrollTo).first().scrollIntoViewIfNeeded();
  if (step.scrollBy != null) return page.evaluate((y) => window.scrollBy(0, y), step.scrollBy);
  if (step.setViewport != null) return page.setViewportSize(step.setViewport);
  return undefined;
}

export async function capture(plan, { baseUrl, assetsDir, headful = false, storageState = undefined, authDir = undefined }) {
  mkdirSync(assetsDir, { recursive: true });
  const browser = await chromium.launch({
    executablePath: chromePath(),
    headless: !headful,
    args: ['--no-sandbox', '--hide-scrollbars', '--force-device-scale-factor=1'],
  });
  const results = [];
  try {
    const vpDefault = plan.defaultViewport || { width: 1385, height: 1200 };
    for (const shot of plan.shots) {
      // A shot may name its own auth state (e.g. a moderator); otherwise use the run default.
      const shotState = shot.auth ? join(authDir || assetsDir, shot.auth) : storageState;
      const context = await browser.newContext({
        viewport: shot.viewport || vpDefault,
        deviceScaleFactor: 1,
        ...(shotState && existsSync(shotState) ? { storageState: shotState } : {}),
      });
      const page = await context.newPage();
      try {
        // A shot may target a different app (e.g. ModTools) via its own baseUrl.
        const shotBase = (shot.baseUrl ? subst(shot.baseUrl) : baseUrl).replace(/\/$/, '');
        await page.goto(shotBase + subst(shot.route), { waitUntil: 'networkidle', timeout: 30000 });
        for (const step of shot.steps || []) await runStep(page, step, shotBase);
        const dest = join(assetsDir, shot.name);
        const fullPage = !shot.clip && shot.fullPage !== false;
        if (shot.clip) {
          await locator(page, shot.clip).first().screenshot({ path: dest });
        } else if (fullPage) {
          // Capture the whole page by SIZING THE VIEWPORT to the document and taking a normal
          // viewport screenshot — NOT Playwright's fullPage (which resizes+stitches the viewport
          // and can distort viewport-/JS-sized layouts, producing phantom gaps). This also keeps
          // the screenshot and the measured callout boxes in ONE identical layout state.
          const vp = page.viewportSize() || { width: 1385, height: 1400 };
          const docH = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
          await page.setViewportSize({ width: vp.width, height: Math.min(docH + 40, 16000) });
          await page.evaluate(() => window.scrollTo(0, 0));
          await page.waitForTimeout(350);
          await page.screenshot({ path: dest, fullPage: false });
        } else {
          await page.screenshot({ path: dest, fullPage: false });
        }
        // Auto-measure annotation boxes (fullPage only — fractions map to the screenshot).
        let measured = 0;
        if (fullPage && Array.isArray(shot.annotate) && shot.annotate.length) {
          const { w, h } = pngSize(dest);
          const boxes = await measureAnnotations(page, shot.annotate, w, h);
          measured = Object.keys(boxes).length;
          writeFileSync(join(assetsDir, basename(shot.name).replace(/\.(png|jpe?g)$/i, '.boxes.json')), JSON.stringify(boxes, null, 2));
        }
        // Record the ACTUAL (test) URL captured from, so the video's browser chrome shows the
        // real test address — never a hardcoded live/production URL. Host + path, no port.
        const shotUrl = (shotBase + subst(shot.route)).replace(/^https?:\/\//, '').replace(/:\d+/, '');
        writeFileSync(join(assetsDir, basename(shot.name).replace(/\.(png|jpe?g)$/i, '.meta.json')), JSON.stringify({ url: shotUrl }));
        results.push({ name: shot.name, ok: true, dest });
        console.log(`  ✓ ${shot.name} ← ${shot.route}${measured ? ` (+${measured} measured callouts)` : ''}`);
      } catch (e) {
        results.push({ name: shot.name, ok: false, error: e.message });
        console.warn(`  ✗ ${shot.name} ← ${shot.route}: ${e.message}`);
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
  return results;
}

async function main() {
  const exampleDir = resolve(ROOT, arg('--pr-dir', 'prs/pr-618'));
  const planPath = arg('--plan', join(exampleDir, 'capture-plan.json'));
  if (!existsSync(planPath)) throw new Error(`No capture plan at ${planPath}`);
  const plan = JSON.parse(readFileSync(planPath, 'utf8'));

  const { ok, errors } = validateCapturePlan(plan);
  if (!ok) { console.error('Capture plan invalid:\n' + errors.map((e) => '  - ' + e).join('\n')); process.exit(1); }

  const baseUrl = (arg('--base-url', plan.baseUrl) || '').replace(/\/$/, '');
  if (!baseUrl) throw new Error('No base URL — pass --base-url <running worktree URL> or set baseUrl in the plan');
  const assetsDir = arg('--out', join(exampleDir, 'assets'));

  const storageState = arg('--storage-state', undefined);
  console.log(`• capturing ${plan.shots.length} shot(s) from ${baseUrl}${storageState ? ' (authed)' : ''}`);
  const results = await capture(plan, { baseUrl, assetsDir, headful: hasFlag('--headful'), storageState, authDir: exampleDir });
  const failed = results.filter((r) => !r.ok);
  if (failed.length) { console.error(`\n${failed.length} shot(s) failed.`); process.exit(2); }
  console.log(`\n✓ ${results.length} screenshot(s) → ${assetsDir}`);
}

if (import.meta.url === `file://${process.argv[1]}`) main();
