import { describe, it, expect } from 'vitest'
import {
  filterNonFailedFiles,
  filterFilesToEmitUploadStarted,
} from '@uppy/utils'

// Regression test for Sentry NUXT3-D2C:
// "TypeError: Cannot use 'in' operator to search for 'error' in undefined"
// The upstream @uppy/utils fileFilters.ts callbacks blew up when the
// files array contained undefined entries — which happens in practice
// because Uppy's getFilesByIds maps unknown IDs to undefined. Our
// patches/@uppy+utils+6.2.2.patch guards both callbacks with a null check.
describe('@uppy/utils fileFilters (patched)', () => {
  describe('filterNonFailedFiles', () => {
    it('does not throw when array contains undefined entries', () => {
      const files = [
        { id: 'a', progress: {} },
        undefined,
        { id: 'b', progress: {}, error: 'boom' },
        null,
        { id: 'c', progress: {} },
      ]
      expect(() => filterNonFailedFiles(files)).not.toThrow()
    })

    it('drops undefined, null, and errored files', () => {
      const good1 = { id: 'a', progress: {} }
      const good2 = { id: 'c', progress: {} }
      const files = [
        good1,
        undefined,
        { id: 'b', progress: {}, error: 'boom' },
        null,
        good2,
      ]
      const result = filterNonFailedFiles(files)
      expect(result).toEqual([good1, good2])
    })

    it('returns all files when none are failed or undefined', () => {
      const files = [
        { id: 'a', progress: {} },
        { id: 'b', progress: {} },
      ]
      expect(filterNonFailedFiles(files)).toEqual(files)
    })
  })

  describe('filterFilesToEmitUploadStarted', () => {
    it('does not throw when array contains undefined entries', () => {
      const files = [
        { id: 'a', progress: { uploadStarted: null }, isRestored: false },
        undefined,
        { id: 'b', progress: { uploadStarted: 1 }, isRestored: true },
        null,
      ]
      expect(() => filterFilesToEmitUploadStarted(files)).not.toThrow()
    })

    it('skips undefined and null without emitting', () => {
      const fresh = {
        id: 'a',
        progress: { uploadStarted: null },
        isRestored: false,
      }
      const restoredAlreadyStarted = {
        id: 'b',
        progress: { uploadStarted: 1 },
        isRestored: true,
      }
      const files = [fresh, undefined, restoredAlreadyStarted, null]
      expect(filterFilesToEmitUploadStarted(files)).toEqual([fresh])
    })
  })
})
