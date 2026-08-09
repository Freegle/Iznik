import { describe, it, expect, vi, beforeEach } from 'vitest'

import { setupNotification } from '~/composables/useNotification'

// ============================================================
// Store mocks — must be declared before any vi.mock() calls
// ============================================================
const mockUserFetch = vi.fn()
const mockUserById = vi.fn()
const mockNotificationById = vi.fn()
const mockNewsfeedFetch = vi.fn()
const mockNewsfeedById = vi.fn()
const mockCommunityEventFetch = vi.fn()
const mockCommunityEventById = vi.fn()
const mockVolunteeringFetch = vi.fn()
const mockVolunteeringById = vi.fn()

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    fetch: mockUserFetch,
    byId: mockUserById,
  }),
}))

vi.mock('~/stores/notification', () => ({
  useNotificationStore: () => ({
    byId: mockNotificationById,
  }),
}))

vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => ({
    fetch: mockNewsfeedFetch,
    byId: mockNewsfeedById,
  }),
}))

vi.mock('~/stores/communityevent', () => ({
  useCommunityEventStore: () => ({
    fetch: mockCommunityEventFetch,
    byId: mockCommunityEventById,
  }),
}))

vi.mock('~/stores/volunteering', () => ({
  useVolunteeringStore: () => ({
    fetch: mockVolunteeringFetch,
    byId: mockVolunteeringById,
  }),
}))

describe('setupNotification', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUserFetch.mockResolvedValue(undefined)
    mockNewsfeedFetch.mockResolvedValue({})
    mockCommunityEventFetch.mockResolvedValue(undefined)
    mockVolunteeringFetch.mockResolvedValue(undefined)
  })

  it('leaves fromuser and newsfeed null when the notification has neither', async () => {
    mockNotificationById.mockReturnValue({
      id: 1,
      fromuser: null,
      newsfeedid: null,
      timestamp: '2024-01-01T00:00:00Z',
    })

    const result = await setupNotification(1)

    expect(mockUserFetch).not.toHaveBeenCalled()
    expect(mockNewsfeedFetch).not.toHaveBeenCalled()
    expect(result.fromuser).toBeNull()
    expect(result.newsfeed).toBeNull()
    expect(typeof result.notificationago.value).toBe('string')
  })

  it('fetches and exposes fromuser when the notification has one', async () => {
    mockNotificationById.mockReturnValue({
      id: 2,
      fromuser: 42,
      newsfeedid: null,
      timestamp: '2024-01-01T00:00:00Z',
    })
    mockUserById.mockReturnValue({ id: 42, displayname: 'Jo' })

    const result = await setupNotification(2)

    expect(mockUserFetch).toHaveBeenCalledWith(42)
    expect(result.fromuser.value).toEqual({ id: 42, displayname: 'Jo' })
  })

  it('builds a plain-message newsfeed entry when there is no event or volunteering', async () => {
    mockNotificationById.mockReturnValue({
      id: 3,
      fromuser: null,
      newsfeedid: 100,
      timestamp: '2024-01-01T00:00:00Z',
    })
    mockNewsfeedFetch.mockResolvedValue({ id: 100 })
    mockNewsfeedById.mockReturnValue({ id: 100, message: 'Hello there' })

    const result = await setupNotification(3)

    expect(mockNewsfeedFetch).toHaveBeenCalledWith(100)
    expect(mockCommunityEventFetch).not.toHaveBeenCalled()
    expect(mockVolunteeringFetch).not.toHaveBeenCalled()
    expect(result.newsfeed.value.message).toBe('Hello there')
  })

  it('builds an event-titled newsfeed entry and fetches the event', async () => {
    mockNotificationById.mockReturnValue({
      id: 4,
      fromuser: null,
      newsfeedid: 101,
      timestamp: '2024-01-01T00:00:00Z',
    })
    mockNewsfeedFetch.mockResolvedValue({ id: 101, eventid: 55 })
    mockNewsfeedById.mockReturnValue({ id: 101, eventid: 55, message: null })
    mockCommunityEventById.mockReturnValue({ id: 55, title: 'Jumble sale' })

    const result = await setupNotification(4)

    expect(mockCommunityEventFetch).toHaveBeenCalledWith(55)
    expect(mockVolunteeringFetch).not.toHaveBeenCalled()
    expect(result.newsfeed.value.message).toBe('Jumble sale')
  })

  it('falls back to an empty title when the event lookup misses (optional chaining)', async () => {
    mockNotificationById.mockReturnValue({
      id: 5,
      fromuser: null,
      newsfeedid: 102,
      timestamp: '2024-01-01T00:00:00Z',
    })
    mockNewsfeedFetch.mockResolvedValue({ id: 102, eventid: 66 })
    mockNewsfeedById.mockReturnValue({ id: 102, eventid: 66, message: null })
    mockCommunityEventById.mockReturnValue(undefined)

    const result = await setupNotification(5)

    expect(result.newsfeed.value.message).toBeUndefined()
  })

  it('builds a volunteering-titled newsfeed entry and fetches the opportunity', async () => {
    mockNotificationById.mockReturnValue({
      id: 6,
      fromuser: null,
      newsfeedid: 103,
      timestamp: '2024-01-01T00:00:00Z',
    })
    mockNewsfeedFetch.mockResolvedValue({ id: 103, volunteeringid: 77 })
    mockNewsfeedById.mockReturnValue({
      id: 103,
      volunteeringid: 77,
      message: null,
    })
    mockVolunteeringById.mockReturnValue({ id: 77, title: 'Litter pick' })

    const result = await setupNotification(6)

    expect(mockVolunteeringFetch).toHaveBeenCalledWith(77)
    expect(mockCommunityEventFetch).not.toHaveBeenCalled()
    expect(result.newsfeed.value.message).toBe('Litter pick')
  })

  it('returns the raw falsy item untouched when the newsfeed store has nothing for it', async () => {
    mockNotificationById.mockReturnValue({
      id: 7,
      fromuser: null,
      newsfeedid: 104,
      timestamp: '2024-01-01T00:00:00Z',
    })
    mockNewsfeedFetch.mockResolvedValue({ id: 104 })
    mockNewsfeedById.mockReturnValue(null)

    const result = await setupNotification(7)

    expect(result.newsfeed.value).toBeNull()
  })
})
