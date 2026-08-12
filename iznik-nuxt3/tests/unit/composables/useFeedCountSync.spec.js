import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { useFeedCountSync } from '~/composables/useFeedCountSync'

// The browse badge count is polled every 60 seconds (useNavbar getCounts -> messageStore
// .fetchCount). The FEED is not on that timer, so a post that arrives after the page loaded
// makes the count say "1 new post" while the loaded list has nothing unseen to show for it -
// which is what members see as a count with no item behind it.
describe('useFeedCountSync', () => {
  let refresh

  beforeEach(() => {
    refresh = vi.fn().mockResolvedValue(undefined)
  })

  it('pulls the feed when the count rises', async () => {
    const count = ref(0)
    useFeedCountSync(count, refresh)

    count.value = 1
    await nextTick()

    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('does not pull the feed when the count falls', async () => {
    // Falling is what "Mark seen" does, and it already refreshes the feed itself.
    const count = ref(3)
    useFeedCountSync(count, refresh)

    count.value = 0
    await nextTick()

    expect(refresh).not.toHaveBeenCalled()
  })

  it('does not pull the feed when the count is re-reported unchanged', async () => {
    // The poll fires every 60s whether or not anything changed; refetching the feed on
    // every tick would put every browsing member's reach query back on the server.
    const count = ref(2)
    useFeedCountSync(count, refresh)

    count.value = 2
    await nextTick()
    count.value = 2
    await nextTick()

    expect(refresh).not.toHaveBeenCalled()
  })

  it('treats the value present at setup as already accounted for', async () => {
    // The feed was fetched alongside this count, so the posts behind it are already loaded.
    const count = ref(4)
    useFeedCountSync(count, refresh)
    await nextTick()

    expect(refresh).not.toHaveBeenCalled()
  })

  it('pulls again on each subsequent rise', async () => {
    const count = ref(0)
    useFeedCountSync(count, refresh)

    count.value = 1
    await nextTick()
    // Let the first refresh settle - two rises that overlap are covered separately below.
    await flushPromises()
    count.value = 2
    await nextTick()

    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('rises again correctly after a fall', async () => {
    // Mark seen drops it to 0; the next arrival must still pull the feed.
    const count = ref(2)
    useFeedCountSync(count, refresh)

    count.value = 0
    await nextTick()
    count.value = 1
    await nextTick()

    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('survives a null or undefined count without pulling', async () => {
    // fetchCount can resolve to nothing before the first real answer arrives.
    const count = ref(null)
    useFeedCountSync(count, refresh)

    count.value = undefined
    await nextTick()

    expect(refresh).not.toHaveBeenCalled()
  })

  it('does not stack refreshes while one is still in flight', async () => {
    // The poll can fire again before a slow reach query returns; a second concurrent
    // fetch of the same feed is wasted work on a query the server is already running.
    let release
    refresh = vi.fn(() => new Promise((resolve) => (release = resolve)))
    const count = ref(0)
    useFeedCountSync(count, refresh)

    count.value = 1
    await nextTick()
    count.value = 2
    await nextTick()

    expect(refresh).toHaveBeenCalledTimes(1)

    release()
    await flushPromises()

    // Once it settles, a later rise is honoured again.
    count.value = 3
    await nextTick()
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('does not wedge permanently when a refresh rejects', async () => {
    refresh = vi.fn().mockRejectedValue(new Error('network'))
    const count = ref(0)
    useFeedCountSync(count, refresh)

    count.value = 1
    await nextTick()
    await flushPromises()

    count.value = 2
    await nextTick()

    expect(refresh).toHaveBeenCalledTimes(2)
  })
})
