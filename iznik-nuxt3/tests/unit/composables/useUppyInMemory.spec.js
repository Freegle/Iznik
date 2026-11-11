import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  createInMemoryPreProcessor,
  READ_RETRY_DELAY_MS,
} from '~/composables/useUppyInMemory'

const mockAction = vi.hoisted(() => vi.fn())
vi.mock('~/composables/useClientLog', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, action: (...args) => mockAction(...args) }
})

function fakeUppy(files = []) {
  const state = new Map(files.map((f) => [f.id, f]))

  return {
    getFile: (id) => state.get(id),
    setFileState: vi.fn((id, patch) => {
      state.set(id, { ...state.get(id), ...patch })
    }),
    removeFile: vi.fn((id) => state.delete(id)),
    peek: (id) => state.get(id),
  }
}

function pickedFile(data, overrides = {}) {
  return {
    id: 'file-1',
    name: 'photo.jpg',
    type: 'image/jpeg',
    size: data.size,
    data,
    meta: { name: 'photo.jpg', type: 'image/jpeg' },
    ...overrides,
  }
}

function notReadable() {
  return Promise.reject(
    Object.assign(new Error('The requested file could not be read'), {
      name: 'NotReadableError',
    })
  )
}

// Runs the pre-processor through its retry wait without really waiting.
async function run(preProcessor, fileIDs) {
  const done = preProcessor(fileIDs)
  await vi.advanceTimersByTimeAsync(READ_RETRY_DELAY_MS)
  await done
}

describe('createInMemoryPreProcessor', () => {
  beforeEach(() => {
    mockAction.mockClear()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('replaces the picked file with an in-memory copy of the same bytes', async () => {
    const original = new File(['abcdef'], 'photo.jpg', {
      type: 'image/jpeg',
      lastModified: 1234,
    })
    const uppy = fakeUppy([pickedFile(original)])

    await run(
      createInMemoryPreProcessor({ getUppy: () => uppy, uploader: 'photo' }),
      ['file-1']
    )

    const copy = uppy.peek('file-1').data
    expect(copy).not.toBe(original)
    expect(copy).toBeInstanceOf(File)
    expect(copy.name).toBe('photo.jpg')
    expect(copy.type).toBe('image/jpeg')
    expect(copy.lastModified).toBe(1234)
    expect(await copy.text()).toBe('abcdef')
    expect(uppy.peek('file-1').size).toBe(6)
    expect(uppy.removeFile).not.toHaveBeenCalled()
    expect(mockAction).not.toHaveBeenCalled()
  })

  it('uses a second read when the first fails, and logs that it recovered', async () => {
    const bytes = new TextEncoder().encode('xyz').buffer
    const flaky = {
      size: 3,
      type: 'image/jpeg',
      arrayBuffer: vi
        .fn()
        .mockImplementationOnce(notReadable)
        .mockResolvedValueOnce(bytes),
    }
    const uppy = fakeUppy([pickedFile(flaky)])
    const onUnreadable = vi.fn()

    await run(
      createInMemoryPreProcessor({
        getUppy: () => uppy,
        uploader: 'photo',
        onUnreadable,
      }),
      ['file-1']
    )

    expect(flaky.arrayBuffer).toHaveBeenCalledTimes(2)
    expect(await uppy.peek('file-1').data.text()).toBe('xyz')
    expect(uppy.removeFile).not.toHaveBeenCalled()
    expect(onUnreadable).not.toHaveBeenCalled()
    expect(mockAction).toHaveBeenCalledWith(
      'upload_file_unreadable',
      expect.objectContaining({
        error_name: 'NotReadableError',
        recovered: true,
      })
    )
  })

  it('drops a photo the phone will not let us read, logs why, and says so', async () => {
    const unreadable = {
      size: 263997,
      type: 'image/jpeg',
      arrayBuffer: vi.fn(notReadable),
    }
    const uppy = fakeUppy([pickedFile(unreadable)])
    const onUnreadable = vi.fn()

    await run(
      createInMemoryPreProcessor({
        getUppy: () => uppy,
        uploader: 'our',
        onUnreadable,
      }),
      ['file-1']
    )

    expect(unreadable.arrayBuffer).toHaveBeenCalledTimes(2)
    expect(uppy.setFileState).not.toHaveBeenCalled()
    // Removed, so it does not go on to fail as a network error.
    expect(uppy.removeFile).toHaveBeenCalledWith('file-1')
    expect(onUnreadable).toHaveBeenCalledWith(1)
    expect(mockAction).toHaveBeenCalledWith(
      'upload_file_unreadable',
      expect.objectContaining({
        uploader: 'our',
        error_name: 'NotReadableError',
        reason: 'The requested file could not be read',
        file_size: 263997,
        file_type: 'image/jpeg',
        recovered: false,
      })
    )
  })

  it('reads every file in the batch, even after one fails', async () => {
    const bad = {
      size: 1,
      type: 'image/jpeg',
      arrayBuffer: () => Promise.reject(new Error('nope')),
    }
    const good = new File(['xy'], 'b.jpg', { type: 'image/jpeg' })
    const uppy = fakeUppy([
      pickedFile(bad, { id: 'a' }),
      pickedFile(good, { id: 'b', name: 'b.jpg' }),
    ])
    const onUnreadable = vi.fn()

    await run(
      createInMemoryPreProcessor({
        getUppy: () => uppy,
        uploader: 'photo',
        onUnreadable,
      }),
      ['a', 'b']
    )

    expect(uppy.removeFile).toHaveBeenCalledWith('a')
    expect(uppy.removeFile).not.toHaveBeenCalledWith('b')
    expect(await uppy.peek('b').data.text()).toBe('xy')
    // Told once for the batch, not once per photo.
    expect(onUnreadable).toHaveBeenCalledTimes(1)
  })

  it('still drops an unreadable photo when nobody is listening', async () => {
    const bad = { size: 1, type: 'image/jpeg', arrayBuffer: vi.fn(notReadable) }
    const uppy = fakeUppy([pickedFile(bad)])

    await run(
      createInMemoryPreProcessor({ getUppy: () => uppy, uploader: 'photo' }),
      ['file-1']
    )

    expect(uppy.removeFile).toHaveBeenCalledWith('file-1')
  })

  it('ignores files with nothing to read and a missing Uppy', async () => {
    const uppy = fakeUppy([{ id: 'remote', data: {} }])
    const pre = createInMemoryPreProcessor({
      getUppy: () => uppy,
      uploader: 'photo',
    })

    await run(pre, ['remote', 'missing'])
    await run(
      createInMemoryPreProcessor({ getUppy: () => null, uploader: 'x' }),
      ['remote']
    )

    expect(uppy.setFileState).not.toHaveBeenCalled()
    expect(uppy.removeFile).not.toHaveBeenCalled()
  })
})
