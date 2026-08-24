import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  isHeicFile,
  createHeicPreProcessor,
  createHeicSafeCompressor,
} from '~/composables/useUppyHeic'

const mockHeicTo = vi.hoisted(() => vi.fn())
vi.mock('heic-to', () => ({ heicTo: (...args) => mockHeicTo(...args) }))

const mockAction = vi.hoisted(() => vi.fn())
vi.mock('~/composables/useClientLog', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, action: (...args) => mockAction(...args) }
})

function fakeBlob(size, type = 'image/jpeg') {
  return { size, type }
}

function fakeUppy(files = []) {
  const state = new Map(files.map((f) => [f.id, f]))

  return {
    getFile: (id) => state.get(id),
    setFileState: vi.fn((id, patch) => {
      state.set(id, { ...state.get(id), ...patch })
    }),
    info: vi.fn(),
    // Test-only peek at what the file looks like now.
    peek: (id) => state.get(id),
  }
}

function heicFile(overrides = {}) {
  return {
    id: 'file-1',
    name: 'IMG_0001.HEIC',
    type: 'image/heic',
    size: 4000000,
    data: fakeBlob(4000000, 'image/heic'),
    meta: { name: 'IMG_0001.HEIC', type: 'image/heic' },
    ...overrides,
  }
}

function jpegFile(overrides = {}) {
  return {
    id: 'file-jpeg',
    name: 'photo.jpg',
    type: 'image/jpeg',
    size: 1000,
    data: fakeBlob(1000),
    meta: { name: 'photo.jpg', type: 'image/jpeg' },
    ...overrides,
  }
}

describe('isHeicFile', () => {
  it.each([
    'image/heic',
    'image/heif',
    'image/heic-sequence',
    'image/heif-sequence',
  ])('recognises the %s MIME type', (type) => {
    expect(isHeicFile({ type })).toBe(true)
  })

  it('recognises the MIME type on the underlying blob', () => {
    expect(isHeicFile({ type: '', data: fakeBlob(1, 'image/heic') })).toBe(true)
  })

  it('recognises a .heic extension when the browser gives no type', () => {
    expect(isHeicFile({ type: '', name: 'IMG_0001.HEIC' })).toBe(true)
  })

  it('recognises a .heif extension', () => {
    expect(isHeicFile({ type: '', name: 'photo.heif' })).toBe(true)
  })

  it('falls back to the name in meta', () => {
    expect(isHeicFile({ meta: { name: 'IMG_0002.heic' } })).toBe(true)
  })

  it('does not treat a JPEG as HEIC', () => {
    expect(isHeicFile(jpegFile())).toBe(false)
  })

  it('does not treat a name merely containing heic as HEIC', () => {
    expect(isHeicFile({ type: 'image/jpeg', name: 'my-heic-photos.jpg' })).toBe(
      false
    )
  })

  it.each([null, undefined])('handles %s', (file) => {
    expect(isHeicFile(file)).toBe(false)
  })
})

