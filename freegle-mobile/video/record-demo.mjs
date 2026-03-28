import { chromium } from 'playwright';
import { execSync } from 'child_process';
import path from 'path';
import fs from 'fs';

const BASE_URL = 'http://localhost:3001';
const OUTPUT_DIR = path.resolve('video/out');
const SOUNDTRACK = path.resolve('video/public/bg-music-warm.mp3');

fs.mkdirSync(OUTPUT_DIR, { recursive: true });

async function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

async function record() {
  const browser = await chromium.launch({ headless: true });
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

  // Suppress console noise
  page.on('console', () => {});
  page.on('pageerror', () => {});

  console.log('1/8 Onboarding - Welcome');
  await page.evaluate(() => {
    localStorage.removeItem('freegle-mobile-onboarded');
    localStorage.removeItem('freegle-mobile-location');
  });
  await page.goto(BASE_URL, { waitUntil: 'networkidle' });
  await sleep(2500);

  console.log('2/8 Onboarding - How it works');
  await page.click('.onboarding__next');
  await sleep(2000);

  console.log('3/8 Onboarding - Community');
  await page.click('.onboarding__next');
  await sleep(2000);

  console.log('4/8 Location picker');
  await page.click('.onboarding__next');
  await sleep(1500);

  // Type postcode
  console.log('5/8 Enter postcode');
  const input = page.locator('.location-picker__input, input[placeholder*="postcode"]');
  await input.fill('PR3 2NX');
  await sleep(800);
  await page.click('.location-picker__go, button:has-text("Go")');
  await sleep(1000);

  // Set location directly to ensure it works
  await page.evaluate(() => {
    localStorage.setItem('freegle-mobile-location', JSON.stringify({
      postcode: 'PR3 2NX', type: 'postcode',
      lat: 53.86469, lng: -2.624747,
    }));
  });
  await page.goto(BASE_URL + '/feed', { waitUntil: 'networkidle' });

  console.log('6/8 Feed loading');
  await sleep(8000); // Wait for API data

  // Slow scroll through the feed
  console.log('7/8 Scrolling feed');
  for (let i = 0; i < 4; i++) {
    await page.evaluate(() => document.querySelector('.feed-page__content')?.scrollBy(0, 200));
    await sleep(1200);
  }

  // Scroll back up
  await page.evaluate(() => document.querySelector('.feed-page__content')?.scrollTo(0, 0));
  await sleep(1000);

  // Click on a post for detail view
  console.log('8/8 Post detail');
  const card = page.locator('.feed-card:not(.feed-card--taken)').first();
  await card.click();
  await sleep(3000);

  // Close detail
  const backBtn = page.locator('.post-detail__back');
  if (await backBtn.isVisible()) {
    await backBtn.click();
    await sleep(1000);
  }

  // Open settings briefly
  await page.click('[aria-label="Open settings"]');
  await sleep(2000);

  // Close settings
  await page.click('.settings-drawer__back');
  await sleep(1500);

  // Final pause on feed
  await sleep(2000);

  console.log('Recording complete, closing browser...');
  await page.close();
  await context.close();
  await browser.close();

  // Find the recorded video file
  const files = fs.readdirSync(OUTPUT_DIR).filter(f => f.endsWith('.webm'));
  if (!files.length) {
    console.error('No video file found!');
    process.exit(1);
  }

  const rawVideo = path.join(OUTPUT_DIR, files[files.length - 1]);
  const finalVideo = path.join(OUTPUT_DIR, 'freegle-mobile-demo.mp4');

  console.log(`Raw video: ${rawVideo}`);
  console.log('Adding soundtrack and converting to MP4...');

  // Combine video + soundtrack with ffmpeg
  try {
    execSync(
      `ffmpeg -y -i "${rawVideo}" -i "${SOUNDTRACK}" ` +
      `-c:v libx264 -preset fast -crf 23 ` +
      `-c:a aac -b:a 128k ` +
      `-shortest -movflags +faststart ` +
      `"${finalVideo}"`,
      { stdio: 'inherit' }
    );
    console.log(`Done! Video saved to: ${finalVideo}`);
  } catch (e) {
    console.log('ffmpeg failed, saving raw video without soundtrack');
    fs.copyFileSync(rawVideo, finalVideo.replace('.mp4', '.webm'));
    console.log(`Raw video at: ${finalVideo.replace('.mp4', '.webm')}`);
  }
}

record().catch(e => {
  console.error('Recording failed:', e);
  process.exit(1);
});
