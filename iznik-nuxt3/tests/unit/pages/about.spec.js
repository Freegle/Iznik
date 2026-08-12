import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import AboutPage from '~/pages/about.vue'

const mockFetch = vi.fn()
const mockTeams = {
  Volunteers: {
    members: [
      { id: 1, displayname: 'Alice Volunteer', description: 'Helps out' },
    ],
  },
  Board: {
    members: [{ id: 2, displayname: 'Bob Board', description: null }],
  },
}

vi.mock('~/stores/team', () => ({
  useTeamStore: () => ({
    fetch: mockFetch,
    getTeam: (team) => mockTeams[team],
  }),
}))

globalThis.useHead = () => {}

function mountPage() {
  return mount(AboutPage, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-card-header': {
          template: '<div class="card-header"><slot /></div>',
        },
        'b-card-body': { template: '<div class="card-body"><slot /></div>' },
        'b-card-text': { template: '<div class="card-text"><slot /></div>' },
        'b-collapse': {
          template: '<div class="collapse"><slot /></div>',
          props: ['id', 'accordion'],
        },
        'b-list-group': { template: '<div><slot /></div>' },
        'b-list-group-item': { template: '<div><slot /></div>' },
        ExternalLink: { template: '<a><slot /></a>', props: ['href'] },
      },
      directives: {
        'b-toggle': {},
      },
    },
  })
}

describe('pages/about.vue', () => {
  it('fetches the volunteer and board teams and lists their members', () => {
    const wrapper = mountPage()

    expect(mockFetch).toHaveBeenCalledWith('Volunteers')
    expect(mockFetch).toHaveBeenCalledWith('Board')

    // Volunteers only render a ProfileImage per member (name is alt text,
    // not visible copy) - assert the v-for actually iterated the real data.
    expect(wrapper.findAll('.profile-image-stub').length).toBe(2)

    // Board members render their name as visible text.
    expect(wrapper.text()).toContain('Bob Board')
    expect(wrapper.text()).toContain('What Do We Do?')
  })

  it('copes with an empty team list', () => {
    mockTeams.Volunteers = { members: [] }
    mockTeams.Board = { members: [] }

    const wrapper = mountPage()

    expect(wrapper.findAll('.profile-image-stub').length).toBe(0)
    expect(wrapper.text()).not.toContain('Bob Board')
  })
})