describe('createHeicPreProcessor', () => {
  beforeEach(() => {
    mockHeicTo.mockReset()
    mockAction.mockReset()
    vi.spyOn(console, 'log').mockImplementation(() => {})
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('does nothing when there are no HEIC files', async () => {
    const uppy = fakeUppy([jpegFile()])
    const preProcess = createHeicPreProcessor({
      getUppy: () => uppy,
      uploader: 'our',
    })

    await preProcess(['file-jpeg'])

    expect(mockHeicTo).not.toHaveBeenCalled()
    expect(uppy.setFileState).not.toHaveBeenCalled()
    expect(uppy.info).not.toHaveBeenCalled()
  })

  it('does not throw when there is no uppy instance', async () => {
    const preProcess = createHeicPreProcessor({
      getUppy: () => null,
      uploader: 'our',
    })

    await expect(preProcess(['file-1'])).resolves.toBeUndefined()
  })

  it('converts a HEIC file to JPEG in place', async () => {
    const converted = fakeBlob(900000)
    mockHeicTo.mockResolvedValue(converted)
    const uppy = fakeUppy([heicFile()])
    const preProcess = createHeicPreProcessor({
      getUppy: () => uppy,
      uploader: 'our',
    })

    await preProcess(['file-1'])

    expect(mockHeicTo).toHaveBeenCalledWith({
      blob: expect.objectContaining({ type: 'image/heic' }),
      type: 'image/jpeg',
      quality: 0.92,
    })
    expect(uppy.setFileState).toHaveBeenCalledWith('file-1', {
      data: expect.any(File),
      type: 'image/jpeg',
      extension: 'jpg',
      size: 900000,
      name: 'IMG_0001.jpg',
      meta: { name: 'IMG_0001.jpg', type: 'image/jpeg' },
    })
    // The data handed on must be a NAMED File, not a bare Blob: Compressor
    // rebuilds the upload filename from its input's name after compressing,
    // and a nameless blob compresses to a nameless blob, so the rebuilt name
    // ends ".undefined" and the upload is stored as "item1.undefined".
    const handedOn = uppy.setFileState.mock.calls[0][1].data
    expect(handedOn.name).toBe('IMG_0001.jpg')
    expect(handedOn.type).toBe('image/jpeg')
  })

  it('tells the user conversion is happening', async () => {
    mockHeicTo.mockResolvedValue(fakeBlob(1000))
    const uppy = fakeUppy([heicFile()])

    await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
      'file-1',
    ])

    expect(uppy.info).toHaveBeenCalledWith(
      expect.stringContaining('Converting your photo'),
      'info',
      expect.any(Number)
    )
  })

  it('logs conversion telemetry', async () => {
    mockHeicTo.mockResolvedValue(fakeBlob(900000))
    const uppy = fakeUppy([heicFile()])

    await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
      'file-1',
    ])

    expect(mockAction).toHaveBeenCalledWith('upload_heic_selected', {
      uploader: 'our',
      file_count: 1,
    })
    expect(mockAction).toHaveBeenCalledWith(
      'upload_heic_converted',
      expect.objectContaining({
        uploader: 'our',
        original_size: 4000000,
        converted_size: 900000,
      })
    )
  })

  it('only converts the HEIC files in a mixed batch', async () => {
    mockHeicTo.mockResolvedValue(fakeBlob(1000))
    const uppy = fakeUppy([jpegFile(), heicFile()])

    await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
      'file-jpeg',
      'file-1',
    ])

    expect(mockHeicTo).toHaveBeenCalledTimes(1)
    expect(uppy.setFileState).toHaveBeenCalledTimes(1)
    expect(uppy.setFileState).toHaveBeenCalledWith(
      'file-1',
      expect.objectContaining({ type: 'image/jpeg' })
    )
  })

  it('converts multiple HEIC files one at a time', async () => {
    let inFlight = 0
    let maxInFlight = 0
    mockHeicTo.mockImplementation(async () => {
      inFlight++
      maxInFlight = Math.max(maxInFlight, inFlight)
      await Promise.resolve()
      inFlight--
      return fakeBlob(1000)
    })
    const uppy = fakeUppy([
      heicFile({ id: 'a', name: 'a.heic', meta: { name: 'a.heic' } }),
      heicFile({ id: 'b', name: 'b.heic', meta: { name: 'b.heic' } }),
    ])

    await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
      'a',
      'b',
    ])

    expect(mockHeicTo).toHaveBeenCalledTimes(2)
    expect(maxInFlight).toBe(1)
  })

  it('names the converted file .jpg even when the original had no extension', async () => {
    mockHeicTo.mockResolvedValue(fakeBlob(500))
    const uppy = fakeUppy([
      heicFile({ name: 'photo', meta: { name: 'photo', type: 'image/heic' } }),
    ])

    await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
      'file-1',
    ])

    expect(uppy.setFileState).toHaveBeenCalledWith(
      'file-1',
      expect.objectContaining({ name: 'photo.jpg' })
    )
  })

  describe('when conversion fails', () => {
    it('leaves the original file untouched', async () => {
      mockHeicTo.mockRejectedValue(new Error('libheif exploded'))
      const uppy = fakeUppy([heicFile()])

      await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
        'file-1',
      ])

      expect(uppy.setFileState).not.toHaveBeenCalled()
      expect(uppy.peek('file-1').type).toBe('image/heic')
    })

    it('warns the user rather than failing silently', async () => {
      mockHeicTo.mockRejectedValue(new Error('libheif exploded'))
      const uppy = fakeUppy([heicFile()])

      await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
        'file-1',
      ])

      expect(uppy.info).toHaveBeenCalledWith(
        expect.stringContaining("couldn't convert"),
        'warning',
        expect.any(Number)
      )
    })

    it('logs the failure', async () => {
      mockHeicTo.mockRejectedValue(new Error('libheif exploded'))
      const uppy = fakeUppy([heicFile()])

      await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'photo' })([
        'file-1',
      ])

      expect(mockAction).toHaveBeenCalledWith(
        'upload_heic_convert_failed',
        expect.objectContaining({
          uploader: 'photo',
          reason: 'libheif exploded',
        })
      )
    })

    it('records a bare string rejection, which heic-to can throw', async () => {
      mockHeicTo.mockRejectedValue("Can't convert canvas to blob.")
      const uppy = fakeUppy([heicFile()])

      await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
        'file-1',
      ])

      expect(mockAction).toHaveBeenCalledWith(
        'upload_heic_convert_failed',
        expect.objectContaining({ reason: "Can't convert canvas to blob." })
      )
    })

    it('treats an empty conversion result as a failure', async () => {
      mockHeicTo.mockResolvedValue(fakeBlob(0))
      const uppy = fakeUppy([heicFile()])

      await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
        'file-1',
      ])

      expect(uppy.setFileState).not.toHaveBeenCalled()
      expect(uppy.info).toHaveBeenCalledWith(
        expect.stringContaining("couldn't convert"),
        'warning',
        expect.any(Number)
      )
    })

    it('carries on with the rest of the batch', async () => {
      mockHeicTo
        .mockRejectedValueOnce(new Error('libheif exploded'))
        .mockResolvedValueOnce(fakeBlob(500))
      const uppy = fakeUppy([
        heicFile({ id: 'a', name: 'a.heic', meta: { name: 'a.heic' } }),
        heicFile({ id: 'b', name: 'b.heic', meta: { name: 'b.heic' } }),
      ])

      await createHeicPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
        'a',
        'b',
      ])

      expect(uppy.setFileState).toHaveBeenCalledTimes(1)
      expect(uppy.setFileState).toHaveBeenCalledWith(
        'b',
        expect.objectContaining({ type: 'image/jpeg' })
      )
    })

    it('gives up rather than hanging when conversion never finishes', async () => {
      vi.useFakeTimers()
      mockHeicTo.mockImplementation(() => new Promise(() => {}))
      const uppy = fakeUppy([heicFile()])

      const done = createHeicPreProcessor({
        getUppy: () => uppy,
        uploader: 'our',
      })(['file-1'])

      await vi.advanceTimersByTimeAsync(45000)
      await done

      expect(uppy.setFileState).not.toHaveBeenCalled()
      expect(mockAction).toHaveBeenCalledWith(
        'upload_heic_convert_failed',
        expect.objectContaining({ reason: 'HEIC conversion timed out' })
      )
    })
  })
})

