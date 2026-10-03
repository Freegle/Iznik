import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SiteRules from '~/components/SiteRules.vue'

// Experiment: one set of rules for the whole site, in place of ~500 community rule sets
// nobody could read. The list is the union of what communities could switch on today.

describe('SiteRules', () => {
  function createWrapper() {
    return mount(SiteRules, {
      global: {
        stubs: {
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
        },
      },
    })
  }

  it('lists the rules that used to vary by community', () => {
    const wrapper = createWrapper()
    const text = wrapper.text()
    for (const rule of [
      'free',
      'legal',
      'weapons',
      'medicines',
      'animals',
      'alcohol',
      'tobacco',
      'personal details',
      'selling',
    ]) {
      expect(text.toLowerCase(), rule).toContain(rule)
    }
  })

  it('says what happens when a rule is broken, without a person', () => {
    const wrapper = createWrapper()
    expect(wrapper.text()).toContain('taken down')
    expect(wrapper.text()).toContain('report')
  })

  it('has one rule per item with an id to link to', () => {
    const wrapper = createWrapper()
    const items = wrapper.findAll('li[id]')
    expect(items.length).toBeGreaterThanOrEqual(8)
  })
})
