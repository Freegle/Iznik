import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetch = vi.fn()
const mockFetchBatch = vi.fn()
const mockFetchMessagesForGroup = vi.fn()
const mockGroupList = vi.fn()
const mockGroupAdd = vi.fn()
const mockGroupPatch = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    group: {
      fetch: mockFetch,
      fetchBatch: mockFetchBatch,
      fetchMessagesForGroup: mockFetchMessagesForGroup,
      list: mockGroupList,
      add: mockGroupAdd,
      patch: mockGroupPatch,
    },
  }),
}))

describe('group store', () => {
  let useGroupStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/stores/group')
    useGroupStore = mod.useGroupStore
  })

  describe('init', () => {
    it('initializes the store with config', () => {
      const store = useGroupStore()
      const config = { baseURL: 'http://localhost' }
      store.init(config)
      expect(store.config).toEqual(config)
      expect(store.fetching).toBeDefined()
    })
  })

  describe('clear', () => {
    it('clears all store data', () => {
      const store = useGroupStore()
      store.list = { 1: { id: 1, name: 'Group1' } }
      store.summaryList = { 2: { id: 2, name: 'Group2' } }
      store.messages = { 1: [{ id: 1 }] }
      store.allGroups = { group1: { id: 1 } }
      store._remember = { 1: true }

      store.clear()

      expect(store.list).toEqual({})
      expect(store.summaryList).toEqual({})
      expect(store.messages).toEqual({})
      expect(store.allGroups).toEqual({})
      expect(store._remember).toEqual({})
    })
  })

  describe('fetchBatch', () => {
    it('fetches multiple groups in one call and stores them', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetchBatch.mockResolvedValue([
        { id: 1, nameshort: 'Group1', settings: {} },
        { id: 2, nameshort: 'Group2', settings: {} },
        { id: 3, nameshort: 'Group3', settings: {} },
      ])

      await store.fetchBatch([1, 2, 3])

      expect(mockFetchBatch).toHaveBeenCalledWith([1, 2, 3])
      expect(store.list[1].nameshort).toBe('Group1')
      expect(store.list[2].nameshort).toBe('Group2')
      expect(store.list[3].nameshort).toBe('Group3')
    })

    it('skips groups already in the store with settings', async () => {
      const store = useGroupStore()
      store.init({})
      store.list[1] = { id: 1, nameshort: 'Existing', settings: { foo: 1 } }

      mockFetchBatch.mockResolvedValue([
        { id: 2, nameshort: 'Group2', settings: {} },
      ])

      await store.fetchBatch([1, 2])

      // Should only fetch id 2, not id 1
      expect(mockFetchBatch).toHaveBeenCalledWith([2])
      expect(store.list[1].nameshort).toBe('Existing')
      expect(store.list[2].nameshort).toBe('Group2')
    })

    it('does nothing if all groups are cached', async () => {
      const store = useGroupStore()
      store.init({})
      store.list[1] = { id: 1, settings: {} }
      store.list[2] = { id: 2, settings: {} }

      await store.fetchBatch([1, 2])

      expect(mockFetchBatch).not.toHaveBeenCalled()
    })

    it('handles empty response', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetchBatch.mockResolvedValue([])

      await store.fetchBatch([999])

      expect(mockFetchBatch).toHaveBeenCalledWith([999])
      expect(store.list[999]).toBeUndefined()
    })

    it('chunks requests to CHUNK_SIZE (25)', async () => {
      const store = useGroupStore()
      store.init({})

      const ids = Array.from({ length: 60 }, (_, i) => i + 1)
      mockFetchBatch.mockResolvedValue([])

      await store.fetchBatch(ids)

      // Should be called 3 times: 25 + 25 + 10
      expect(mockFetchBatch).toHaveBeenCalledTimes(3)
      expect(mockFetchBatch).toHaveBeenNthCalledWith(1, ids.slice(0, 25))
      expect(mockFetchBatch).toHaveBeenNthCalledWith(2, ids.slice(25, 50))
      expect(mockFetchBatch).toHaveBeenNthCalledWith(3, ids.slice(50, 60))
    })

    it('falls back to individual fetches on batch error', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetchBatch.mockRejectedValue(new Error('Batch not available'))
      mockFetch.mockImplementation((id) =>
        Promise.resolve({ id, nameshort: `Group${id}`, settings: {} })
      )

      await store.fetchBatch([1, 2])

      expect(mockFetch).toHaveBeenCalledTimes(2)
      expect(store.list[1]).toBeDefined()
      expect(store.list[2]).toBeDefined()
    })

    it('handles null response from batch', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetchBatch.mockResolvedValue(null)

      await store.fetchBatch([1])

      expect(store.list[1]).toBeUndefined()
    })

    it('handles non-array response from batch', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetchBatch.mockResolvedValue({ id: 1, nameshort: 'Group1' })

      await store.fetchBatch([1])

      expect(store.list[1]).toBeUndefined()
    })
  })

  describe('fetch', () => {
    it('fetches a group by numeric id', async () => {
      const store = useGroupStore()
      store.init({})

      const group = { id: 1, nameshort: 'Group1', settings: { val: 1 } }
      mockFetch.mockResolvedValue(group)

      const result = await store.fetch(1)

      expect(mockFetch).toHaveBeenCalledWith(1, expect.any(Function))
      expect(result).toEqual(group)
      expect(store.list[1]).toEqual(group)
    })

    it('returns cached group if it has settings', async () => {
      const store = useGroupStore()
      store.init({})
      const cached = { id: 1, nameshort: 'Group1', settings: { val: 1 } }
      store.list[1] = cached

      const result = await store.fetch(1)

      expect(mockFetch).not.toHaveBeenCalled()
      expect(result).toEqual(cached)
    })

    it('fetches group by name (string id)', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue([
        { id: 1, nameshort: 'testgroup', settings: { val: 1 } },
        { id: 2, nameshort: 'othergroup', settings: { val: 2 } },
      ])

      const result = await store.fetch('testgroup')

      expect(mockGroupList).toHaveBeenCalled()
      expect(store.allGroups['testgroup']).toBeDefined()
      expect(store.summaryList[1]).toBeDefined()
      expect(result.id).toBe(1)
    })

    it('fetches group by name case-insensitive', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue([
        { id: 1, nameshort: 'TestGroup', settings: { val: 1 } },
      ])

      const result = await store.fetch('TESTGROUP')

      expect(result.id).toBe(1)
    })

    it('returns null if group name not found', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue([
        { id: 1, nameshort: 'testgroup', settings: { val: 1 } },
      ])

      const result = await store.fetch('nonexistent')

      expect(result).toBeNull()
    })

    it('caches group name lookup to avoid re-fetching list', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue([
        { id: 1, nameshort: 'testgroup', settings: { val: 1 } },
      ])

      await store.fetch('testgroup')
      const result2 = await store.fetch('testgroup')

      expect(mockGroupList).toHaveBeenCalledTimes(1)
      expect(result2.id).toBe(1)
    })

    it('waits for in-flight fetch instead of fetching twice', async () => {
      const store = useGroupStore()
      store.init({})

      let resolveFirst
      const fetchPromise = new Promise((resolve) => {
        resolveFirst = resolve
      })
      mockFetch.mockReturnValue(fetchPromise)

      const promise1 = store.fetch(1)
      const promise2 = store.fetch(1)

      resolveFirst({ id: 1, nameshort: 'Group1', settings: {} })

      const result1 = await promise1
      const result2 = await promise2

      expect(mockFetch).toHaveBeenCalledTimes(1)
      expect(result1).toEqual(result2)
    })

    it('calls fetch with error callback function', async () => {
      const store = useGroupStore()
      store.init({})

      let callbackFn
      mockFetch.mockImplementation((id, cb) => {
        callbackFn = cb
        return Promise.resolve({ id: 1, nameshort: 'Group1', settings: {} })
      })

      await store.fetch(1)

      expect(callbackFn).toBeDefined()
      expect(typeof callbackFn).toBe('function')
      // Verify error callback suppresses ret === 10
      expect(callbackFn({ ret: 10 })).toBe(false)
      expect(callbackFn({ ret: 0 })).toBe(true)
    })

    it('converts string id to integer', async () => {
      const store = useGroupStore()
      store.init({})

      const group = { id: 123, nameshort: 'Group123', settings: { val: 1 } }
      mockFetch.mockResolvedValue(group)

      const result = await store.fetch('123')

      expect(mockFetch).toHaveBeenCalledWith(123, expect.any(Function))
      expect(result.id).toBe(123)
    })

    it('returns null when fetch is called with falsy id (fetch all)', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue([
        { id: 1, nameshort: 'group1', settings: { val: 1 } },
      ])

      const result = await store.fetch()

      expect(result).toBeUndefined()
      expect(store.summaryList[1]).toBeDefined()
    })

    it('populates allGroups and summaryList on fetch all', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue([
        { id: 1, nameshort: 'Group1', settings: { val: 1 } },
        { id: 2, nameshort: 'Group2', settings: { val: 2 } },
      ])

      await store.fetch()

      expect(store.allGroups['group1']).toBeDefined()
      expect(store.allGroups['group2']).toBeDefined()
      expect(store.summaryList[1]).toBeDefined()
      expect(store.summaryList[2]).toBeDefined()
    })

    it('handles null response on fetch all', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupList.mockResolvedValue(null)

      await store.fetch()

      expect(store.summaryList).toEqual({})
    })

    it('forces refetch when force=true', async () => {
      const store = useGroupStore()
      store.init({})
      store.list[1] = { id: 1, nameshort: 'Group1', settings: { val: 1 } }

      const newGroup = { id: 1, nameshort: 'Group1', settings: { val: 2 } }
      mockFetch.mockResolvedValue(newGroup)

      await store.fetch(1, true)

      expect(mockFetch).toHaveBeenCalled()
      expect(store.list[1].settings.val).toBe(2)
    })

    it('clears fetching flag after successful fetch', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetch.mockResolvedValue({ id: 1, nameshort: 'Group1', settings: {} })

      await store.fetch(1)

      expect(store.fetching[1]).toBeNull()
    })
  })

  describe('remember', () => {
    it('stores a value in _remember', () => {
      const store = useGroupStore()
      store.remember(1, 'some-value')
      expect(store._remember[1]).toBe('some-value')
    })

    it('overwrites previous remembered value', () => {
      const store = useGroupStore()
      store.remember(1, 'first')
      store.remember(1, 'second')
      expect(store._remember[1]).toBe('second')
    })
  })

  describe('fetchMessagesForGroup', () => {
    it('fetches and stores messages for a group', async () => {
      const store = useGroupStore()
      store.init({})

      const messages = [
        { id: 1, text: 'Message1' },
        { id: 2, text: 'Message2' },
      ]
      mockFetchMessagesForGroup.mockResolvedValue(messages)

      await store.fetchMessagesForGroup(123)

      expect(mockFetchMessagesForGroup).toHaveBeenCalledWith(123)
      expect(store.messages[123]).toEqual(messages)
    })

    it('converts string id to integer', async () => {
      const store = useGroupStore()
      store.init({})

      const messages = [{ id: 1, text: 'Message1' }]
      mockFetchMessagesForGroup.mockResolvedValue(messages)

      await store.fetchMessagesForGroup('456')

      expect(mockFetchMessagesForGroup).toHaveBeenCalledWith(456)
    })

    it('handles null response', async () => {
      const store = useGroupStore()
      store.init({})

      mockFetchMessagesForGroup.mockResolvedValue(null)

      await store.fetchMessagesForGroup(123)

      expect(store.messages[123]).toBeUndefined()
    })
  })

  describe('addgroup', () => {
    it('creates a new group with provided parameters', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupAdd.mockResolvedValue(789)
      mockGroupPatch.mockResolvedValue({})

      const params = {
        nameshort: 'newgroup',
        namefull: 'New Group',
        cga: 'polygon1',
        dpa: 'polygon2',
        lat: 51.5,
        lng: -0.1,
      }

      const result = await store.addgroup(params)

      expect(mockGroupAdd).toHaveBeenCalledWith({
        grouptype: 'Freegle',
        action: 'Create',
        name: 'newgroup',
      })
      expect(mockGroupPatch).toHaveBeenCalledWith({
        id: 789,
        namefull: 'New Group',
        publish: 0,
        polyofficial: 'polygon1',
        poly: 'polygon2',
        lat: 51.5,
        lng: -0.1,
        onyahoo: 0,
        onhere: 1,
        ontn: 1,
        onlovejunk: 1,
        licenserequired: 0,
        showonyahoo: 0,
      })
      expect(result).toBe(789)
    })

    it('returns the newly created group id', async () => {
      const store = useGroupStore()
      store.init({})

      mockGroupAdd.mockResolvedValue(999)
      mockGroupPatch.mockResolvedValue({})

      const result = await store.addgroup({
        nameshort: 'test',
        namefull: 'Test',
        cga: 'cga',
        dpa: 'dpa',
        lat: 0,
        lng: 0,
      })

      expect(result).toBe(999)
    })
  })

  describe('get getter', () => {
    const testGetterCases = [
      {
        name: 'returns group from list by numeric id',
        state: { list: { 1: { id: 1, nameshort: 'group1' } }, summaryList: {} },
        input: 1,
        expected: { id: 1, nameshort: 'group1' },
      },
      {
        name: 'returns group from list by numeric string id',
        state: { list: { 42: { id: 42, nameshort: 'mygroup' } }, summaryList: {} },
        input: '42',
        expected: { id: 42, nameshort: 'mygroup' },
      },
      {
        name: 'falls back to summaryList for numeric id',
        state: {
          list: {},
          summaryList: { 5: { id: 5, nameshort: 'summarygroup' } },
        },
        input: 5,
        expected: { id: 5, nameshort: 'summarygroup' },
      },
      {
        name: 'returns null if numeric id not found',
        state: { list: {}, summaryList: {} },
        input: 999,
        expected: null,
      },
      {
        name: 'finds group by name in list',
        state: {
          list: { 1: { id: 1, nameshort: 'mygroup' } },
          summaryList: {},
        },
        input: 'mygroup',
        expected: { id: 1, nameshort: 'mygroup' },
      },
      {
        name: 'finds group by name case-insensitive in list',
        state: {
          list: { 1: { id: 1, nameshort: 'MyGroup' } },
          summaryList: {},
        },
        input: 'mygroup',
        expected: { id: 1, nameshort: 'MyGroup' },
      },
      {
        name: 'finds group by name in summaryList if not in list',
        state: {
          list: {},
          summaryList: { 2: { id: 2, nameshort: 'summaryonly' } },
        },
        input: 'summaryonly',
        expected: { id: 2, nameshort: 'summaryonly' },
      },
      {
        name: 'prefers list over summaryList for name search',
        state: {
          list: { 1: { id: 1, nameshort: 'shared' } },
          summaryList: { 2: { id: 2, nameshort: 'shared' } },
        },
        input: 'shared',
        expected: { id: 1, nameshort: 'shared' },
      },
      {
        name: 'returns null if name not found anywhere',
        state: { list: {}, summaryList: {} },
        input: 'nonexistent',
        expected: null,
      },
    ]

    testGetterCases.forEach(({ name, state, input, expected }) => {
      it(name, () => {
        const store = useGroupStore()
        store.list = state.list
        store.summaryList = state.summaryList
        const result = store.get(input)
        expect(result).toEqual(expected)
      })
    })
  })

  describe('getMessages getter', () => {
    it('returns messages if id exists', () => {
      const store = useGroupStore()
      const messages = [{ id: 1, text: 'msg1' }]
      store.messages[5] = messages

      const result = store.getMessages(5)

      expect(result).toEqual(messages)
    })

    it('returns empty array if id does not exist', () => {
      const store = useGroupStore()

      const result = store.getMessages(999)

      expect(result).toEqual([])
    })
  })

  describe('remembered getter', () => {
    it('returns remembered value', () => {
      const store = useGroupStore()
      store._remember[10] = 'remembered-val'

      const result = store.remembered(10)

      expect(result).toBe('remembered-val')
    })

    it('returns undefined if value not remembered', () => {
      const store = useGroupStore()

      const result = store.remembered(999)

      expect(result).toBeUndefined()
    })
  })
})
