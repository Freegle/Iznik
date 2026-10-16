import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxProblemGroups from '~/modtools/components/ModSysAdminUxProblemGroups.vue'
import { parseView } from '~/modtools/composables/useUxView'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import { uxStubs } from './uxStubs'

const fetchers = {
  fetchProblemGroups: vi.fn(),
  fetchProblems: vi.fn(),
  fetchValues: vi.fn(),
  fetchQuestions: vi.fn(),
}
vi.mock('~/api', () => ({
  default: () => ({ uxsurveys: fetchers }),
}))

// The registry is F's, and it imports every section. A fake one stands in, with the two functions the
// files under test use.
vi.mock('~/modtools/composables/useUxSections', () => ({
  hostedFor: () => [],
  uxSections: () => [],
}))

// The shapes are the Go responses in iznik-server-go/ux/surveys.go.
const biggest = (id, title, mentions, answers = 120) => ({
  id,
  title,
  mentions,
  answers,
})

const GROUPS = () => ({
  answers: 120,
  left_over: 9,
  groups: [
    {
      name: 'Photos and posting',
      problems: 3,
      mentions: 41,
      biggest: biggest(4, 'Photos fail to upload', 14),
    },
    {
      name: 'Collection and no-shows',
      problems: 2,
      mentions: 22,
      biggest: biggest(7, 'People do not turn up', 12),
    },
    {
      name: 'Finding things',
      problems: 1,
      mentions: 6,
      biggest: biggest(9, 'Search misses things', 6),
    },
    {
      name: 'Other',
      problems: 1,
      mentions: 5,
      biggest: biggest(11, 'The site is slow at weekends', 5),
    },
  ],
})

const problemOf = (id, over = {}) => ({
  id,
  title: `Problem ${id}`,
  description: '',
  flow: 'give',
  group: 'Photos and posting',
  mentions: 10,
  answers: 120,
  asked: 0,
  weight: null,
  most: null,
  times_shown: null,
  quotes: [],
  suggestions: [],
  status: 'open',
  ...over,
})

const listOf = (n, total = n, from = 1) => ({
  total,
  problems: Array.from({ length: n }, (_, i) => problemOf(from + i)),
})

