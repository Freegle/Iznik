/**
 * Documentation screenshot generator.
 *
 * Drives the real Freegle and ModTools apps with Playwright and writes deterministic
 * screenshots into docs/<audience>/assets/. This is how the images in the member and
 * moderator guides stay up to date: they are regenerated from the running app rather
 * than pasted in by hand.
 *
 * It lives under iznik-nuxt3/ so that `@playwright/test` resolves from the app's own
 * install. Run it from the iznik-nuxt3 directory:
 *
 *   TEST_BASE_URL=http://freegle-prod-local.localhost \
 *   TEST_MODTOOLS_BASE_URL=http://modtools-prod-local.localhost \
 *   DOCS_MEMBER_EMAIL=test@test.com DOCS_MEMBER_PASSWORD=freegle \
 *   DOCS_MOD_EMAIL=testmod@test.com DOCS_MOD_PASSWORD=freegle \
 *   node tests/e2e/docs-screenshots.mjs
 *
 * See docs/screenshots/README.md for the full guide, including how to point it at a
 * worktree (offset ports) and how determinism is achieved.
 */
import { chromium } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { mkdir } from 'node:fs/promises'

const __dirname = dirname(fileURLToPath(import.meta.url))
// DOCS_OUT lets CI write the assets to a known dir it can copy out of the
// container; locally it defaults to the repo's docs/ folder.
const DOCS_ROOT = process.env.DOCS_OUT
  ? resolve(process.env.DOCS_OUT)
  : resolve(__dirname, '../../../docs')

const cfg = {
  memberBase: process.env.TEST_BASE_URL || 'http://freegle-prod-local.localhost',
  modBase:
    process.env.TEST_MODTOOLS_BASE_URL || 'http://modtools-prod-local.localhost',
  memberEmail: process.env.DOCS_MEMBER_EMAIL || 'test@test.com',
  memberPassword: process.env.DOCS_MEMBER_PASSWORD || 'freegle',
  modEmail: process.env.DOCS_MOD_EMAIL || 'testmod@test.com',
  modPassword: process.env.DOCS_MOD_PASSWORD || 'freegle',
  // Freegle is mobile-first, so docs use a phone viewport.
  viewport: { width: 390, height: 844 },
  navTimeout: 45000,
}

/**
 * The manifest. Each entry maps one screenshot to one place in the docs.
 * auth: 'none' logged out, 'member' logged in as a freegler, 'mod' logged in to ModTools.
 * Add a shot here rather than capturing images by hand.
 */
const SHOTS = [
  // Members
  { audience: 'members', name: 'homepage', auth: 'none', app: 'member', path: '/' },
  { audience: 'members', name: 'explore', auth: 'none', app: 'member', path: '/explore' },
  { audience: 'members', name: 'browse', auth: 'member', app: 'member', path: '/browse' },
  { audience: 'members', name: 'give-start', auth: 'none', app: 'member', path: '/give/mobile/details' },
  { audience: 'members', name: 'find-start', auth: 'none', app: 'member', path: '/find/mobile/details' },
  { audience: 'members', name: 'myposts', auth: 'member', app: 'member', path: '/myposts' },
  { audience: 'members', name: 'chats', auth: 'member', app: 'member', path: '/chats' },
  { audience: 'members', name: 'settings', auth: 'member', app: 'member', path: '/settings' },
  // Moderators
  { audience: 'moderators', name: 'dashboard', auth: 'mod', app: 'mod', path: '/' },
  { audience: 'moderators', name: 'pending', auth: 'mod', app: 'mod', path: '/messages/pending' },
  { audience: 'moderators', name: 'members', auth: 'mod', app: 'mod', path: '/members/approved' },
  { audience: 'moderators', name: 'settings', auth: 'mod', app: 'mod', path: '/settings' },
]

/**
 * Selectors that vary run to run and should be masked for determinism. Extend as needed;
 * a wrong selector simply matches nothing, so this is safe to keep broad.
 */
