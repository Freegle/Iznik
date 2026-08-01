/**
 * HEIC photo upload.
 *
 * iPhones produce .heic files, and no browser except Safari can decode them.
 * @uppy/compressor re-encodes every image/* file through a <canvas>, so a HEIC
 * either fails to compress or produces a black JPEG. Both uploaders convert
 * HEIC to JPEG before Compressor runs (see composables/useUppyHeic.js) - this
 * checks what actually lands on the server is a JPEG, which is the thing that
 * regressed when the uploader moved from FilePond to Uppy.
 *
 * assets/item1.heic is a real HEIC (ftyp brand "heic"), encoded from item1.png
 * with libheif.
 *
 * We read the stored object back rather than inspecting the upload request:
 * tus sends the file as a binary body that Playwright does not retain, so
 * request.postDataBuffer() is null for it.
 */
const path = require('path')
const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { loginViaHomepage } = require('./utils/user')

const HEIC_FIXTURE = path.join(__dirname, 'assets', 'item1.heic')

// tus replies to the creation POST with the URL of the stored upload.
function captureTusUploads(page) {
  const uploads = []

  page.on('response', (response) => {
    if (response.request().method() !== 'POST') return
    if (!response.url().includes('tus')) return

    const location = response.headers().location
    if (!location) return

    uploads.push({
      location,
      metadata: response.request().headers()['upload-metadata'] || '',
    })
  })

  return uploads
}

function decodeTusMetadata(metadata) {
  const out = {}

  for (const pair of metadata.split(',')) {
    const [key, value] = pair.trim().split(' ')
    if (!key) continue
    out[key] = value ? Buffer.from(value, 'base64').toString('utf8') : ''
  }

  return out
}

// Fetched from inside the page: tusd allows cross-origin reads, and the node
// side of Playwright cannot resolve the container hostname.
async function fetchStoredUpload(page, url) {
  return await page.evaluate(async (target) => {
    const response = await fetch(target)
    const bytes = new Uint8Array(await response.arrayBuffer())

    return {
      status: response.status,
      contentType: response.headers.get('content-type'),
      head: Array.from(bytes.slice(0, 8)),
      length: bytes.length,
    }
  }, url)
}

function expectStoredJpeg(stored) {
  expect(stored.status).toBe(200)
  expect(stored.length).toBeGreaterThan(0)

  // JPEG SOI marker.
  expect(stored.head.slice(0, 3)).toEqual([0xff, 0xd8, 0xff])

  // An ISO base media container - which is what a HEIC is - has "ftyp" at
  // offset 4. If this fires, the raw HEIC was uploaded unconverted.
  const boxType = String.fromCharCode(...stored.head.slice(4, 8))
  expect(boxType).not.toBe('ftyp')
}

async function uploadHeicAndCheck(page, uploads) {
  const fileInput = page.locator('input[type="file"]').first()
  await fileInput.setInputFiles(HEIC_FIXTURE)

  // Conversion runs libheif in a worker before the upload starts, so allow the
  // slower API timeout rather than a plain element wait.
  await expect
    .poll(() => uploads.length, { timeout: timeouts.api.slowApi })
    .toBeGreaterThan(0)

  // The file state the uploader handed to tus - image/heic here would mean the
  // conversion never ran.
  const meta = decodeTusMetadata(uploads[0].metadata)
  if (meta.filetype) {
    expect(meta.filetype).toBe('image/jpeg')
  }
  if (meta.filename) {
    expect(meta.filename).toMatch(/\.jpg$/i)
  }

  const stored = await fetchStoredUpload(page, uploads[0].location)
  expectStoredJpeg(stored)
}

test.describe('HEIC photo upload', () => {
  // There are two uploader components and they convert independently, so cover
  // one flow through each. ChitChat is the OurUploader one; the give flow below
  // (both desktop and mobile - /give renders PostMessageTablet, which uses
  // PhotoUploader) is the PhotoUploader one.
  test('chitchat converts a HEIC photo to JPEG before uploading', async ({
    page,
    testEnv,
  }) => {
    const uploads = captureTusUploads(page)

    expect(
      await loginViaHomepage(page, testEnv.user.email, 'freegle')
    ).toBeTruthy()

    await page.gotoAndVerify('/chitchat', {
      timeout: timeouts.navigation.default,
    })

    // Reveal the uploader, then open its dashboard.
    await page
      .locator('.photo-btn')
      .first()
      .click({ timeout: timeouts.ui.appearance })
    await page
      .locator('button')
      .filter({ hasText: /^\s*Add photo\s*$/i })
      .first()
      .click({ timeout: timeouts.ui.appearance })

    await uploadHeicAndCheck(page, uploads)
  })

  test.describe('mobile give flow', () => {
    test.use({ viewport: { width: 414, height: 896 } })

    test('converts a HEIC photo to JPEG before uploading', async ({ page }) => {
      const uploads = captureTusUploads(page)

      await page.gotoAndVerify('/give/mobile/photos', {
        timeout: timeouts.navigation.default,
      })

      await page.waitForSelector('.photo-uploader, .app-give-photos', {
        timeout: timeouts.ui.appearance,
      })

      await uploadHeicAndCheck(page, uploads)
    })
  })
})
