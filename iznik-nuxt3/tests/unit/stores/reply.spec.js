import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

describe('reply store', () => {
  let useReplyStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/stores/reply')
    useReplyStore = mod.useReplyStore
  })

  describe('initial state', () => {
    it('starts with null/false defaults', () => {
      const store = useReplyStore()
      expect(store.replyMsgId).toBeNull()
      expect(store.replyMessage).toBeNull()
      expect(store.replyingAt).toBeNull()
      expect(store.machineState).toBeNull()
      expect(store.isNewUser).toBe(false)
    })
  })

  describe('clearReply', () => {
    it('resets all reply state', () => {
      const store = useReplyStore()
      store.replyMsgId = 42
      store.replyMessage = 'Hello'
      store.replyingAt = Date.now()
      store.machineState = 'composing'
      store.isNewUser = true

      store.clearReply()

      expect(store.replyMsgId).toBeNull()
      expect(store.replyMessage).toBeNull()
      expect(store.replyingAt).toBeNull()
      expect(store.machineState).toBeNull()
      expect(store.isNewUser).toBe(false)
    })
  })

  describe('saveMachineState', () => {
    it('saves state and isNewUser', () => {
      const store = useReplyStore()
      store.saveMachineState('waitingForEmail', true)
      expect(store.machineState).toBe('waitingForEmail')
      expect(store.isNewUser).toBe(true)
    })

    it('defaults isNewUser to false', () => {
      const store = useReplyStore()
      store.saveMachineState('composing')
      expect(store.machineState).toBe('composing')
      expect(store.isNewUser).toBe(false)
    })
  })

  describe('draft persistence', () => {
    it('starts with null draft fields', () => {
      const store = useReplyStore()
      expect(store.draftMsgId).toBeNull()
      expect(store.draftMessage).toBeNull()
      expect(store.draftCollect).toBeNull()
      expect(store.draftEmail).toBeNull()
      expect(store.draftAt).toBeNull()
    })

    it('saveDraft stores all draft fields and timestamps it', () => {
      const store = useReplyStore()
      const before = Date.now()
      store.saveDraft({
        msgId: 42,
        message: 'Half-typed reply',
        collect: 'Weekends',
        email: 'someone@example.com',
      })
      expect(store.draftMsgId).toBe(42)
      expect(store.draftMessage).toBe('Half-typed reply')
      expect(store.draftCollect).toBe('Weekends')
      expect(store.draftEmail).toBe('someone@example.com')
      expect(store.draftAt).toBeGreaterThanOrEqual(before)
    })

    it('saveDraft does not touch the pending-send fields', () => {
      const store = useReplyStore()
      store.saveDraft({ msgId: 42, message: 'Draft', collect: '', email: '' })
      expect(store.replyMsgId).toBeNull()
      expect(store.replyMessage).toBeNull()
      expect(store.replyingAt).toBeNull()
    })

    it('clearDraft resets only the draft fields', () => {
      const store = useReplyStore()
      store.replyMsgId = 7
      store.replyMessage = 'Committed reply'
      store.saveDraft({ msgId: 42, message: 'Draft', collect: '', email: '' })

      store.clearDraft()

      expect(store.draftMsgId).toBeNull()
      expect(store.draftMessage).toBeNull()
      expect(store.draftCollect).toBeNull()
      expect(store.draftEmail).toBeNull()
      expect(store.draftAt).toBeNull()
      expect(store.replyMsgId).toBe(7)
      expect(store.replyMessage).toBe('Committed reply')
    })

    it('clearReply also clears the draft', () => {
      const store = useReplyStore()
      store.saveDraft({ msgId: 42, message: 'Draft', collect: '', email: '' })
      store.clearReply()
      expect(store.draftMsgId).toBeNull()
      expect(store.draftMessage).toBeNull()
      expect(store.draftCollect).toBeNull()
      expect(store.draftEmail).toBeNull()
      expect(store.draftAt).toBeNull()
    })
  })
})