const MASK = []

async function fillLogin(page, email, password) {
  const modal = page.locator('#loginModal')
  await modal.first().waitFor({ state: 'visible', timeout: cfg.navTimeout })

  // The modal opens on either the Join or Log in tab. Switch to Log in if needed.
  const switchToLogin = page.locator(
    '#loginModal .test-already-a-freegler, #loginModal :text("Log in")'
  )
  if (await switchToLogin.first().isVisible().catch(() => false)) {
    await switchToLogin.first().click().catch(() => {})
  }

  await page.locator('#loginModal input[type="email"]').first().fill(email)
  await page.locator('#loginModal input[type="password"]').first().fill(password)
  await page
    .locator('#loginform button[type="submit"], #loginModal button:has-text("Log in")')
    .first()
    .click()

  // Wait for the modal to go away, which means we are in.
  await page
    .locator('#loginModal')
    .first()
    .waitFor({ state: 'hidden', timeout: cfg.navTimeout })
    .catch(() => {})
}

async function loginMember(page) {
  await page.goto(cfg.memberBase + '/browse', {
    waitUntil: 'domcontentloaded',
    timeout: cfg.navTimeout,
  })
  await fillLogin(page, cfg.memberEmail, cfg.memberPassword)
}

async function loginMod(page) {
  await page.goto(cfg.modBase + '/', {
    waitUntil: 'domcontentloaded',
    timeout: cfg.navTimeout,
  })
  await fillLogin(page, cfg.modEmail, cfg.modPassword)
}

async function capture(context, shot) {
  const base = shot.app === 'mod' ? cfg.modBase : cfg.memberBase
  const outDir = resolve(DOCS_ROOT, shot.audience, 'assets')
  await mkdir(outDir, { recursive: true })
  const page = context.page
  await page.goto(base + shot.path, {
    waitUntil: 'networkidle',
    timeout: cfg.navTimeout,
  })
  // Settle animations and lazy content.
  await page.waitForTimeout(1500)
  await page.screenshot({
    path: resolve(outDir, shot.name + '.png'),
    animations: 'disabled',
    caret: 'hide',
    mask: MASK.map((sel) => page.locator(sel)),
  })
  console.log(`  captured ${shot.audience}/${shot.name}.png  (${shot.path})`)
}

async function run() {
  console.log('Docs screenshots')
  console.log('  member app :', cfg.memberBase)
  console.log('  mod app    :', cfg.modBase)
  console.log('  output     :', DOCS_ROOT)

  const browser = await chromium.launch()
  const ctxOpts = {
    viewport: cfg.viewport,
    locale: 'en-GB',
    timezoneId: 'Europe/London',
    reducedMotion: 'reduce',
  }

  let memberCtx = null
  let modCtx = null
  let failures = 0

  for (const shot of SHOTS) {
    try {
      let ctx
      if (shot.auth === 'member') {
        if (!memberCtx) {
          memberCtx = await browser.newContext(ctxOpts)
          memberCtx.page = await memberCtx.newPage()
          await loginMember(memberCtx.page)
        }
        ctx = memberCtx
      } else if (shot.auth === 'mod') {
        if (!modCtx) {
          modCtx = await browser.newContext(ctxOpts)
          modCtx.page = await modCtx.newPage()
          await loginMod(modCtx.page)
        }
        ctx = modCtx
      } else {
        // Logged-out: a fresh incognito context per shot so no stale session leaks in.
        ctx = await browser.newContext(ctxOpts)
        ctx.page = await ctx.newPage()
      }
      await capture(ctx, shot)
      if (shot.auth === 'none') await ctx.close()
    } catch (err) {
      failures++
      console.error(`  FAILED ${shot.audience}/${shot.name}: ${err.message}`)
    }
  }

  await browser.close()
  console.log(failures ? `Done with ${failures} failure(s).` : 'Done.')
  process.exit(failures ? 1 : 0)
}

run().catch((err) => {
  console.error(err)
  process.exit(1)
})
