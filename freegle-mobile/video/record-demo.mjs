import { chromium } from 'playwright';
import { execSync } from 'child_process';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE_URL = 'http://localhost:3001';
const OUTPUT_DIR = path.resolve(__dirname, 'out');
const SOUNDTRACK = path.resolve(__dirname, 'public/bg-music.mp3');

fs.mkdirSync(OUTPUT_DIR, { recursive: true });

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

async function record() {
  const browser = await chromium.launch({
    headless: false,
    channel: 'chrome',
    args: ['--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({
    viewport: { width: 375, height: 812 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    recordVideo: {
      dir: OUTPUT_DIR,
      size: { width: 375, height: 812 },
    },
  });

  const page = await context.newPage();
  page.on('console', msg => {
    if (msg.type() === 'error') console.log('  [console.error]', msg.text().substring(0, 200));
  });
  page.on('pageerror', err => console.log('  [pageerror]', err.message.substring(0, 200)));
  page.on('response', resp => {
    if (resp.status() >= 400) console.log(`  [HTTP ${resp.status()}] ${resp.url().substring(0, 120)}`);
  });

  // Scene 1: Onboarding Welcome
  console.log('Scene 1: Onboarding Welcome');
  await page.goto(BASE_URL, { waitUntil: 'networkidle' });
  await page.evaluate(() => {
    localStorage.removeItem('freegle-mobile-onboarded');
    localStorage.removeItem('freegle-mobile-location');
  });
  await page.reload({ waitUntil: 'networkidle' });
  await sleep(3000);

  // Scene 2: How it works
  console.log('Scene 2: How it works');
  await page.click('.onboarding__next');
  await sleep(2500);

  // Scene 3: Community
  console.log('Scene 3: Community');
  await page.click('.onboarding__next');
  await sleep(2500);

  // Scene 4: Location picker
  console.log('Scene 4: Location picker');
  await page.click('.onboarding__next');
  await sleep(2000);

  // Scene 5: Type postcode and go
  console.log('Scene 5: Enter postcode');
  await page.fill('input[placeholder*="postcode"], .location-picker__input', 'PR3 2NX');
  await sleep(1000);

  // Set location and navigate to feed (no login needed — API is public)
  await page.evaluate(() => {
    localStorage.setItem('freegle-mobile-onboarded', '1');
    localStorage.setItem('freegle-mobile-location', JSON.stringify({
      postcode: 'PR3 2NX', type: 'postcode',
      lat: 53.86469, lng: -2.624747,
    }));
    localStorage.removeItem('auth');
  });

  await page.goto(BASE_URL + '/feed', { waitUntil: 'domcontentloaded' });

  // Scene 6: Feed loading + browsing
  console.log('Scene 6: Feed with real posts');
  // Poll for feed cards — they render asynchronously after multiple API calls
  let loaded = false;
  for (let attempt = 0; attempt < 20; attempt++) {
    await sleep(2000);
    const count = await page.locator('.feed-card').count();
    if (count > 0) {
      console.log(`  Feed cards loaded: ${count} (after ${(attempt + 1) * 2}s)`);
      loaded = true;
      break;
    }
    if (attempt % 5 === 4) {
      const state = await page.evaluate(() => document.querySelector('.feed-page__content')?.innerText?.substring(0, 100));
      console.log(`  Still waiting... content: ${state}`);
    }
  }
  if (!loaded) {
    console.log('  Feed never loaded after 40s');
    // Force loading to false via DOM manipulation
    const debugState = await page.evaluate(() => {
      const nuxt = document.querySelector('#__nuxt')?.__vue_app__;
      return {
        hasVueApp: !!nuxt,
        bodyHTML: document.querySelector('.feed-page__content')?.innerHTML?.substring(0, 200),
      };
    });
    console.log('  Vue app:', debugState.hasVueApp, 'Content:', debugState.bodyHTML);
  }
  await sleep(3000);

  // Scene 7: Slow scroll
  console.log('Scene 7: Scrolling feed');
  for (let i = 0; i < 5; i++) {
    await page.evaluate(() => document.querySelector('.feed-page__content')?.scrollBy(0, 150));
    await sleep(800);
  }
  await sleep(1000);

  // Scene 8: Scroll back and tap a post
  console.log('Scene 8: Post detail');
  await page.evaluate(() => document.querySelector('.feed-page__content')?.scrollTo(0, 0));
  await sleep(1000);
  try {
    const card = page.locator('.feed-card:not(.feed-card--taken)').first();
    await card.click({ timeout: 5000 });
    await sleep(3500);

    // Scene 9: Reply flow
    console.log('Scene 9: Reply');
    const replyBtn = page.locator('.post-detail__reply-btn');
    if (await replyBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await replyBtn.click();
      await sleep(3000);
    }

    // Close reply
    const chatBack = page.locator('.chat-slide__back');
    if (await chatBack.isVisible({ timeout: 1000 }).catch(() => false)) {
      await chatBack.click();
      await sleep(500);
    }

    // Close detail
    const detailBack = page.locator('.post-detail__back');
    if (await detailBack.isVisible({ timeout: 1000 }).catch(() => false)) {
      await detailBack.click();
      await sleep(1000);
    }
  } catch (e) {
    console.log('  (skipped detail/reply — no feed cards loaded)');
    await sleep(2000);
  }

  // Scene 10: Settings
  console.log('Scene 10: Settings');
  await page.click('[aria-label="Open settings"]');
  await sleep(2500);
  await page.click('.settings-drawer__back');
  await sleep(1500);

  // Final pause
  await sleep(2000);

  console.log('Recording complete');
  await page.close();
  await context.close();
  await browser.close();

  // Find the video file
  const files = fs.readdirSync(OUTPUT_DIR)
    .filter(f => f.endsWith('.webm'))
    .sort((a, b) => fs.statSync(path.join(OUTPUT_DIR, b)).mtimeMs - fs.statSync(path.join(OUTPUT_DIR, a)).mtimeMs);

  if (!files.length) {
    console.error('No video file found');
    process.exit(1);
  }

  const rawVideo = path.join(OUTPUT_DIR, files[0]);
  const finalVideo = path.join(OUTPUT_DIR, 'freegle-mobile-playwright.mp4');

  console.log(`Raw: ${rawVideo}`);
  console.log('Mixing soundtrack...');

  try {
    execSync(
      `ffmpeg -y -i "${rawVideo}" -i "${SOUNDTRACK}" ` +
      `-c:v libx264 -preset fast -crf 23 ` +
      `-c:a aac -b:a 128k -af "volume=0.7" ` +
      `-shortest -movflags +faststart ` +
      `"${finalVideo}"`,
      { stdio: 'inherit' }
    );
    console.log(`Done: ${finalVideo}`);
  } catch (e) {
    console.log('ffmpeg failed, raw video:', rawVideo);
  }
}

record().catch(e => {
  console.error(e);
  process.exit(1);
});
