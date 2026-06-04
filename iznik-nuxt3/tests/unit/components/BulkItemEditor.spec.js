import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

// Stub heavy children so their imports (Uppy etc.) don't load.
vi.mock('~/components/PhotoUploader', () => ({
  default: {
    name: 'PhotoUploader',
    template: '<div class="photo-uploader-stub" />',
    props: [
      'modelValue',
      'type',
      'maxPhotos',
      'recognise',
      'emptyTitle',
      'emptySubtitle',
    ],
  },
}))

// Mock the upload composable so the per-row "Add photo" tests don't touch TUS
// or the image store — the component just needs a photo object back.
const uploadPhotoMock = vi.fn()
vi.mock('~/composables/useBulkPhotoUpload', () => ({
  useBulkPhotoUpload: () => ({ uploadPhoto: uploadPhotoMock }),
}))
import BulkItemEditor from '~/components/BulkItemEditor.vue'

// Auto-stub the bootstrap-vue-next form components this component uses that the
// global test setup doesn't already stub (the setup throws on unresolved
// components). We exercise logic via the exposed methods, not the DOM.
const mountOpts = {
  global: {
    stubs: {
      'b-form-input': true,
      'b-form-textarea': true,
      'b-form-group': true,
      'b-form-select': true,
    },
  },
}

describe('BulkItemEditor', () => {
  it('starts with one blank row', () => {
    const w = mount(BulkItemEditor, mountOpts)
    expect(w.vm.items).toHaveLength(1)
    expect(w.vm.items[0].name).toBe('')
  })

  it('adds and removes rows', () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.addItem()
    expect(w.vm.items).toHaveLength(2)
    w.vm.removeItem(0)
    expect(w.vm.items).toHaveLength(1)
  })

  it('removing the last remaining row leaves one blank row', () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.removeItem(0)
    expect(w.vm.items).toHaveLength(1)
    expect(w.vm.items[0].name).toBe('')
  })

  it('starts with no add-method chosen (gated until the user picks)', () => {
    const w = mount(BulkItemEditor, mountOpts)
    expect(w.vm.mode).toBe(null)
  })

  it('imports items from uploaded spreadsheet text, replacing the blank row', async () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.applyImport('Desk,4,Good\nChair,14,Used')
    await nextTick()
    expect(w.vm.items.map((i) => i.name)).toEqual(['Desk', 'Chair'])
    expect(w.vm.items[0].quantity).toBe(4)
    expect(w.vm.items[1].condition).toBe('Used')
    // Importing reveals the table so the rows can be edited.
    expect(w.vm.mode).toBe('manual')
  })

  it('derives attachment ids from uploaded photos and emits them', async () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.items[0].name = 'Desk'
    w.vm.items[0].photos = [{ id: 7 }, { id: 'notnumeric' }, { id: 9 }]
    await nextTick()
    expect(w.vm.items[0].attachments).toEqual([7, 9])
    expect(w.emitted('update:modelValue')).toBeTruthy()
  })

  it('tap-to-assign moves a tray photo onto the tapped item (touch fallback)', async () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.items[0].name = 'Desk'
    w.vm.tray.push({ id: 11 }, { id: 12 })
    await nextTick()

    // Tap the second tray photo to pick it up, then tap the item.
    w.vm.onTrayPhotoClick(1)
    expect(w.vm.selectedTrayPhoto).toBe(1)
    w.vm.onRowPhotoClick(0)
    await nextTick()

    expect(w.vm.items[0].photos.map((p) => p.id)).toEqual([12])
    expect(w.vm.tray.map((p) => p.id)).toEqual([11]) // the other stays
    expect(w.vm.selectedTrayPhoto).toBe(null) // selection cleared after assign
  })

  it('tapping a picked-up tray photo again cancels the selection', async () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.tray.push({ id: 11 })
    await nextTick()
    w.vm.onTrayPhotoClick(0)
    expect(w.vm.selectedTrayPhoto).toBe(0)
    w.vm.onTrayPhotoClick(0)
    expect(w.vm.selectedTrayPhoto).toBe(null)
    // Tapping an item with nothing picked up does nothing.
    w.vm.onRowPhotoClick(0)
    expect(w.vm.items[0].photos).toHaveLength(0)
    expect(w.vm.tray).toHaveLength(1)
  })

  it('per-row Add photo uploads the file and attaches it to that item', async () => {
    uploadPhotoMock.mockResolvedValueOnce({ id: 42, path: 'u', paththumb: 't' })
    const w = mount(BulkItemEditor, mountOpts)
    const item = w.vm.items[0]
    item.name = 'Desk'

    w.vm.addPhotoToRow(item)
    await w.vm.onRowFile({ target: { files: [new Blob(['x'])], value: '' } })
    await nextTick()

    expect(uploadPhotoMock).toHaveBeenCalledTimes(1)
    expect(item.photos.map((p) => p.id)).toEqual([42])
    expect(item.uploading).toBe(false)
    // The derived attachment id flows through to the emitted model.
    expect(w.vm.items[0].attachments).toEqual([42])
  })

  it('attaches to the right item even if rows are added mid-upload', async () => {
    let resolveUpload
    uploadPhotoMock.mockReturnValueOnce(
      new Promise((res) => {
        resolveUpload = res
      })
    )
    const w = mount(BulkItemEditor, mountOpts)
    const target = w.vm.items[0]
    target.name = 'Desk'

    w.vm.addPhotoToRow(target)
    const pending = w.vm.onRowFile({
      target: { files: [new Blob(['x'])], value: '' },
    })
    // A new row is unshifted (so indices shift) before the upload resolves.
    w.vm.addItem()
    resolveUpload({ id: 7 })
    await pending
    await nextTick()

    // The photo lands on the original item object, not whatever is at index 0.
    expect(target.photos.map((p) => p.id)).toEqual([7])
    expect(w.vm.items.find((i) => i.name === 'Desk').photos).toHaveLength(1)
  })

  it('surfaces an error and clears uploading when the upload fails', async () => {
    uploadPhotoMock.mockRejectedValueOnce(new Error('nope'))
    const w = mount(BulkItemEditor, mountOpts)
    const item = w.vm.items[0]

    w.vm.addPhotoToRow(item)
    await w.vm.onRowFile({ target: { files: [new Blob(['x'])], value: '' } })
    await nextTick()

    expect(item.photos).toHaveLength(0)
    expect(item.uploading).toBe(false)
    expect(w.vm.photoError).toContain("couldn't be uploaded")
  })

  it('does nothing when the picker is dismissed with no file', async () => {
    const w = mount(BulkItemEditor, mountOpts)
    w.vm.addPhotoToRow(w.vm.items[0])
    await w.vm.onRowFile({ target: { files: [], value: '' } })
    expect(uploadPhotoMock).not.toHaveBeenCalled()
  })

  it('seeds from an incoming modelValue', () => {
    const w = mount(BulkItemEditor, {
      ...mountOpts,
      props: {
        modelValue: [{ name: 'Sofa', quantity: 2, condition: 'Good' }],
      },
    })
    expect(w.vm.items).toHaveLength(1)
    expect(w.vm.items[0].name).toBe('Sofa')
  })
})
