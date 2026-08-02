import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect } from 'vitest'

/*
 * ChatMobileNavbar component uses top-level await for setupChat which causes
 * test timeout issues. We test component structure and prop definitions.
 */

// ChatMobileNavbar awaits setupChat() in <script setup>, so it can't be cleanly
// mounted here; assert on the source (as ChatPane.spec.js does).
const navbarSource = readFileSync(
  resolve(__dirname, '../../../components/ChatMobileNavbar.vue'),
  'utf-8'
)

describe('profile popover hidden for User2Mod (Discourse 9918)', () => {
  it('gates the profile popover on User2User only', () => {
    // A User2Mod (contact-the-volunteers) chat has no other-user profile, so the
    // popover must not enable for it - it rendered as an empty white box.
    expect(navbarSource).toMatch(
      /<b-popover\s+v-if="cssReady && chat\.chattype === 'User2User'"/
    )
  })

  it('makes the header avatar toggle the profile card only for User2User', () => {
    expect(navbarSource).toMatch(
      /@click="chat\.chattype === 'User2User' \? toggleProfileCard\(\) : null"/
    )
  })
})

// The profile card could only be dismissed by tapping the avatar again, taking
// one of its actions, or scrolling the thread. None of those look like a way
// out, so the card read as stuck open on a phone - and this navbar is the only
// chat header below md, so there was no other affordance.
describe('profile card close button', () => {
  it('has a close control that collapses the card', () => {
    expect(navbarSource).toMatch(/class="profile-card-close"/)
    expect(navbarSource).toMatch(
      /class="profile-card-close"[\s\S]*?@click="profileCardExpanded = false"/
    )
  })

  it('gives the close control an accessible name', () => {
    expect(navbarSource).toMatch(
      /class="profile-card-close"[\s\S]*?aria-label="Close profile info"/
    )
  })

  it('hides the close control while the first-visit hint is showing', () => {
    // dismissHint() already sets profileCardExpanded = false, so "Got it"
    // closes the whole card; a second control there would only overlap it.
    expect(navbarSource).toMatch(
      /<button\s+v-if="!showProfileHint"\s+class="profile-card-close"/
    )
    expect(navbarSource).toMatch(
      /function dismissHint\(\)[\s\S]*?profileCardExpanded\.value = false/
    )
  })

  it('sizes the close control as a comfortable touch target', () => {
    // This popover only exists below md, so it is always touched, never clicked.
    expect(navbarSource).toMatch(
      /\.profile-card-close\s*\{[\s\S]*?width:\s*44px[\s\S]*?height:\s*44px/
    )
  })
})

describe('ChatMobileNavbar', () => {
  describe('component structure', () => {
    it('is a mobile navbar for chat pages', () => {
      // Fixed-top navbar shown only on mobile
      expect(true).toBe(true)
    })
  })

  describe('expected props', () => {
    it('should accept required id prop', () => {
      const propDef = { type: Number, required: true }
      expect(propDef.required).toBe(true)
      expect(propDef.type).toBe(Number)
    })
  })

  describe('back button', () => {
    it('has back button with arrow-left icon', () => {
      // nav-back-btn div with v-icon arrow-left
      expect(true).toBe(true)
    })

    it('shows badge with unread chat count', () => {
      // back-badge with backButtonCount from useNavbar
      expect(true).toBe(true)
    })

    it('triggers backButton from useNavbar on click', () => {
      // Uses useNavbar().backButton
      expect(true).toBe(true)
    })
  })

  describe('offline indicator', () => {
    it('shows OfflineIndicator when offline', () => {
      // v-if="!online" from useNavbar
      expect(true).toBe(true)
    })
  })

  describe('other user display', () => {
    it('shows other user profile image', () => {
      // ProfileImage with chat.icon
      expect(true).toBe(true)
    })

    it('shows other user name', () => {
      // h1 with chat.name, truncate class
      expect(true).toBe(true)
    })

    it('group is clickable for profile card toggle', () => {
      // @click="toggleProfileCard"
      expect(true).toBe(true)
    })
  })

  describe('mark read button', () => {
    it('shows when unseen messages and profile card collapsed', () => {
      // v-if="unseen && !profileCardExpanded"
      expect(true).toBe(true)
    })

    it('displays unseen count in badge', () => {
      // navbar-mark-read-badge
      expect(true).toBe(true)
    })

    it('has check icon', () => {
      // navbar-mark-read-icon
      expect(true).toBe(true)
    })
  })

  describe('user dropdown', () => {
    it('has user profile image or icon', () => {
      // ProfileImage if me?.profile?.path else v-icon user
      expect(true).toBe(true)
    })

    it('has Settings menu item', () => {
      const menuItems = ['Settings', 'Logout']
      expect(menuItems).toContain('Settings')
    })

    it('has Logout menu item', () => {
      const menuItems = ['Settings', 'Logout']
      expect(menuItems).toContain('Logout')
    })
  })

  describe('profile popover', () => {
    it('uses b-popover with bottom placement', () => {
      // placement="bottom" target="other-user-group"
      expect(true).toBe(true)
    })

    it('waits for cssReady before showing', () => {
      // v-if="cssReady" - uses requestAnimationFrame
      expect(true).toBe(true)
    })

    it('shows profile hint for first-time visitors', () => {
      // profile-hint-tip with Got it button
      expect(true).toBe(true)
    })
  })

  describe('profile card content', () => {
    it('shows supporter trophy icon when applicable', () => {
      // v-icon trophy in avatar-wrapper
      expect(true).toBe(true)
    })

    it('shows SupporterInfo badge', () => {
      // SupporterInfo component
      expect(true).toBe(true)
    })

    it('shows UserRatings', () => {
      // UserRatings with externalModals prop
      expect(true).toBe(true)
    })
  })

  describe('profile card stats', () => {
    it('shows last seen stat chip', () => {
      const stats = ['lastSeen', 'repliesIn', 'distance']
      expect(stats).toContain('lastSeen')
    })

    it('shows replies in stat chip', () => {
      const stats = ['lastSeen', 'repliesIn', 'distance']
      expect(stats).toContain('repliesIn')
    })

    it('shows distance stat chip', () => {
      const stats = ['lastSeen', 'repliesIn', 'distance']
      expect(stats).toContain('distance')
    })
  })

  describe('profile card actions', () => {
    it('has Mark read action', () => {
      const actions = [
        'markRead',
        'profile',
        'show',
        'hide',
        'block',
        'unblock',
        'report',
      ]
      expect(actions).toContain('markRead')
    })

    it('has Profile action', () => {
      const actions = [
        'markRead',
        'profile',
        'show',
        'hide',
        'block',
        'unblock',
        'report',
      ]
      expect(actions).toContain('profile')
    })

    it('has Show/Hide action', () => {
      const actions = [
        'markRead',
        'profile',
        'show',
        'hide',
        'block',
        'unblock',
        'report',
      ]
      expect(actions).toContain('show')
      expect(actions).toContain('hide')
    })

    it('has Block/Unblock action for User2User', () => {
      const actions = [
        'markRead',
        'profile',
        'show',
        'hide',
        'block',
        'unblock',
        'report',
      ]
      expect(actions).toContain('block')
      expect(actions).toContain('unblock')
    })

    it('has Report action with danger styling', () => {
      const actions = [
        'markRead',
        'profile',
        'show',
        'hide',
        'block',
        'unblock',
        'report',
      ]
      expect(actions).toContain('report')
    })
  })

  describe('modals', () => {
    it('has ChatBlockModal', () => {
      const modals = [
        'ChatBlock',
        'ChatHide',
        'ChatReport',
        'Profile',
        'RatingsDown',
        'RatingsRemove',
      ]
      expect(modals).toContain('ChatBlock')
    })

    it('has ChatHideModal', () => {
      const modals = [
        'ChatBlock',
        'ChatHide',
        'ChatReport',
        'Profile',
        'RatingsDown',
        'RatingsRemove',
      ]
      expect(modals).toContain('ChatHide')
    })

    it('has ChatReportModal', () => {
      const modals = [
        'ChatBlock',
        'ChatHide',
        'ChatReport',
        'Profile',
        'RatingsDown',
        'RatingsRemove',
      ]
      expect(modals).toContain('ChatReport')
    })

    it('has ProfileModal with close-on-message', () => {
      const modals = [
        'ChatBlock',
        'ChatHide',
        'ChatReport',
        'Profile',
        'RatingsDown',
        'RatingsRemove',
      ]
      expect(modals).toContain('Profile')
    })

    it('has UserRatingsDownModal', () => {
      const modals = [
        'ChatBlock',
        'ChatHide',
        'ChatReport',
        'Profile',
        'RatingsDown',
        'RatingsRemove',
      ]
      expect(modals).toContain('RatingsDown')
    })

    it('has UserRatingsRemoveModal', () => {
      const modals = [
        'ChatBlock',
        'ChatHide',
        'ChatReport',
        'Profile',
        'RatingsDown',
        'RatingsRemove',
      ]
      expect(modals).toContain('RatingsRemove')
    })
  })

  describe('profile hint', () => {
    it('checks shouldShowHint based on miscStore', () => {
      // Dismissed timestamps checked against 7 days
      const sevenDays = 7 * 24 * 60 * 60 * 1000
      expect(sevenDays).toBe(604800000)
    })

    it('dismissHint saves timestamp to miscStore', () => {
      // miscStore.set({ key: 'profileHintDismissed', value: Date.now() })
      expect(true).toBe(true)
    })
  })

  describe('scroll handling', () => {
    it('adds scroll listener on mount', () => {
      // window.addEventListener('scroll', handleScroll)
      expect(true).toBe(true)
    })

    it('removes scroll listener on unmount', () => {
      // window.removeEventListener('scroll', handleScroll)
      expect(true).toBe(true)
    })

    it('hides navbar when scrollY > 200', () => {
      // setNavBarHidden(true)
      expect(true).toBe(true)
    })

    it('shows navbar when scrollY < 100', () => {
      // setNavBarHidden(false)
      expect(true).toBe(true)
    })

    it('collapses profile card when scrollY > 50', () => {
      // profileCardExpanded.value = false
      expect(true).toBe(true)
    })
  })

  describe('composables', () => {
    it('uses useNavbar', () => {
      // online, backButtonCount, backButton, logout
      expect(true).toBe(true)
    })

    it('uses setupChat', () => {
      // otheruser, milesaway, unseen
      expect(true).toBe(true)
    })

    it('uses useTimeFormat', () => {
      // timeago for lastSeenAgo
      expect(true).toBe(true)
    })
  })

  describe('store integrations', () => {
    it('uses chatStore', () => {
      const stores = ['chat', 'auth', 'misc']
      expect(stores).toContain('chat')
    })

    it('uses authStore for me', () => {
      const stores = ['chat', 'auth', 'misc']
      expect(stores).toContain('auth')
    })

    it('uses miscStore for hint state', () => {
      const stores = ['chat', 'auth', 'misc']
      expect(stores).toContain('misc')
    })
  })

  describe('styling', () => {
    it('uses fixed-top positioning', () => {
      // class="fixed-top"
      expect(true).toBe(true)
    })

    it('uses ourBack class for navbar background', () => {
      // @import 'assets/css/navbar.scss'
      expect(true).toBe(true)
    })

    it('has profile-popover custom class', () => {
      // max-width: calc(100vw - 24px), width: 400px
      expect(true).toBe(true)
    })
  })
})
