import { describe, it, expect, vi } from 'vitest'
import { markRaw } from 'vue'
import { mount } from '@vue/test-utils'
import ModSysAdminUxNav from '~/modtools/components/ModSysAdminUxNav.vue'

const sections = [
  { key: 'overview', label: 'Overview' },
  { key: 'funnels', label: 'Funnels' },
  { key: 'pages', label: 'Pages and features' },
]

const make = (props = {}) =>
  mount(ModSysAdminUxNav, {
    props: {
      sections,
      current: 'funnels',
      hrefFor: (patch) => `/sysadmin?tab=ux&ux_section=${patch.section}`,
      ...props,
    },
  })

describe('ModSysAdminUxNav', () => {
  it('is a labelled row of links, in the order given', () => {
    const w = make()
    expect(w.find('nav').attributes('aria-label')).toBe('UX sections')
    expect(w.findAll('a').map((a) => a.text())).toEqual([
      'Overview',
      'Funnels',
      'Pages and features',
    ])
  })

  it('makes each a real link to its section, so it can be copied or opened in a new tab', () => {
    const w = make()
    expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual([
      '/sysadmin?tab=ux&ux_section=overview',
      '/sysadmin?tab=ux&ux_section=funnels',
      '/sysadmin?tab=ux&ux_section=pages',
    ])
  })

  it('marks the open section as the current page', () => {
    const w = make()
    const links = w.findAll('a')
    expect(links[1].attributes('aria-current')).toBe('page')
    expect(links[1].classes()).toContain('active')
    expect(links[0].attributes('aria-current')).toBeUndefined()
    expect(links[0].classes()).not.toContain('active')
  })

  it('says which section a plain click goes to, and stops the browser following the link', async () => {
    const w = make()
    const preventDefault = vi.fn()
    await w.findAll('a')[2].trigger('click', { button: 0, preventDefault })
    expect(w.emitted('go')).toEqual([['pages']])
  })

  it('leaves a click with a modifier key to the browser', async () => {
    const w = make()
    await w.findAll('a')[0].trigger('click', { ctrlKey: true })
    await w.findAll('a')[0].trigger('click', { metaKey: true })
    await w.findAll('a')[0].trigger('click', { shiftKey: true })
    expect(w.emitted('go')).toBeUndefined()
  })

  it('draws a badge after the label when a section has one', () => {
    const Badge = markRaw({ template: '<span class="badge-here">3</span>' })
    const w = make({
      sections: [
        { key: 'experiments', label: 'Experiments', badge: Badge },
        ...sections,
      ],
    })
    expect(w.findAll('a')[0].find('.badge-here').exists()).toBe(true)
    expect(w.findAll('a')[1].find('.badge-here').exists()).toBe(false)
  })

  it('does not use pills, which the SysAdmin page already uses two levels up', () => {
    expect(make().html()).not.toContain('nav-pills')
  })
})
