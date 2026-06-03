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