describe('ModSysAdminUxProblemGroups', () => {
  let store

  beforeEach(() => {
    vi.clearAllMocks()
    fetchers.fetchProblems.mockResolvedValue(listOf(3))
    setActivePinia(createPinia())
    store = useUxSurveysStore()
    store.groups.data = GROUPS()
  })

  const make = async (props = {}) => {
    const w = mount(ModSysAdminUxProblemGroups, {
      props,
      global: {
        stubs: uxStubs,
        provide: { uxHrefFor: () => '#' },
      },
    })
    await flushPromises()
    return w
  }

  const rows = (w) => w.findAll('.group-row')
  const names = (w) => rows(w).map((r) => r.find('.name').text())

  describe('the groups', () => {
    it('are closed, and listed in the order the server sent them, without sorting', async () => {
      const unsorted = GROUPS()
      unsorted.groups.reverse()
      store.groups.data = unsorted
      const w = await make()
      expect(names(w)).toEqual([
        'Other',
        'Finding things',
        'Collection and no-shows',
        'Photos and posting',
      ])
      expect(rows(w).map((r) => r.attributes('aria-expanded'))).toEqual(
        Array(4).fill('false')
      )
      expect(w.find('.group-body').exists()).toBe(false)
      expect(fetchers.fetchProblems).not.toHaveBeenCalled()
    })

    it('give the number of problems and mentions, with the right singular', async () => {
      const w = await make()
      expect(rows(w).map((r) => r.find('.counts').text())).toEqual([
        '3 problems, 41 mentions',
        '2 problems, 22 mentions',
        '1 problem, 6 mentions',
        '1 problem, 5 mentions',
      ])
      store.groups.data.groups[0].mentions = 1
      await flushPromises()
      expect(rows(w)[0].find('.counts').text()).toBe('3 problems, 1 mention')
    })

    // The base is the written answers the coder read for the question, which is fewer than the
    // members who answered it (those also hold the ones who wrote nothing). The Questions view says
    // "answered" for that count, so this view must not use the word for another.
    it('name the biggest problem against the written answers, with the percentage from 100 answers', async () => {
      const w = await make()
      expect(rows(w)[0].find('.biggest').text()).toBe(
        'Biggest: Photos fail to upload (14 of 120 written answers, 12%)'
      )
    })

    it('name the biggest problem as a count below 100 answers', async () => {
      store.groups.data.groups[0].biggest = biggest(
        4,
        'Photos fail to upload',
        14,
        45
      )
      const w = await make()
      expect(rows(w)[0].find('.biggest').text()).toBe(
        'Biggest: Photos fail to upload (14 of 45 written answers)'
      )
      expect(rows(w)[0].text()).not.toContain('%')
      expect(rows(w)[0].text()).not.toContain('who answered')
    })

    it('have no "of" for a biggest problem that did not come from a question', async () => {
      store.groups.data.groups[0].biggest = biggest(
        4,
        'Photos fail to upload',
        14,
        0
      )
      const w = await make()
      expect(rows(w)[0].find('.biggest').text()).toBe(
        'Biggest: Photos fail to upload (14 members)'
      )
    })

    it('ask to open the group pressed, and to close the one that is open, clearing any open problem', async () => {
      const w = await make()
      await rows(w)[1].trigger('click')
      expect(w.emitted('navigate')[0]).toStrictEqual([
        { group: 'Collection and no-shows', problem: undefined },
      ])
      const open = await make({ group: 'Collection and no-shows' })
      await rows(open)[1].trigger('click')
      expect(open.emitted('navigate')[0]).toStrictEqual([
        { group: undefined, problem: undefined },
      ])
    })

    it('open one at a time: only the group the address names', async () => {
      const w = await make({ group: 'Finding things' })
      expect(rows(w).map((r) => r.attributes('aria-expanded'))).toEqual([
        'false',
        'false',
        'true',
        'false',
      ])
      expect(w.findAll('.group-body')).toHaveLength(1)
      expect(rows(w)[2].attributes('aria-controls')).toBe(
        w.find('.group-body').attributes('id')
      )
    })
  })

  describe('the problems in an open group', () => {
    it('are asked for when the group opens, 30 at a time, and listed with their counts', async () => {
      fetchers.fetchProblems.mockResolvedValue({
        total: 3,
        problems: [
          problemOf(4, {
            title: 'Photos fail to upload',
            mentions: 14,
            answers: 120,
          }),
          problemOf(5, {
            title: 'The post starts again',
            mentions: 9,
            answers: 45,
          }),
        ],
      })
      const w = await make({ group: 'Photos and posting' })
      expect(fetchers.fetchProblems).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchProblems).toHaveBeenCalledWith({
        group: 'Photos and posting',
        limit: 30,
      })
      const items = w.findAll('.problem-row')
      expect(items.map((i) => i.find('.title').text())).toEqual([
        'Photos fail to upload',
        'The post starts again',
      ])
      // The percentage is from 100 answers; the base is each problem's own.
      expect(items.map((i) => i.find('.of').text())).toEqual([
        '14 of 120 (12%)',
        '9 of 45',
      ])
    })

    // The route sends answers 0 for a problem that did not come from a question. The row then says
    // how many members, as the detail and the Biggest line do, and not a bare number with no unit.
    it('read a problem with no base as a count of members, not as a bare number', async () => {
      fetchers.fetchProblems.mockResolvedValue({
        total: 2,
        problems: [
          problemOf(4, {
            title: 'Photos fail to upload',
            mentions: 6,
            answers: 0,
          }),
          problemOf(5, {
            title: 'The post starts again',
            mentions: 1,
            answers: 0,
          }),
        ],
      })
      const w = await make({ group: 'Photos and posting' })
      expect(w.findAll('.problem-row .of').map((i) => i.text())).toEqual([
        '6 members',
        '1 member',
      ])
    })

    it('send the funnel and the search with the group', async () => {
      await make({ group: 'Other', flow: 'give', q: "can't" })
      expect(fetchers.fetchProblems).toHaveBeenCalledWith({
        group: 'Other',
        flow: 'give',
        q: "can't",
        limit: 30,
      })
    })

    it('say that they are loading until the list arrives', async () => {
      let resolve
      fetchers.fetchProblems.mockReturnValue(new Promise((r) => (resolve = r)))
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.group-body').text()).toContain('Loading...')
      resolve(listOf(2))
      await flushPromises()
      expect(w.findAll('.problem-row')).toHaveLength(2)
      expect(w.find('.group-body').text()).not.toContain('Loading...')
    })

    it('never draw the list of the group that was open before under the one open now', async () => {
      fetchers.fetchProblems.mockResolvedValueOnce({
        total: 1,
        problems: [problemOf(4, { title: 'From the photos group' })],
      })
      const w = await make({ group: 'Photos and posting' })
      expect(w.text()).toContain('From the photos group')

      let resolve
      fetchers.fetchProblems.mockReturnValueOnce(
        new Promise((r) => (resolve = r))
      )
      await w.setProps({ group: 'Finding things' })
      await flushPromises()
      expect(w.text()).not.toContain('From the photos group')
      expect(w.find('.group-body').text()).toContain('Loading...')

      resolve({
        total: 1,
        problems: [problemOf(9, { title: 'From the finding group' })],
      })
      await flushPromises()
      expect(w.text()).toContain('From the finding group')
      expect(w.text()).not.toContain('From the photos group')
    })

    it('start again at 30 when the funnel or the search changes under an open group', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
      const w = await make({ group: 'Other' })
      await w.find('.more').trigger('click')
      await flushPromises()
      expect(fetchers.fetchProblems).toHaveBeenLastCalledWith({
        group: 'Other',
        limit: 60,
      })

      await w.setProps({ flow: 'ask' })
      await flushPromises()
      expect(fetchers.fetchProblems).toHaveBeenLastCalledWith({
        group: 'Other',
        flow: 'ask',
        limit: 30,
      })
      await w.setProps({ q: 'email' })
      await flushPromises()
      expect(fetchers.fetchProblems).toHaveBeenLastCalledWith({
        group: 'Other',
        flow: 'ask',
        q: 'email',
        limit: 30,
      })
    })

    it('go back to steps from 30 when the funnel changes, so Show more asks for 60 and not for more', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
      const w = await make({ group: 'Other' })
      await w.find('.more').trigger('click')
      await flushPromises()
      await w.setProps({ flow: 'ask' })
      await flushPromises()
      expect(w.findAll('.problem-row')).toHaveLength(30)
      await w.find('.more').trigger('click')
      await flushPromises()
      expect(fetchers.fetchProblems).toHaveBeenLastCalledWith({
        group: 'Other',
        flow: 'ask',
        limit: 60,
      })
    })

    it('show an error from the list in the open group, and the groups stay', async () => {
      fetchers.fetchProblems.mockRejectedValue(new Error('boom'))
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.list-error').text()).toBe('boom')
      expect(rows(w)).toHaveLength(4)
    })

    it('say this part is not switched on when the route for the list is missing, and do not stay on Loading', async () => {
      fetchers.fetchProblems.mockRejectedValue({ response: { status: 404 } })
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.group-body').text()).toBe(
        'This part is not switched on yet.'
      )
      expect(w.find('.group-body .loading').exists()).toBe(false)
      expect(w.find('.list-error').exists()).toBe(false)
      expect(rows(w)).toHaveLength(4)
    })

    it('say no problems match when the list comes back empty', async () => {
      fetchers.fetchProblems.mockResolvedValue({ total: 0, problems: [] })
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.group-body').text()).toContain('No problems match.')
      expect(w.find('.showing').exists()).toBe(false)
    })
  })

  describe('a long group', () => {
    beforeEach(() => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
    })

    it('shows 30 of 45, says so, and offers Show more', async () => {
      const w = await make({ group: 'Photos and posting' })
      expect(w.findAll('.problem-row')).toHaveLength(30)
      expect(w.find('.showing').text()).toBe('Showing 30 of 45.')
      expect(w.find('.more').text()).toBe('Show more')
    })

    it('asks again with limit 60 when Show more is pressed, and replaces the list', async () => {
      const w = await make({ group: 'Photos and posting' })
      await w.find('.more').trigger('click')
      await flushPromises()
      expect(fetchers.fetchProblems).toHaveBeenCalledTimes(2)
      expect(fetchers.fetchProblems).toHaveBeenLastCalledWith({
        group: 'Photos and posting',
        limit: 60,
      })
      expect(w.findAll('.problem-row')).toHaveLength(45)
      expect(w.find('.showing').text()).toBe('Showing all 45.')
      expect(w.find('.more').exists()).toBe(false)
    })

    it('keeps the 30 on screen while the longer list loads, and does not ask twice', async () => {
      const w = await make({ group: 'Photos and posting' })
      let resolve
      fetchers.fetchProblems.mockReturnValueOnce(
        new Promise((r) => (resolve = r))
      )
      await w.find('.more').trigger('click')
      expect(w.findAll('.problem-row')).toHaveLength(30)
      expect(w.find('.more').attributes('disabled')).toBeDefined()
      await w.find('.more').trigger('click')
      expect(fetchers.fetchProblems).toHaveBeenCalledTimes(2)
      resolve(listOf(45, 45))
      await flushPromises()
      expect(w.findAll('.problem-row')).toHaveLength(45)
    })

    it('says all of them when there are exactly as many as were asked for', async () => {
      fetchers.fetchProblems.mockResolvedValue(listOf(30, 30))
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.showing').text()).toBe('Showing all 30.')
      expect(w.find('.more').exists()).toBe(false)
    })

    // "Showing all 1." says nothing the single line does not. Two or more are counted.
    it('says nothing about how many are shown when there is only one problem', async () => {
      fetchers.fetchProblems.mockResolvedValue(listOf(1, 1))
      const w = await make({ group: 'Photos and posting' })
      expect(w.findAll('.problem-row')).toHaveLength(1)
      expect(w.find('.showing').exists()).toBe(false)
      expect(w.find('.more').exists()).toBe(false)
    })

    it('still counts two', async () => {
      fetchers.fetchProblems.mockResolvedValue(listOf(2, 2))
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.showing').text()).toBe('Showing all 2.')
    })

    it('steps by 30 up to 100, and then offers nothing but a way to narrow the list', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 100), 140)
      )
      const w = await make({ group: 'Photos and posting' })
      const limits = []
      for (let i = 0; i < 4 && w.find('.more').exists(); i++) {
        await w.find('.more').trigger('click')
        await flushPromises()
        limits.push(fetchers.fetchProblems.mock.calls.at(-1)[0].limit)
      }
      expect(limits).toEqual([60, 90, 100])
      expect(w.findAll('.problem-row')).toHaveLength(100)
      expect(w.find('.more').exists()).toBe(false)
      expect(w.find('.showing').text()).toBe(
        'Showing 100 of 140. Narrow the list with the funnel or the search box.'
      )
    })
  })

  describe('an open problem', () => {
    it('shows its details under its line, and only that one', async () => {
      fetchers.fetchProblems.mockResolvedValue({
        total: 2,
        problems: [
          problemOf(4, { description: 'The photo step fails.' }),
          problemOf(5),
        ],
      })
      const w = await make({ group: 'Photos and posting', problem: '4' })
      const items = w.findAll('.problem-row')
      expect(items.map((i) => i.attributes('aria-expanded'))).toEqual([
        'true',
        'false',
      ])
      expect(w.findAll('.ux-problem-detail')).toHaveLength(1)
      expect(w.find('.ux-problem-detail').text()).toContain(
        'The photo step fails.'
      )
      expect(items[0].attributes('aria-controls')).toBe(
        w.find('.problem > div').attributes('id')
      )
    })

    it('asks to open the problem pressed, and to close the one that is open', async () => {
      const w = await make({ group: 'Photos and posting' })
      await w.findAll('.problem-row')[1].trigger('click')
      expect(w.emitted('navigate').at(-1)).toStrictEqual([{ problem: '2' }])

      const open = await make({ group: 'Photos and posting', problem: '2' })
      await open.findAll('.problem-row')[1].trigger('click')
      expect(open.emitted('navigate').at(-1)).toStrictEqual([
        { problem: undefined },
      ])
    })

    it('passes a click on its funnel link up as a change of address', async () => {
      const w = await make({ group: 'Photos and posting', problem: '1' })
      const click = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        button: 0,
      })
      w.find('.about-link').element.dispatchEvent(click)
      expect(w.emitted('navigate').at(-1)).toStrictEqual([
        { section: 'funnels', flow: 'give' },
      ])
    })

    it('ignores an id that is not a number', async () => {
      const w = await make({ group: 'Photos and posting', problem: 'abc' })
      expect(w.find('.ux-problem-detail').exists()).toBe(false)
      expect(w.find('.not-in-list').exists()).toBe(false)
    })

    it('asks again at the most the server sends when a link names a problem the first 30 do not reach, then opens it', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
      const w = await make({ group: 'Photos and posting', problem: '40' })
      expect(fetchers.fetchProblems.mock.calls.map((c) => c[0].limit)).toEqual([
        30, 100,
      ])
      expect(w.findAll('.problem-row')).toHaveLength(45)
      expect(w.find('.ux-problem-detail').exists()).toBe(true)
      expect(w.find('.not-in-list').exists()).toBe(false)
    })

    it('does not ask a third time, and says so when the problem is not in the group at all', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
      const w = await make({ group: 'Photos and posting', problem: '999' })
      expect(fetchers.fetchProblems.mock.calls.map((c) => c[0].limit)).toEqual([
        30, 100,
      ])
      expect(w.find('.not-in-list').text()).toBe(
        'That problem is not in the list.'
      )
      expect(w.find('.ux-problem-detail').exists()).toBe(false)
    })

    it('asks only once more even when the server sends fewer than were asked for', async () => {
      // A server that cuts every list at 40, whatever the limit, leaves more to ask for after each
      // answer. The follow-up must not go round and round. If it did, this stops it with a fault after
      // six asks, so that it fails and does not run for ever.
      let asks = 0
      fetchers.fetchProblems.mockImplementation(async (p) => {
        if (++asks > 6) throw new Error('asked too often')
        return listOf(Math.min(p.limit, 40), 140)
      })
      const w = await make({ group: 'Photos and posting', problem: '999' })
      await flushPromises()
      expect(fetchers.fetchProblems.mock.calls.map((c) => c[0].limit)).toEqual([
        30, 100,
      ])
      expect(w.findAll('.problem-row')).toHaveLength(40)
    })

    it('does not say a problem is missing while a longer list may still bring it', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.not-in-list').exists()).toBe(false)
      await w.setProps({ problem: '40' })
      // The first look has not reached it, and the second has not come back.
      expect(w.find('.not-in-list').exists()).toBe(false)
      await flushPromises()
      expect(w.find('.ux-problem-detail').exists()).toBe(true)
    })

    it('finds a problem the first list holds without asking again', async () => {
      const w = await make({ group: 'Photos and posting', problem: '2' })
      expect(fetchers.fetchProblems).toHaveBeenCalledTimes(1)
      expect(w.find('.ux-problem-detail').exists()).toBe(true)
    })

    // In a short group there is nothing more to ask for, so a follow-up that forgot to look first would
    // go unseen. In a long one it would ask again for 100, swap the 30 rows for 45 and lose the Show more
    // button, when the problem was in the first 30 all along.
    it('finds a problem in the first 30 of a long group without asking again, and keeps Show more', async () => {
      fetchers.fetchProblems.mockImplementation(async (p) =>
        listOf(Math.min(p.limit, 45), 45)
      )
      const w = await make({ group: 'Photos and posting', problem: '2' })
      expect(fetchers.fetchProblems).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchProblems.mock.calls[0][0].limit).toBe(30)
      expect(w.findAll('.problem-row')).toHaveLength(30)
      expect(w.find('.showing').text()).toBe('Showing 30 of 45.')
      expect(w.find('.more').exists()).toBe(true)
      expect(w.find('.ux-problem-detail').exists()).toBe(true)
    })
  })

  describe('the notes under the groups', () => {
    it('say how many answers matched no problem, in the singular too', async () => {
      const w = await make()
      expect(w.find('.left-over').text()).toBe(
        '9 answers did not match any problem.'
      )
      store.groups.data.left_over = 1
      await flushPromises()
      expect(w.find('.left-over').text()).toBe(
        '1 answer did not match any problem.'
      )
    })

    it('say nothing about answers when all matched', async () => {
      store.groups.data.left_over = 0
      expect((await make()).find('.left-over').exists()).toBe(false)
    })

    it('say the group list may need another group when Other holds more than a quarter of the mentions', async () => {
      store.groups.data.groups[3].mentions = 40
      const w = await make()
      expect(w.find('.other-heavy').text()).toBe(
        'Many problems are in Other. The group list may need another group.'
      )
    })

    it('do not say so for exactly a quarter, or for a small Other, and do for just over a quarter', async () => {
      const withOther = (others, other) => {
        const g = GROUPS()
        g.groups[0].mentions = others[0]
        g.groups[1].mentions = others[1]
        g.groups[2].mentions = others[2]
        g.groups[3].mentions = other
        return g
      }
      // 30 + 30 + 15 + 25 = 100, and Other is exactly a quarter.
      store.groups.data = withOther([30, 30, 15], 25)
      expect((await make()).find('.other-heavy').exists()).toBe(false)
      // 30 + 30 + 15 + 26 = 101, and Other is just over a quarter.
      store.groups.data = withOther([30, 30, 15], 26)
      expect((await make()).find('.other-heavy').exists()).toBe(true)
      // The groups as they are: Other is 5 of 74.
      store.groups.data = GROUPS()
      expect((await make()).find('.other-heavy').exists()).toBe(false)
    })

    // A funnel or a search leaves only some of the groups, and the note is about the whole list, so
    // Other's share of what is left says nothing about it. Other is 5 of 74 mentions in the whole list.
    it('say nothing about Other under a funnel or a search, whatever share it has of what is left', async () => {
      const onlyOther = () => ({
        answers: 120,
        left_over: 0,
        groups: [GROUPS().groups[3]],
      })
      store.groups.data = onlyOther()
      expect((await make({ flow: 'none' })).find('.other-heavy').exists()).toBe(
        false
      )
      expect((await make({ q: 'slow' })).find('.other-heavy').exists()).toBe(
        false
      )
      expect(
        (await make({ flow: 'give', q: 'slow' })).find('.other-heavy').exists()
      ).toBe(false)
      // The same groups as the whole list would be said to need another group.
      expect((await make()).find('.other-heavy').exists()).toBe(true)
    })

    it('say nothing about Other under a funnel when Other is over a quarter of what is left', async () => {
      store.groups.data.groups[3].mentions = 40
      expect((await make({ flow: 'give' })).find('.other-heavy').exists()).toBe(
        false
      )
      expect((await make()).find('.other-heavy').exists()).toBe(true)
    })

    // The figure is worked out for the funnel and not for the search text, so under a search it would
    // sit beside a list it does not describe, and beside "No problems match." it would look like the
    // answers that failed the search.
    it('say nothing about the answers that matched no problem under a search, which the figure ignores', async () => {
      store.groups.data = { answers: 120, left_over: 9, groups: [] }
      const none = await make({ q: 'zzzz' })
      expect(none.find('.none').text()).toBe('No problems match.')
      expect(none.find('.left-over').exists()).toBe(false)

      store.groups.data = { ...GROUPS(), groups: [GROUPS().groups[3]] }
      expect((await make({ q: 'slow' })).find('.left-over').exists()).toBe(
        false
      )
      expect(
        (await make({ flow: 'give', q: 'slow' })).find('.left-over').exists()
      ).toBe(false)
    })

    it('still say it under a funnel, which the figure is worked out for', async () => {
      expect((await make({ flow: 'give' })).find('.left-over').text()).toBe(
        '9 answers did not match any problem.'
      )
    })

    it('say nothing about Other when there is no Other group', async () => {
      store.groups.data.groups.pop()
      expect((await make()).find('.other-heavy').exists()).toBe(false)
    })
  })

  describe('when there is nothing to show', () => {
    it('says no problems yet, and when they come', async () => {
      store.groups.data = { answers: 0, left_over: 0, groups: [] }
      const w = await make()
      expect(w.find('.none').text()).toBe(
        'No problems yet. They appear after members answer a question in their own words and the answers have been analysed.'
      )
      expect(rows(w)).toHaveLength(0)
    })

    it('says no problems match when a funnel or a search leaves nothing', async () => {
      store.groups.data = { answers: 0, left_over: 0, groups: [] }
      expect((await make({ flow: 'give' })).find('.none').text()).toBe(
        'No problems match.'
      )
      expect((await make({ q: 'xyz' })).find('.none').text()).toBe(
        'No problems match.'
      )
    })

    it('still says how many answers matched no problem when there are no groups', async () => {
      store.groups.data = { answers: 12, left_over: 12, groups: [] }
      expect((await make()).find('.left-over').text()).toBe(
        '12 answers did not match any problem.'
      )
    })

    it('says a group in the address is not in the list, and opens nothing', async () => {
      const w = await make({ group: 'Nothing like it' })
      expect(w.find('.not-in-list').text()).toBe(
        'That group is not in the list.'
      )
      expect(w.find('.group-body').exists()).toBe(false)
      expect(fetchers.fetchProblems).not.toHaveBeenCalled()
    })

    it('does not add that when the list is empty, where the empty text says enough', async () => {
      store.groups.data = { answers: 0, left_over: 0, groups: [] }
      expect(
        (await make({ group: 'Other' })).find('.not-in-list').exists()
      ).toBe(false)
    })

    it('says this part is not switched on when the route is missing, and shows no fault', async () => {
      store.groups.data = null
      store.groups.missing = true
      const w = await make()
      expect(w.text()).toContain('This part is not switched on yet.')
      expect(w.find('.notice-message').exists()).toBe(false)
    })

    it('shows a fault as a notice, and says it is loading before the first answer', async () => {
      store.groups.data = null
      store.groups.error = 'boom'
      expect((await make()).find('.notice-message').text()).toBe('boom')
      store.groups.error = null
      store.groups.loading = true
      expect((await make()).text()).toContain('Loading...')
    })

    it('dims the groups while a new choice of funnel or search is being asked for', async () => {
      store.groups.loading = true
      const w = await make()
      expect(w.find('.stale').exists()).toBe(true)
      store.groups.loading = false
      await flushPromises()
      expect(w.find('.stale').exists()).toBe(false)
    })
  })

  describe('a group name the address cannot hold whole', () => {
    // The address keeps letters, digits, spaces and . : - and drops the rest (useUxView). A group is
    // matched on its name read the same way, so it still opens from a link.
    const NAME = "Pictures & people's posting"

    it('is matched through the same rule the address applies, and the server is asked for the real name', async () => {
      store.groups.data = {
        answers: 120,
        left_over: 0,
        groups: [
          {
            name: NAME,
            problems: 1,
            mentions: 5,
            biggest: biggest(4, 'Photos fail to upload', 5),
          },
        ],
      }
      const read = parseView({ ux_section: 'members', ux_group: NAME }, [
        { key: 'members', filters: [], params: ['group'] },
      ]).extra.group
      expect(read).toBe('Pictures  peoples posting')

      const w = await make({ group: read })
      expect(w.find('.group-row').attributes('aria-expanded')).toBe('true')
      expect(fetchers.fetchProblems).toHaveBeenCalledWith({
        group: NAME,
        limit: 30,
      })
    })

    it('keeps the eight names of the coder whole in the address', () => {
      const eight = [
        'Photos and posting',
        'Collection and no-shows',
        'Messages and chat',
        'Email and notifications',
        'Moderation and approval',
        'Finding things',
        'Account and settings',
        'Other',
      ]
      for (const name of eight) {
        const read = parseView({ ux_section: 'members', ux_group: name }, [
          { key: 'members', filters: [], params: ['group'] },
        ]).extra.group
        expect(read, name).toBe(name)
      }
    })
  })

  describe('the words', () => {
    it('say funnel, never flow, and draw no raw key', async () => {
      const w = await make({ group: 'Photos and posting', problem: '1' })
      const t = w.text()
      expect(t).not.toMatch(/\bflow\b/i)
      expect(t).not.toContain('chat_promise')
    })

    it('escape a title', async () => {
      fetchers.fetchProblems.mockResolvedValue({
        total: 1,
        problems: [problemOf(1, { title: '<img src=x onerror=alert(1)>' })],
      })
      store.groups.data.groups[0].biggest = biggest(1, '<b>bold</b>', 3)
      const w = await make({ group: 'Photos and posting' })
      expect(w.find('.problem-row img').exists()).toBe(false)
      expect(w.find('.group-row b').exists()).toBe(false)
      expect(w.text()).toContain('<img src=x onerror=alert(1)>')
    })
  })
})
