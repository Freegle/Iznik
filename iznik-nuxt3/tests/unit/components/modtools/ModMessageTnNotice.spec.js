import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ModMessageTnNotice from '~/modtools/components/ModMessageTnNotice.vue'

function mountNotice(props = {}) {
  return mount(ModMessageTnNotice, {
    props: {
      modMessagingAllowed: false,
      groupName: 'Edinburgh Freegle',
      ...props,
    },
    global: {
      stubs: {
        NoticeMessage: {
          template: '<div class="notice-message"><slot /></div>',
          props: ['variant'],
        },
      },
    },
  })
}

describe('ModMessageTnNotice', () => {
  it('says nothing at all about an ordinary post', () => {
    const wrapper = mountNotice({ modMessagingAllowed: true })

    expect(wrapper.find('[data-test="tn-unaddressed-warning"]').exists()).toBe(
      false
    )
    expect(wrapper.text()).toBe('')
  })

  // A moderator about to let one through needs to know that approve or delete is the
  // whole of it - there is no asking the poster for a photo or a postcode first.
  describe('on a copy still awaiting a decision', () => {
    it('tells the moderator it is approve or delete on what they can see', () => {
      const notice = mountNotice({ live: false }).find(
        '[data-test="tn-unaddressed-warning"]'
      )

      expect(notice.exists()).toBe(true)
      expect(notice.text()).toContain('Trash Nothing')
      expect(notice.text()).toContain("hasn't joined")
      expect(notice.text()).toContain('approve or delete')
      expect(notice.text()).toContain("can't edit it")
    })

    it('does not claim the post is live', () => {
      const wrapper = mountNotice({ live: false })

      expect(
        wrapper.find('[data-test="tn-unaddressed-pending"]').exists()
      ).toBe(true)
      expect(
        wrapper.find('[data-test="tn-unaddressed-approved"]').exists()
      ).toBe(false)
      expect(wrapper.text()).not.toContain('is live on')
    })
  })

  // On a live copy the surprising part is what happens next: a report does not come back
  // to them, it takes the post off Freegle.
  describe('on a live copy', () => {
    it('says what happens if members report it', () => {
      const notice = mountNotice({ live: true }).find(
        '[data-test="tn-unaddressed-warning"]'
      )

      expect(notice.text()).toContain('is live on')
      expect(notice.text()).toContain('comes off Freegle automatically')
      expect(notice.text()).toContain("can't edit it or message them")
    })

    it('does not tell the moderator to approve something already approved', () => {
      const wrapper = mountNotice({ live: true })

      expect(
        wrapper.find('[data-test="tn-unaddressed-approved"]').exists()
      ).toBe(true)
      expect(
        wrapper.find('[data-test="tn-unaddressed-pending"]').exists()
      ).toBe(false)
      expect(wrapper.text()).not.toContain('approve or delete')
    })
  })

  it('names the community the post was matched to', () => {
    expect(mountNotice({ live: false }).text()).toContain('Edinburgh Freegle')
    expect(mountNotice({ live: true }).text()).toContain('Edinburgh Freegle')
  })

  it('reads sensibly when the community name has not loaded', () => {
    const wrapper = mountNotice({ live: false, groupName: null })

    expect(wrapper.text()).toContain('this community')
  })
})
