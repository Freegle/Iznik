import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportMailHeldTable from '~/modtools/components/ModSupportMailHeldTable.vue'

const stubs = {
  NoticeMessage: { template: '<div><slot /></div>' },
  'b-table-simple': { template: '<table><slot /></table>' },
  'b-thead': { template: '<thead><slot /></thead>' },
  'b-tbody': { template: '<tbody><slot /></tbody>' },
  'b-tr': { template: '<tr><slot /></tr>' },
  'b-th': { template: '<th><slot /></th>' },
  'b-td': { template: '<td><slot /></td>' },
  'nuxt-link': { template: '<a><slot /></a>' },
}

function render(members) {
  return mount(ModSupportMailHeldTable, {
    props: { members, limit: 1000 },
    global: { stubs },
  })
}

function words(wrapper) {
  return wrapper.text().replace(/\s+/g, ' ').trim()
}

// Every row used to read "Unknown" in a Provider column, which on an
// address-scope suppression is always empty - so the table said nothing at all
// about why the mail was not getting through.
describe('ModSupportMailHeldTable', () => {
  it('says a full inbox in words', () => {
    const text = words(
      render([
        {
          userid: 1,
          email: 'a@gmail.com',
          reason:
            "host alt1.gmail-smtp-in.l.google.com said: 452-4.2.2 The recipient's inbox is out of storage",
        },
      ])
    )

    expect(text).toContain('Their inbox is full')
  })

  it('says an unreachable mail server in words', () => {
    const text = words(
      render([
        {
          userid: 2,
          email: 'b@icloude.com',
          reason: 'connect to icloude.com[17.253.142.4]:25: Connection timed out',
        },
      ])
    )

    expect(text).toContain("We can't reach their mail server")
  })

  it('names the provider when the provider is the one refusing us', () => {
    const text = words(
      render([
        {
          userid: 3,
          email: 'c@talktalk.net',
          provider: 'TalkTalk',
          reason: 'host mx004.tt.xion.oxcs.net refused to talk to me: 421',
        },
      ])
    )

    expect(text).toContain('TalkTalk is refusing our mail')
  })

  it('does not pretend to know when nothing was recorded', () => {
    expect(words(render([{ userid: 4, email: 'd@example.com' }]))).toContain(
      'Not recorded'
    )
  })

  // The number is times we declined to generate, not emails in a queue.
  it('heads the count as emails not generated', () => {
    const text = words(
      render([{ userid: 5, email: 'e@gmail.com', skipped: 12351 }])
    )

    expect(text).toContain('Emails not generated')
    expect(text).toContain('12,351')
  })
})
