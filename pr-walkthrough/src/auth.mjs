#!/usr/bin/env node
// Log in to a running Freegle (or ModTools) instance and save the browser auth state, so
// capture.mjs can film logged-in / moderator screens (pass it via --storage-state, or name
// it on a shot as "auth"). Logging in does not mutate data — it's read-only.
//
//   node src/auth.mjs --base-url http://freegle-dev-local.localhost:12023 \
//        --email pw_user@test.com --password freegle --out prs/pr-618/.auth-user.json
//
// Selectors are Freegle's login-modal selectors (.test-signinbutton, #loginModal,
// .test-already-a-freegler). The main app needs the sign-in button clicked to open the
// modal; ModTools shows it on load — this handles both.
import { chromium } from 'playwright-core';
import { existsSync } from 'node:fs';

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
function chromePath() {
  if (process.env.CHROME && existsSync(process.env.CHROME)) return process.env.CHROME;
  for (const p of ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser']) {
    if (existsSync(p)) return p;
  }
  return undefined;
}

export async function login({ baseUrl, email, password = 'freegle', out, headful = false }) {
  const browser = await chromium.launch({
    executablePath: chromePath(), headless: !headful, args: ['--no-sandbox'],
  });
  const ctx = await browser.newContext({ viewport: { width: 1385, height: 1200 } });
  const page = await ctx.newPage();
  try {
    await page.goto(baseUrl.replace(/\/$/, '') + '/', { waitUntil: 'domcontentloaded', timeout: 30000 });

    // Open the login modal if a sign-in button is shown (main app); ModTools shows it directly.
    const signin = page.locator('.test-signinbutton:visible').first();
    if (await signin.isVisible({ timeout: 8000 }).catch(() => false)) await signin.click();
    await page.locator('#loginModal').first().waitFor({ state: 'visible', timeout: 15000 });

    // Switch from signup to signin mode if needed.
    const loginLink = page.locator('.test-already-a-freegler').filter({ visible: true }).first();
    if (await loginLink.isVisible({ timeout: 4000 }).catch(() => false)) await loginLink.click();

    await page.locator('input[type="email"], input[name="email"]').first().fill(email);
    const pw = page.locator('input[type="password"], input[name="password"]').first();
    await pw.fill(password);
    await pw.press('Enter');

    await page.locator('#loginModal').first().waitFor({ state: 'hidden', timeout: 20000 });
    await page.waitForTimeout(2500);
    await ctx.storageState({ path: out });
    const stillOut = await page.locator('.test-signinbutton:visible').first().isVisible().catch(() => false);
    return { ok: !stillOut, out };
  } finally {
    await browser.close();
  }
}

async function main() {
  const baseUrl = arg('--base-url', null);
  const email = arg('--email', null);
  const out = arg('--out', null);
  if (!baseUrl || !email || !out) {
    console.error('usage: node src/auth.mjs --base-url <url> --email <email> [--password freegle] --out <file>');
    process.exit(1);
  }
  const r = await login({ baseUrl, email, password: arg('--password', 'freegle'), out, headful: process.argv.includes('--headful') });
  console.log(r.ok ? `✓ logged in as ${email} → ${out}` : `✗ login may have failed for ${email} (sign-in button still visible)`);
  if (!r.ok) process.exit(2);
}

if (import.meta.url === `file://${process.argv[1]}`) main();
