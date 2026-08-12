import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import RipplingDigestModal from '~/modtools/components/RipplingDigestModal.vue'

/**
 * This modal builds its body as raw HTML from post data and injects it with v-html, so
 * subject and group name — both member-supplied — pass through escapeHTML on the way. It
 * also mirrors several of the real digest email's presentation rules, which is the point of
 * a mock-up: if the mock-up and the email disagree, the moderator is being shown something
 * that will not happen.
 *
 * Note the file already ran at 100% line coverage before this spec existed, exercised
 * incidentally by a parent. Executed is not asserted: nothing checked the escaping, the
 * mile formatting or the rank offset until now.
 *
 * Uses the real scoring/geometry composables rather than mocks, since they are pure.
 */
describe('RipplingDigestModal', () => {
  const MEMBER = [51.5, -0.12]

  function post(overrides = {}) {
    return {
      msgid: 1,
      subject: 'OFFER: Sofa (Camden)',
      msgtype: 'Offer',
      groupid: 7,
      groupname: 'Camden Freegle',
      lat: 51.5,
      lng: -0.12,
      arrival: '2026-08-01T10:00:00Z',
      drive_min: 5,
      home_group: true,
      views: 3,
      replies: 2,
      score: 0.5,
      score_close: 0.3,
      score_budget: 0.1,
      score_anchor: 0.1,
      ...overrides,
    }
  }

  function openWith(data, memberLat = MEMBER[0], memberLng = MEMBER[1]) {
    const wrapper = mount(RipplingDigestModal)
    wrapper.vm.openDigest(data, memberLat, memberLng)
    return wrapper
  }

  describe('nothing to show', () => {
    it('asks for a location when none has been dropped', async () => {
      const wrapper = mount(RipplingDigestModal)
      wrapper.vm.openDigest({ top_picks: [] }, null, null)
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Drop a location first')
    })

    it('distinguishes "no results here" from "no location"', async () => {
      const wrapper = openWith({ top_picks: [] })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('No posts in the digest yet')
      expect(wrapper.text()).not.toContain('Drop a location first')
    })
  })

  describe('member-supplied text is escaped', () => {
    it('does not let a crafted subject inject markup', async () => {
      const wrapper = openWith({
        top_picks: [post({ subject: 'OFFER: <script>alert(1)</script> (Camden)' })],
      })
      await wrapper.vm.$nextTick()

      // The body goes in via v-html, so an unescaped subject would become a real element.
      expect(wrapper.find('script').exists()).toBe(false)
      expect(wrapper.html()).toContain('&lt;script&gt;')
    })

    it('escapes a crafted group name too', async () => {
      const wrapper = openWith({
        top_picks: [post({ groupname: '<img src=x onerror=alert(1)>' })],
      })
      await wrapper.vm.$nextTick()

      expect(wrapper.find('img[onerror]').exists()).toBe(false)
      expect(wrapper.html()).toContain('&lt;img')
    })
  })

  describe('presentation rules shared with the real email', () => {
    it('strips the OFFER:/WANTED: prefix so the type is not said twice', async () => {
      // The subject carries "WANTED:" and the row renders the type separately, so leaving
      // the prefix would read "Wanted: WANTED: Bookcase".
      const wrapper = openWith({
        top_picks: [post({ subject: 'WANTED: Bookcase (Camden)', msgtype: 'Wanted' })],
      })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Bookcase')
      expect(wrapper.text()).not.toContain('WANTED:')
    })

    it('shows "< 1 mile" rather than a rounded zero for something on the doorstep', async () => {
      const wrapper = openWith({ top_picks: [post({ lat: 51.5001, lng: -0.1201 })] })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('< 1 mile')
    })

    it('rounds a real distance to whole miles', async () => {
      // ~0.15 degrees of latitude is roughly 10 miles.
      const wrapper = openWith({ top_picks: [post({ lat: 51.65, lng: -0.12 })] })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toMatch(/\d+ miles/)
      expect(wrapper.text()).not.toContain('< 1 mile')
    })

    it('lists the other groups only for a genuine cross-post', async () => {
      const single = openWith({
        top_picks: [post({ posted_to_names: ['Camden Freegle'] })],
      })
      await single.vm.$nextTick()
      expect(single.text()).not.toContain('Posted to:')

      const cross = openWith({
        top_picks: [post({ posted_to_names: ['Camden Freegle', 'Islington Freegle'] })],
      })
      await cross.vm.$nextTick()
      expect(cross.text()).toContain('Posted to:')
      expect(cross.text()).toContain('Islington Freegle')
    })

    it('marks whether a post is from the home group or rippled in', async () => {
      const home = openWith({ top_picks: [post({ home_group: true })] })
      await home.vm.$nextTick()
      expect(home.text()).toContain('home')

      const rippled = openWith({ top_picks: [post({ home_group: false })] })
      await rippled.vm.$nextTick()
      expect(rippled.text()).toContain('rippled in')
    })
  })

  describe("counts follow the email's subject semantics", () => {
    it('headlines the full total while listing only the capped set', async () => {
      // deduped_count is the real Top-picks total; top_picks is already capped
      // server-side, and the overflow is only on the website.
      const wrapper = openWith({
        top_picks: [post(), post({ msgid: 2 })],
        deduped_count: 70,
        deferred_count: 68,
      })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Top picks (70)')
      expect(wrapper.text()).toContain('and 68 more on the website')
    })

    it('omits the overflow footer when nothing was deferred', async () => {
      const wrapper = openWith({ top_picks: [post()], deduped_count: 1 })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Top picks (1)')
      expect(wrapper.text()).not.toContain('more on the website')
    })

    it('separates the posts that came and went since the last digest', async () => {
      const wrapper = openWith({
        top_picks: [post()],
        came_and_went: [post({ msgid: 3, subject: 'OFFER: Gone already (Camden)' })],
      })
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('came and went')
      expect(wrapper.text()).toContain('Switch to immediate notifications')
      expect(wrapper.text()).toContain('Gone already')
    })
  })

  describe('openCluster', () => {
    it('names how many posts share the exact coordinate', async () => {
      const wrapper = mount(RipplingDigestModal)
      wrapper.vm.openCluster([post({ _rank: 1 }), post({ msgid: 2, _rank: 2 })], ...MEMBER)
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('2 posts here')
      expect(wrapper.text()).toContain('2 posts at this exact location')
      // The TrashNothing centroid case is why this view exists at all.
      expect(wrapper.text()).toContain('TrashNothing')
    })
  })

  describe('openPost (the single-post deep dive)', () => {
    function openOne(p, rank = 0, member = MEMBER) {
      const wrapper = mount(RipplingDigestModal)
      wrapper.vm.openPost(p, rank, member[0], member[1])
      return wrapper
    }

    it('titles the panel with the human rank, not the zero-based index', async () => {
      const wrapper = openOne(post(), 0)
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Post #1')
    })

    it('escapes the subject here too, and strips the type prefix', async () => {
      const wrapper = openOne(
        post({ subject: 'WANTED: <b>Bookcase</b> (Camden)', msgtype: 'Wanted' })
      )
      await wrapper.vm.$nextTick()

      expect(wrapper.find('.rpl-modal-body b').exists()).toBe(false)
      expect(wrapper.html()).toContain('&lt;b&gt;')
      expect(wrapper.text()).not.toContain('WANTED:')
      expect(wrapper.text()).toContain('Wanted:')
    })

    it('gives both crow-flies miles and the drive time', async () => {
      const wrapper = openOne(post({ lat: 51.65, lng: -0.12, drive_min: 12 }))
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toMatch(/\d+\.\d miles as the crow flies/)
      expect(wrapper.text()).toContain('12 min in reach')
    })

    it('falls back to drive time alone when the member has no location', async () => {
      const wrapper = mount(RipplingDigestModal)
      wrapper.vm.openPost(post({ drive_min: 9 }), 0, null, null)
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('9 min in reach')
      expect(wrapper.text()).not.toContain('crow flies')
    })

    it('says view/reply in the singular for exactly one', async () => {
      const one = openOne(post({ views: 1, replies: 1 }))
      await one.vm.$nextTick()
      expect(one.text()).toContain('1 view ')
      expect(one.text()).toContain('1 reply')

      const many = openOne(post({ views: 4, replies: 3 }))
      await many.vm.$nextTick()
      expect(many.text()).toContain('4 views')
      expect(many.text()).toContain('3 replies')
    })

    it('breaks the score into its three components', async () => {
      const wrapper = openOne(
        post({ score: 0.75, score_close: 0.5, score_budget: 0.15, score_anchor: 0.1 })
      )
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('0.75')
      expect(wrapper.text()).toContain('close 0.50')
      expect(wrapper.text()).toContain('budget 0.15')
      expect(wrapper.text()).toContain('anchor 0.10')
    })

    it('shows a missing score component as 0.00 rather than blank or NaN', async () => {
      const wrapper = openOne(
        post({ score: undefined, score_close: undefined, score_budget: undefined, score_anchor: undefined })
      )
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('0.00')
      expect(wrapper.text()).not.toContain('NaN')
      expect(wrapper.text()).not.toContain('undefined')
    })

    it('names the group by id when it has no name, and says so when it has neither', async () => {
      const byId = openOne(post({ groupname: null, groupid: 42 }))
      await byId.vm.$nextTick()
      expect(byId.text()).toContain('group 42')

      const neither = openOne(post({ groupname: null, groupid: null }))
      await neither.vm.$nextTick()
      expect(neither.text()).toContain('no group')
    })

    it('falls back to a placeholder title for a post with no subject', async () => {
      const wrapper = openOne(post({ subject: null }))
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('(no title)')
    })
  })

  describe('close', () => {
    it('takes the modal down', async () => {
      const wrapper = openWith({ top_picks: [post()] })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.rpl-modal-root').exists()).toBe(true)

      wrapper.vm.close()
      await wrapper.vm.$nextTick()

      expect(wrapper.find('.rpl-modal-root').exists()).toBe(false)
    })
  })
})
