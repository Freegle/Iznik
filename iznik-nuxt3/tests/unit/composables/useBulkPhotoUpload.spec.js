import { describe, it, expect, vi, beforeEach } from 'vitest'

import { useBulkPhotoUpload } from '~/composables/useBulkPhotoUpload'

// ============================================================
// Mocks — must be declared before any vi.mock() calls
// ============================================================
const mockImageStorePost = vi.fn()
vi.mock('~/stores/image', () => ({
  useImageStore: () => ({
    post: mockImageStorePost,
  }),
}))

vi.stubGlobal('useRuntimeConfig', () => ({
  public: { TUS_UPLOADER: 'https://tus.example.com/files/' },
}))

// Drives the mocked tus.Upload's start(): a hoisted config object the test
// bodies mutate before calling uploadPhoto(), since the constructor call
// happens asynchronously (after the dynamic import resolves).
const tusState = vi.hoisted(() => ({
  previousUploads: [],
  progress: null, // [uploaded, total] or null to skip
  outcome: 'success', // 'success' | 'error'
  error: new Error('tus upload failed'),
  uploadUrl: 'https://tus.example.com/files/abc123',
}))
const mockFindPreviousUploads = vi.fn()
const mockResumeFromPreviousUpload = vi.fn()
const mockStart = vi.fn()
let capturedOptions

vi.mock('tus-js-client', () => ({
  Upload: vi.fn(function (file, options) {
    capturedOptions = options
    this.file = file
    this.options = options
    this.url = tusState.uploadUrl
    this.findPreviousUploads = mockFindPreviousUploads
    this.resumeFromPreviousUpload = mockResumeFromPreviousUpload
    this.start = mockStart
  }),
}))

describe('useBulkPhotoUpload', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    tusState.previousUploads = []
    tusState.progress = null
    tusState.outcome = 'success'
    tusState.uploadUrl = 'https://tus.example.com/files/abc123'
    capturedOptions = undefined

    mockFindPreviousUploads.mockImplementation(() =>
      Promise.resolve(tusState.previousUploads)
    )
    mockStart.mockImplementation(() => {
      if (tusState.progress) {
        capturedOptions.onProgress(...tusState.progress)
      }
      if (tusState.outcome === 'error') {
        capturedOptions.onError(tusState.error)
      } else {
        capturedOptions.onSuccess()
      }
    })
    mockImageStorePost.mockResolvedValue({
      id: 1,
      url: 'https://cdn.example.com/photo.jpg',
      uid: 'uid-1',
      info: { width: 100, height: 100 },
    })
  })

  it('uploads a fresh file (no previous uploads) and registers the attachment', async () => {
    const { uploadPhoto } = useBulkPhotoUpload()
    const file = new Blob(['x'])

    const result = await uploadPhoto(file)

    expect(mockResumeFromPreviousUpload).not.toHaveBeenCalled()
    expect(mockStart).toHaveBeenCalled()
    expect(mockImageStorePost).toHaveBeenCalledWith({
      imgtype: 'Message',
      externaluid: 'freegletusd-abc123',
      externalmods: {},
      recognise: false,
    })
    expect(result).toEqual({
      id: 1,
      path: 'https://cdn.example.com/photo.jpg',
      paththumb: 'https://cdn.example.com/photo.jpg',
      ouruid: 'uid-1',
      info: { width: 100, height: 100 },
    })
  })

  it('resumes from a previous upload when one is found', async () => {
    tusState.previousUploads = [{ id: 'prev-1' }]
    const { uploadPhoto } = useBulkPhotoUpload()

    await uploadPhoto(new Blob(['x']))

    expect(mockResumeFromPreviousUpload).toHaveBeenCalledWith({
      id: 'prev-1',
    })
    expect(mockStart).toHaveBeenCalled()
  })

  it('defaults the attachment type to Message when none is given', async () => {
    const { uploadPhoto } = useBulkPhotoUpload()
    await uploadPhoto(new Blob(['x']))
    expect(mockImageStorePost).toHaveBeenCalledWith(
      expect.objectContaining({ imgtype: 'Message' })
    )
  })

  it('passes a custom attachment type through', async () => {
    const { uploadPhoto } = useBulkPhotoUpload()
    await uploadPhoto(new Blob(['x']), { type: 'Item' })
    expect(mockImageStorePost).toHaveBeenCalledWith(
      expect.objectContaining({ imgtype: 'Item' })
    )
  })

  it('reports rounded progress when a callback and a non-zero total are given', async () => {
    tusState.progress = [50, 200]
    const onProgress = vi.fn()
    const { uploadPhoto } = useBulkPhotoUpload()

    await uploadPhoto(new Blob(['x']), { onProgress })

    expect(onProgress).toHaveBeenCalledWith(25)
  })

  it('does not call onProgress when total is zero', async () => {
    tusState.progress = [0, 0]
    const onProgress = vi.fn()
    const { uploadPhoto } = useBulkPhotoUpload()

    await uploadPhoto(new Blob(['x']), { onProgress })

    expect(onProgress).not.toHaveBeenCalled()
  })

  it('does not blow up when no onProgress callback is supplied', async () => {
    tusState.progress = [10, 100]
    const { uploadPhoto } = useBulkPhotoUpload()

    await expect(uploadPhoto(new Blob(['x']))).resolves.toBeDefined()
  })

  it('derives the externaluid from the tail of the tus upload URL', async () => {
    tusState.uploadUrl = 'https://tus.example.com/files/deep/path/xyz789'
    const { uploadPhoto } = useBulkPhotoUpload()

    await uploadPhoto(new Blob(['x']))

    expect(mockImageStorePost).toHaveBeenCalledWith(
      expect.objectContaining({ externaluid: 'freegletusd-xyz789' })
    )
  })

  it('rejects when the tus upload errors out', async () => {
    tusState.outcome = 'error'
    tusState.error = new Error('network blip')
    const { uploadPhoto } = useBulkPhotoUpload()

    await expect(uploadPhoto(new Blob(['x']))).rejects.toThrow('network blip')
    expect(mockImageStorePost).not.toHaveBeenCalled()
  })
})
