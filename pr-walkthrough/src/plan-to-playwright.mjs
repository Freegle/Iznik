#!/usr/bin/env node
// Encapsulate the walkthrough's GOLDEN FLOWS as Playwright regression tests.
//
//   node src/plan-to-playwright.mjs --pr-dir prs/pr-618 [--out <file>]
//
// A flow that's worth a walkthrough is worth a regression test. The capture-plan already
// describes each flow (route + steps + the controls we annotate); this turns each shot into
// one Playwright `test()`: the steps become actions, and every `waitFor` + every annotated
// selector becomes a `toBeVisible` assertion (the golden state the video shows). The output
// is meant to live in the PR's `tests/e2e/` — it imports the existing auth helpers, so it is
// robust to login modals the way the video capture (stored sessions) is not. It is written as
// an ARTIFACT for the PR author to add; this tool never edits the target worktree.
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const arg = (n, f) => { const i = process.argv.indexOf(n); return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : f; };

// A capture selector → a Playwright locator expression.
function loc(sel) {
  if (sel.startsWith('testid=')) return `page.locator('[data-testid="${sel.slice(7)}"]')`;
  if (sel.startsWith('text=')) return `page.getByText(${JSON.stringify(sel.slice(5))})`;
  return `page.locator(${JSON.stringify(sel)})`;
}
// ${VAR} → ${process.env.VAR} inside a template literal.
const tmpl = (s) => '`' + String(s).replace(/\$\{([A-Z0-9_]+)\}/g, '${process.env.$1}') + '`';

function stepLine(step) {
  if (step.goto != null) return `  await page.goto(${tmpl(step.goto)}, { waitUntil: 'networkidle' })`;
  if (step.fill != null) return `  await ${loc(step.fill)}.first().fill(${tmpl(step.value)})`;
  if (step.type != null) return `  await ${loc(step.type)}.first().pressSequentially(${tmpl(step.value)})`;
  if (step.select != null) return `  await ${loc(step.select)}.first().selectOption(${tmpl(step.value)})`;
  if (step.click != null) return `  await ${loc(step.click)}.first().click()`;
  if (step.clickText != null) return `  await page.getByText(${JSON.stringify(step.clickText)}).first().click()`;
  if (step.press != null) return `  await page.keyboard.press(${JSON.stringify(step.press)})`;
  if (step.waitFor != null) return `  await expect(${loc(step.waitFor)}.first()).toBeVisible()`;
  if (step.waitForText != null) return `  await expect(page.getByText(${JSON.stringify(step.waitForText)}).first()).toBeVisible()`;
  if (step.waitMs != null) return `  await page.waitForTimeout(${step.waitMs})`;
  if (step.scrollTo != null) return `  await ${loc(step.scrollTo)}.first().scrollIntoViewIfNeeded()`;
  if (step.setViewport != null) return `  await page.setViewportSize(${JSON.stringify(step.setViewport)})`;
  return null;
}

// Guess the login helper + env email for a shot's auth file (".auth-giver.json" → GIVER_EMAIL).
function authFor(shot) {
  if (!shot.auth) return null;
  const role = basename(shot.auth).replace(/^\.auth-/, '').replace(/\.json$/, '').toUpperCase().replace(/[^A-Z0-9]/g, '_');
  const helper = /mod/i.test(role) ? 'loginViaModTools' : 'loginViaHomepage';
  return { helper, envVar: `${role}_EMAIL` };
}

function testFor(shot) {
  const name = basename(shot.name).replace(/\.(png|jpe?g)$/i, '');
  const lines = [];
  lines.push(`test('golden flow: ${name}', async ({ page }) => {`);
  const auth = authFor(shot);
  if (auth) lines.push(`  await ${auth.helper}(page, process.env.${auth.envVar}, process.env.TEST_PASSWORD || 'freegle')`);
  lines.push(`  await page.goto(${tmpl(shot.route)}, { waitUntil: 'networkidle' })`);
  for (const step of shot.steps || []) { const l = stepLine(step); if (l) lines.push(l); }
  // The golden state: every control the walkthrough highlights must be present.
  for (const a of shot.annotate || []) {
    lines.push(`  await expect(${loc(a.selector)}.first()).toBeVisible() // ${a.label}`);
  }
  lines.push('})');
  return lines.join('\n');
}

function main() {
  const prDir = resolve(ROOT, arg('--pr-dir', 'prs/pr-618'));
  const plan = JSON.parse(readFileSync(join(prDir, 'capture-plan.json'), 'utf8'));
  const tag = basename(prDir);
  const usesMod = plan.shots.some((s) => /mod/i.test(s.auth || ''));
  const header = [
    `// GOLDEN-FLOW regression tests generated from ${tag}/capture-plan.json by`,
    `// pr-walkthrough/src/plan-to-playwright.mjs. These are the same flows the walkthrough`,
    `// video shows. Drop into iznik-nuxt3/tests/e2e/. Each test asserts the golden state`,
    `// reached (the controls the video highlights). Seeded ids/emails come from env`,
    `// (see env-from-testenvs.mjs / test-envs.json); TEST_BASE_URL sets the target.`,
    `const { test, expect } = require('@playwright/test')`,
    `const { loginViaHomepage${usesMod ? ', loginViaModTools' : ''} } = require('./utils/user')`,
    '',
    `test.describe('${tag} golden flows (from walkthrough)', () => {`,
    '',
  ].join('\n');
  const body = plan.shots.map(testFor).join('\n\n');
  const out = resolve(arg('--out', join(prDir, 'generated-tests', `${tag}-golden-flows.spec.js`)));
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, header + body.split('\n').map((l) => l ? '  ' + l : l).join('\n') + '\n})\n');
  console.log(`✓ ${plan.shots.length} golden-flow test(s) → ${out}`);
  console.log('  Review + add to the PR (tests/e2e/) to lock the flows as regression coverage.');
}

main();
