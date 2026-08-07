import { describe, it, expect, vi, beforeEach } from 'vitest'

const mockMiscStore = { pageTitle: null }

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

describe('buildHead', () => {
  let buildHead

  beforeEach(async () => {
    vi.resetModules()
    mockMiscStore.pageTitle = null
    const mod = await import('~/modtools/composables/useMTBuildHead')
    buildHead = mod.buildHead
  })

  const runtimeConfig = { public: { MODTOOLS_SITE: 'https://modtools.example' } }

  it('builds meta/link/title using the given image', () => {
    const head = buildHead(
      {},
      runtimeConfig,
      'My Title',
      'My description',
      'https://example.com/pic.png'
    )

    expect(head.title).toBe('My Title')
    expect(head.meta).toContainEqual({
      key: 'description',
      name: 'description',
      content: 'My description',
    })
    expect(head.meta).toContainEqual({
      key: 'og:title',
      property: 'og:title',
      content: 'My Title',
    })
    expect(head.meta).toContainEqual({
      key: 'og:image',
      property: 'og:image',
      content: 'https://example.com/pic.png',
    })
    expect(head.meta).toContainEqual({
      key: 'twitter:image',
      property: 'twitter:image',
      content: 'https://example.com/pic.png',
    })
    expect(head.meta).toContainEqual({
      key: 'og:url',
      property: 'og:url',
      content: 'https://modtools.example',
    })
    expect(head.link).toHaveLength(5)
    expect(head.bodyAttrs).toEqual({})
  })

  it('falls back to the ModTools site icon when no image is given', () => {
    const head = buildHead({}, runtimeConfig, 'Title', 'Desc')

    expect(head.meta).toContainEqual({
      key: 'og:image',
      property: 'og:image',
      content: 'https://modtools.example/icon_modtools.png',
    })
  })

  it('passes bodyAttrs through untouched', () => {
    const head = buildHead({}, runtimeConfig, 'Title', 'Desc', null, {
      class: 'foo',
    })

    expect(head.bodyAttrs).toEqual({ class: 'foo' })
  })

  it('stores the page title in the misc store as a side effect', () => {
    buildHead({}, runtimeConfig, 'Side Effect Title', 'Desc')

    expect(mockMiscStore.pageTitle).toBe('Side Effect Title')
  })
})