describe('createHeicSafeCompressor', () => {
  let prepared

  function baseCompressor() {
    prepared = []
    return class Base {
      constructor(uppy) {
        this.uppy = uppy
      }

      prepareUpload(fileIDs) {
        prepared.push(fileIDs)
        return Promise.resolve()
      }
    }
  }

  beforeEach(() => {
    vi.spyOn(console, 'log').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('passes non-HEIC files through to the real compressor', async () => {
    const Safe = createHeicSafeCompressor(baseCompressor())
    const instance = new Safe(fakeUppy([jpegFile()]))

    await instance.prepareUpload(['file-jpeg'])

    expect(prepared).toEqual([['file-jpeg']])
  })

  it('keeps HEIC files away from the canvas re-encode', async () => {
    const Safe = createHeicSafeCompressor(baseCompressor())
    const instance = new Safe(fakeUppy([jpegFile(), heicFile()]))

    await instance.prepareUpload(['file-jpeg', 'file-1'])

    expect(prepared).toEqual([['file-jpeg']])
  })

  it('still calls the real compressor when every file is HEIC', async () => {
    const Safe = createHeicSafeCompressor(baseCompressor())
    const instance = new Safe(fakeUppy([heicFile()]))

    await instance.prepareUpload(['file-1'])

    expect(prepared).toEqual([[]])
  })

  it('does not throw without an uppy instance', async () => {
    const Safe = createHeicSafeCompressor(baseCompressor())
    const instance = new Safe(undefined)

    await expect(instance.prepareUpload(['file-1'])).resolves.toBeUndefined()
  })

  it('copes with no file IDs at all', async () => {
    const Safe = createHeicSafeCompressor(baseCompressor())
    const instance = new Safe(fakeUppy([]))

    await instance.prepareUpload(undefined)

    expect(prepared).toEqual([[]])
  })
})
