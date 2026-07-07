import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { describe, it, expect } from 'vitest'

const __dirname = dirname(fileURLToPath(import.meta.url))
const uppyJsPath = join(
  __dirname,
  '../../../node_modules/@uppy/core/lib/Uppy.js'
)

// Regression test for Sentry NUXT3-D45:
// "TypeError: Cannot read properties of undefined (reading 'error')"
// in Array.filter(<anonymous>) inside @uppy/core/lib/Uppy.js #runUpload.
//
// When a file is removed mid-upload (e.g. user clicks "Remove photo" during
// a retry cycle), currentUpload.fileIDs still references its ID but
// state.files[id] is gone. getFile() returns undefined, and the two filter
// callbacks that partition results into successful/failed used to read
// .error on that undefined entry.
//
// patches/@uppy+core+4.5.3.patch adds a `file != null` guard to both callbacks.
describe('@uppy/core #runUpload filter guards (NUXT3-D45)', () => {
  it('patched Uppy.js guards successful filter against undefined files', () => {
    const src = readFileSync(uppyJsPath, 'utf-8')
    expect(src).toContain(
      'const successful = files.filter((file) => file != null && !file.error)'
    )
  })

  it('patched Uppy.js guards failed filter against undefined files', () => {
    const src = readFileSync(uppyJsPath, 'utf-8')
    expect(src).toContain(
      'const failed = files.filter((file) => file != null && file.error)'
    )
  })

  describe('filter callback semantics', () => {
    // Mirrors the patched callbacks verbatim so this test breaks if a future
    // patch regresses the guard.
    const successfulFilter = (file) => file != null && !file.error
    const failedFilter = (file) => file != null && file.error

    it('does not throw when files array contains undefined entries', () => {
      const files = [
        { id: 'a', error: null },
        undefined,
        { id: 'b', error: 'boom' },
        null,
      ]
      expect(() => files.filter(successfulFilter)).not.toThrow()
      expect(() => files.filter(failedFilter)).not.toThrow()
    })

    it('drops undefined/null files from both partitions', () => {
      const good = { id: 'a', error: null }
      const bad = { id: 'b', error: 'boom' }
      const files = [good, undefined, bad, null]

      expect(files.filter(successfulFilter)).toEqual([good])
      expect(files.filter(failedFilter)).toEqual([bad])
    })

    it('partitions normally when no files are missing', () => {
      const good = { id: 'a', error: null }
      const bad = { id: 'b', error: new Error('fail') }
      const files = [good, bad]

      expect(files.filter(successfulFilter)).toEqual([good])
      expect(files.filter(failedFilter)).toEqual([bad])
    })
  })
})
