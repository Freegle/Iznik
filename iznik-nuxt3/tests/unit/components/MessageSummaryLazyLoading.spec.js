import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

describe('MessageSummary image lazy loading', () => {
  it('NuxtPicture for externaluid must have :loading attribute to prevent render-blocking on mobile', () => {
    // Bug: reporter saw "30 seconds for pictures to load / can't do anything until photos load"
    // on Honor 200 lite (slow 4G). Root cause: the NuxtPicture for externaluid (Uploadcare) images
    // had no `loading` attribute, so all images loaded eagerly, saturating mobile bandwidth.
    // OurUploadedImage and ProxyImage both correctly default to loading="lazy"; NuxtPicture must too.
    const source = readFileSync(
      resolve(__dirname, '../../../components/MessageSummary.vue'),
      'utf-8'
    )
    const idx = source.indexOf('v-else-if="message.attachments[0]?.externaluid"')
    expect(idx, 'externaluid branch should exist in MessageSummary template').toBeGreaterThan(-1)
    const end = source.indexOf('/>', idx)
    const nuxtPictureBlock = source.substring(idx, end)
    expect(
      nuxtPictureBlock,
      'NuxtPicture for externaluid must include :loading= to prevent eager loading on mobile'
    ).toContain(':loading=')
  })
})
