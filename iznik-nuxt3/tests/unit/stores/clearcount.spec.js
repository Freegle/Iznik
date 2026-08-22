import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const messageApi = { clearCount: vi.fn().mockResolvedValue({ success: true }) }
const newsApi = { seenAll: vi.fn().mockResolvedValue(undefined) }

vi.mock('~/api', () => ({
  default: () => ({ message: messageApi, news: newsApi }),
}))

import { useMessageStore } from '~/stores/message'
import { useNewsfeedStore } from '~/stores/newsfeed'

describe('clearing a count without enumerating what is in it', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // The whole point of the endpoint: the browser cannot name a four-figure backlog it has
  // never scrolled through, so it must not try (Discourse 10055).
  it('browse clearCount sends no ids and zeroes the count', async () => {
    const store = useMessageStore()
    store.count = 1082

    await store.clearCount()

    expect(messageApi.clearCount).toHaveBeenCalledTimes(1)
    expect(messageApi.clearCount).toHaveBeenCalledWith()
    expect(store.count).toBe(0)
  })

  it('ChitChat markAllRead sends no id and zeroes the count', async () => {
    const store = useNewsfeedStore()
    store.count = 66

    await store.markAllRead()

    expect(newsApi.seenAll).toHaveBeenCalledTimes(1)
    expect(newsApi.seenAll).toHaveBeenCalledWith()
    expect(store.count).toBe(0)
  })

  // A pending delayed-seen timer must not fire after an explicit clear and drag the
  // watermark back to whatever this session happened to have loaded.
  it('ChitChat markAllRead cancels a pending delayed seen', async () => {
    const store = useNewsfeedStore()
    store.delayedSeenMode = true
    store.delayedSeenTimer = setTimeout(() => {}, 60000)

    await store.markAllRead()

    expect(store.delayedSeenTimer).toBeNull()
    expect(store.delayedSeenMode).toBe(false)
  })
})
