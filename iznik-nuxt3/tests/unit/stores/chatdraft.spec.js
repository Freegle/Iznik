import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

describe('chatdraft store', () => {
  let useChatDraftStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/stores/chatdraft')
    useChatDraftStore = mod.useChatDraftStore
  })

  it('starts with no drafts', () => {
    const store = useChatDraftStore()
    expect(store.drafts).toEqual({})
    expect(store.getDraft(42)).toBe('')
  })

  it('saves and restores a draft keyed by chat id, isolated per chat', () => {
    const store = useChatDraftStore()
    store.saveDraft(42, 'half-typed message')
    expect(store.getDraft(42)).toBe('half-typed message')
    expect(store.getDraft(43)).toBe('')
  })

  it("clearDraft removes only that chat's draft", () => {
    const store = useChatDraftStore()
    store.saveDraft(42, 'discard me')
    store.saveDraft(43, 'keep me')
    store.clearDraft(42)
    expect(store.getDraft(42)).toBe('')
    expect(store.getDraft(43)).toBe('keep me')
  })

  it('saving empty or whitespace-only text clears the draft', () => {
    const store = useChatDraftStore()
    store.saveDraft(42, 'something')
    store.saveDraft(42, '   ')
    expect(store.getDraft(42)).toBe('')
    expect(store.drafts[42]).toBeUndefined()
  })

  it('ignores a falsy chat id', () => {
    const store = useChatDraftStore()
    store.saveDraft(0, 'no chat')
    store.saveDraft(null, 'no chat')
    expect(store.drafts).toEqual({})
  })

  it('discards and prunes a draft older than 24h', () => {
    const store = useChatDraftStore()
    store.drafts = {
      42: { text: 'stale', savedAt: Date.now() - 25 * 60 * 60 * 1000 },
    }
    expect(store.getDraft(42)).toBe('')
    expect(store.drafts[42]).toBeUndefined()
  })

  it('keeps a recent draft', () => {
    const store = useChatDraftStore()
    store.drafts = { 42: { text: 'fresh', savedAt: Date.now() - 1000 } }
    expect(store.getDraft(42)).toBe('fresh')
  })
})
