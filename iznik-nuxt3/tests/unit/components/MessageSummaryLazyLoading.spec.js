import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'
import { describe, it, expect } from 'vitest'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

describe('MessageSummary image lazy loading', () => {
  // Bug: reporter saw "30 seconds for pictures to load / can't do anything until photos load"
  // on Honor 200 lite (slow 4G). Root cause: the message photo rendered through a bare
  // NuxtPicture with no `loading` attribute, so every image loaded eagerly and saturated mobile
  // bandwidth. That branch existed to serve Uploadcare images and has gone with Uploadcare; the
  // photo now renders through OurUploadedImage, which defaults to loading="lazy". These guard
  // the behaviour rather than the specific element, so the regression cannot come back either
  // through the old branch or a new one.
  const source = readFileSync(
    resolve(__dirname, '../../../components/MessageSummary.vue'),
    'utf-8'
  )

  it('renders the message photo through OurUploadedImage, which is lazy by default', () => {
    expect(source).toContain('<OurUploadedImage')
  })

  it('has no NuxtPicture that omits a loading attribute', () => {
    const blocks = source.match(/<NuxtPicture\b[\s\S]*?\/>/g) || []
    for (const block of blocks) {
      expect(
        block,
        'a NuxtPicture in MessageSummary.vue must set :loading= or it loads eagerly on mobile'
      ).toContain(':loading=')
    }
  })
})
