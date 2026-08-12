import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect } from 'vitest'

/*
 * "Mark read" / "Mark all read" appears in several places - the chat list, a
 * conversation header, the mobile chat navbar, the notification dropdown, and
 * the ModTools equivalents. It is one affordance, so it should look like one
 * affordance: a red outline with the count in a danger badge. Each component
 * used to style it privately, which is how the notification dropdown ended up
 * grey while everything around it was red.
 *
 * These tests pin the shared class and check every place that offers the
 * action carries a red treatment.
 */

const read = (p) => readFileSync(resolve(__dirname, '../../..', p), 'utf-8')

describe('shared mark-read class', () => {
  const buttons = read('assets/css/buttons.scss')
  const rule = buttons.match(/\.btn-mark-read\s*\{[\s\S]*?\n\}/)

  it('is defined in the globally loaded stylesheet', () => {
    /* buttons.scss is imported by global.scss, which both nuxt.config.ts and
     * modtools/nuxt.config.ts list in `css`, so the class reaches both apps. */
    expect(
      rule,
      '.btn-mark-read must exist in assets/css/buttons.scss'
    ).not.toBeNull()
    expect(read('assets/css/global.scss')).toContain("@import 'buttons'")
  })

  it('is a red outline that inverts on hover', () => {
    expect(rule[0]).toMatch(/border:\s*1px solid \$color-red/)
    expect(rule[0]).toMatch(/color:\s*\$color-red/)
    expect(rule[0]).toMatch(/&:hover/)
    expect(rule[0]).toMatch(/background-color:\s*\$color-red/)
  })

  it('is declared after .btn-white so it wins when combined with it', () => {
    /* Both are single-class !important rules, so source order decides. */
    expect(buttons.indexOf('.btn-mark-read')).toBeGreaterThan(
      buttons.indexOf('.btn-white:hover')
    )
  })
})

describe('every mark-read control uses a red treatment', () => {
  it.each([
    ['components/NotificationOptions.vue', 'Mark all read', 'btn-mark-read'],
    ['components/ChatPane.vue', 'Mark read', 'btn-mark-read'],
    ['modtools/components/ModChatHeader.vue', 'Mark read', 'btn-mark-read'],
    ['modtools/pages/chats/[[id]].vue', 'Mark all read', 'btn-mark-read'],
    /* These two predate the shared class and keep bespoke shapes - a pill in
     * the chat list search row, a circle in the mobile navbar - but the same
     * red language. Named here so the sweep stays complete. */
    ['pages/chats/[[id]].vue', 'Mark all read', 'mark-read-btn'],
    ['components/ChatMobileNavbar.vue', 'Mark read', 'action-btn--mark-read'],
  ])('%s styles its "%s" with %s', (file, label, cls) => {
    const src = read(file)
    expect(src).toContain(label)
    expect(src).toContain(cls)
  })

  it('gives each control the unread count in a danger badge', () => {
    for (const file of [
      'components/NotificationOptions.vue',
      'components/ChatPane.vue',
      'components/ChatMobileNavbar.vue',
      'modtools/components/ModChatHeader.vue',
      'pages/chats/[[id]].vue',
    ]) {
      expect(read(file), file).toMatch(/variant="danger"/)
    }
  })

  it('leaves no component declaring its own red mark-read rule', () => {
    /* ChatMobileNavbar keeps a rule for its circle/tint, but the plain
     * outline-and-text case belongs to the shared class now. */
    for (const file of [
      'components/ChatPane.vue',
      'components/NotificationOptions.vue',
      'modtools/components/ModChatHeader.vue',
    ]) {
      expect(read(file), file).not.toMatch(/mark-read\s*\{/)
    }
  })
})
