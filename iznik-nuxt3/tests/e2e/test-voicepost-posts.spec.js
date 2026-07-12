/**
 * Voice post actually posts (not a dead-end demo), and asks for the mic politely.
 *
 * The /voicepost flow records a description, transcribes it (Groq Whisper on the
 * server) and shows a review screen. It used to STOP there with a demo "done"
 * screen. These tests lock in:
 *   1. "Looks good - post it" hands the reviewed title/description (plus the
 *      photo/type already on the compose draft) into the normal final compose
 *      step - /give/mobile/whereami - which is what actually creates the post.
 *   2. If the mic isn't already permitted, an explainer appears BEFORE the
 *      browser's own prompt, telling the user the mic is only used when recording.
 *
 * The microphone and transcription endpoints are stubbed so the flow runs
 * headlessly and deterministically. Assertions are DOM/URL only.
 */

const { test, expect } = require('./fixtures')

const CANNED = {
  title: 'Blue two-seater sofa',
  description:
    'A comfy blue two-seater sofa in good condition, free to a good home, collection only from near the station.',
  transcript:
    "erm yeah so I've got a blue two-seater sofa it's in good condition free to collect",
}

// Stub getUserMedia + MediaRecorder (no real mic). When forcePrompt is set, also
// override the Permissions API at the prototype level so navigator.permissions
// .query('microphone') reports 'prompt' - the test browser is launched with fake
// media that auto-grants, which would otherwise skip the explainer.
async function stubMicAndRecorder(page, { forcePrompt = false } = {}) {
  await page.addInitScript((opts) => {
    const fakeStream = { getTracks: () => [{ stop() {} }] }
    if (!navigator.mediaDevices) {
      Object.defineProperty(navigator, 'mediaDevices', {
        value: {},
        configurable: true,
      })
    }
    navigator.mediaDevices.getUserMedia = async () => fakeStream

    if (opts.forcePrompt && navigator.permissions) {
      try {
        const proto = Object.getPrototypeOf(navigator.permissions)
        Object.defineProperty(proto, 'query', {
          configurable: true,
          writable: true,
          value: async (desc) => ({
            state: desc && desc.name === 'microphone' ? 'prompt' : 'granted',
            onchange: null,
          }),
        })
      } catch (e) {
        /* leave native query in place */
      }
    }

    class FakeMediaRecorder {
      constructor(stream, mrOpts) {
        this.state = 'inactive'
        this.mimeType = (mrOpts && mrOpts.mimeType) || 'audio/webm'
        this.ondataavailable = null
        this.onstop = null
      }

      static isTypeSupported() {
        return true
      }

      start() {
        this.state = 'recording'
        setTimeout(() => {
          if (this.ondataavailable) {
            this.ondataavailable({ data: new Blob(['a'], { type: this.mimeType }) })
          }
        }, 30)
      }

      stop() {
        this.state = 'inactive'
        if (this.ondataavailable) {
          this.ondataavailable({ data: new Blob(['b'], { type: this.mimeType }) })
        }
        if (this.onstop) this.onstop()
      }
    }
    window.MediaRecorder = FakeMediaRecorder
  }, { forcePrompt })
}

// Open /voicepost, skip the photo and choose the voice branch. The skip click is
// retried until the choice step appears - on a loaded machine the page can still
// be hydrating when the button first renders, so a single early click no-ops.
async function goToVoiceChoice(page) {
  await page.goto('/voicepost')
  await expect(async () => {
    await page.locator('.skip-link-btn').click()
    await expect(page.locator('.choice-btn--voice')).toBeVisible({ timeout: 2500 })
  }).toPass({ timeout: 30000 })
  await page.locator('.choice-btn--voice').click()
  await expect(page.locator('.mic-btn')).toBeVisible()
}

async function mockTranscription(page) {
  await page.route('**/voicepost/chunk**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ session: 'e2e-session' }),
    })
  )
  await page.route('**/voicepost/finish**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(CANNED),
    })
  )
}

test.describe('Voice post', () => {
  test('review -> "post it" hands off to the real compose submit step', async ({
    page,
  }) => {
    await stubMicAndRecorder(page, { forcePrompt: true })
    await mockTranscription(page)

    await goToVoiceChoice(page)

    // Tap the mic, acknowledge the permission explainer, then record and stop.
    await page.locator('.mic-btn').click()
    await page.getByRole('button', { name: /ok, ask me/i }).click()
    await page.locator('.stop-btn').click() // Done

    // Review shows the transcribed title/description.
    await expect(page.locator('#vp-title')).toHaveValue(CANNED.title)
    await expect(page.locator('#vp-desc')).toHaveValue(CANNED.description)

    // Post it -> hand off to the REAL final step (was a demo dead-end before).
    await page.getByRole('button', { name: /looks good.*post it/i }).click()

    await expect(page).toHaveURL(/\/give\/mobile\/whereami/)
    await expect(page.getByText(/tell us where it is/i)).toBeVisible()
  })

  test('explains the mic before asking, when permission is not yet granted', async ({
    page,
  }) => {
    await stubMicAndRecorder(page, { forcePrompt: true })
    await mockTranscription(page)

    await goToVoiceChoice(page)

    // Tapping the mic explains first (permission is 'prompt', not granted).
    await page.locator('.mic-btn').click()
    await expect(
      page.getByText(/only use it while you're recording/i)
    ).toBeVisible()

    // Continuing from the explainer starts the (stubbed) recording.
    await page.getByRole('button', { name: /ok, ask me/i }).click()
    await expect(page.getByText(/Listening/i)).toBeVisible()
  })
})
