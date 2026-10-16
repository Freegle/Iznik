import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { markRaw, nextTick } from 'vue'
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

  // On a phone the row scrolls sideways and is wider than the room it has. The open section must be
  // in it, whether its name is cut off at the right, it is the last of the row, or it is back at the left.
  describe('showing the open section', () => {
    // happy-dom lays nothing out, so the boxes are given: a row 358 wide and four links, each at its
    // place in the row less how far the row has scrolled, as in a browser.
    const rect = (left, right) => ({
      left,
      right,
      x: left,
      top: 0,
      bottom: 30,
      y: 0,
      width: right - left,
      height: 30,
    })
    const PLACES = {
      Overview: [0, 74],
      Funnels: [94, 160],
      'What members say': [180, 379],
      'Pages and features': [399, 560],
    }
    const four = [
      { key: 'overview', label: 'Overview' },
      { key: 'funnels', label: 'Funnels' },
      { key: 'members', label: 'What members say' },
      { key: 'pages', label: 'Pages and features' },
    ]
    let boxes

    beforeEach(() => {
      boxes = vi
        .spyOn(Element.prototype, 'getBoundingClientRect')
        .mockImplementation(function () {
          if (this.tagName === 'NAV') return rect(0, 358)
          const place = PLACES[this.textContent.trim()]
          if (!place) return rect(0, 0)
          const scrolled = this.closest('nav')?.scrollLeft || 0
          return rect(place[0] - scrolled, place[1] - scrolled)
        })
    })

    afterEach(() => {
      boxes.mockRestore()
    })

    const open = (current) => make({ sections: four, current })
    const scrolled = (w) => w.find('nav').element.scrollLeft

    it('scrolls the row just far enough to show a name that is cut off at the right', () => {
      expect(scrolled(open('members'))).toBe(379 - 358)
    })

    it('scrolls the row to the last section when that is the one open', () => {
      expect(scrolled(open('pages'))).toBe(560 - 358)
    })

    it('leaves the row alone when the open section is in view already', () => {
      expect(scrolled(open('overview'))).toBe(0)
      expect(scrolled(open('funnels'))).toBe(0)
    })

    it('follows the open section as it changes, in both directions', async () => {
      const w = open('funnels')
      expect(scrolled(w)).toBe(0)
      await w.setProps({ current: 'pages' })
      await nextTick()
      expect(scrolled(w)).toBe(202)
      // Back at the left, the link is cut off there, and the row goes back by the same amount.
      await w.setProps({ current: 'overview' })
      await nextTick()
      expect(scrolled(w)).toBe(0)
      await w.setProps({ current: 'members' })
      await nextTick()
      expect(scrolled(w)).toBe(21)
    })

    it('does nothing when no section is open, or the one named is not in the row', async () => {
      expect(scrolled(open(''))).toBe(0)
      const w = open('nothing-like-it')
      expect(scrolled(w)).toBe(0)
      await w.setProps({ current: 'also-not-there' })
      await nextTick()
      expect(scrolled(w)).toBe(0)
    })

    it('moves the row and not the page', () => {
      const page = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
      const into = vi.fn()
      const original = Object.getOwnPropertyDescriptor(
        Element.prototype,
        'scrollIntoView'
      )
      Element.prototype.scrollIntoView = into
      try {
        open('pages')
        expect(page).not.toHaveBeenCalled()
        expect(into).not.toHaveBeenCalled()
      } finally {
        page.mockRestore()
        // Put back what was there, or nothing if nothing was.
        if (original)
          Object.defineProperty(Element.prototype, 'scrollIntoView', original)
        else delete Element.prototype.scrollIntoView
      }
    })
  })
})
