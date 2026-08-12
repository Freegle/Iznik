import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useStoryAdd } from '~/composables/useStoryAdd'

const mockStoryStore = {
  addWanted: false,
}

vi.mock('~/stores/stories', () => ({
  useStoryStore: () => mockStoryStore,
}))

describe('useStoryAdd', () => {
  beforeEach(() => {
    mockStoryStore.addWanted = false
    globalThis.__mockAuthStore = { user: null, forceLogin: false }
  })

  afterEach(() => {
    delete globalThis.__mockAuthStore
  })

  function logIn() {
    globalThis.__mockAuthStore.user = { id: 42 }
  }

  it('opens the form straight away when logged in', () => {
    logIn()
    const { showStoryAddModal, showAddModal } = useStoryAdd()

    showAddModal()

    expect(showStoryAddModal.value).toBe(true)
    expect(globalThis.__mockAuthStore.forceLogin).toBe(false)
    expect(mockStoryStore.addWanted).toBe(false)
  })

  it('asks for a login instead of showing the form when logged out', () => {
    const { showStoryAddModal, showAddModal } = useStoryAdd()

    showAddModal()

    expect(showStoryAddModal.value).toBe(false)
    expect(globalThis.__mockAuthStore.forceLogin).toBe(true)
    expect(mockStoryStore.addWanted).toBe(true)
  })

  it('opens the form on the instance the login rebuilt', () => {
    // Logging in re-keys app.vue, so the component that asked for the login is
    // thrown away and a new one set up in its place.
    const first = useStoryAdd()
    first.showAddModal()
    expect(first.showStoryAddModal.value).toBe(false)

    logIn()
    const second = useStoryAdd()

    expect(second.showStoryAddModal.value).toBe(true)
  })

  it('only opens one form when several are set up at once', () => {
    // The ChitChat feed has one of these per story.
    useStoryAdd().showAddModal()
    logIn()

    const opened = [useStoryAdd(), useStoryAdd(), useStoryAdd()].filter(
      (s) => s.showStoryAddModal.value
    )

    expect(opened).toHaveLength(1)
    expect(mockStoryStore.addWanted).toBe(false)
  })

  it('does not open the form on a login we did not ask for', () => {
    logIn()

    const { showStoryAddModal } = useStoryAdd()

    expect(showStoryAddModal.value).toBe(false)
  })

  it('does not open the form while they are still logged out', () => {
    mockStoryStore.addWanted = true

    const { showStoryAddModal } = useStoryAdd()

    expect(showStoryAddModal.value).toBe(false)
    expect(mockStoryStore.addWanted).toBe(true)
  })

  it('remembers the intent when the modal itself asks for a login', () => {
    // StoryAddModal emits login-required if the login lapses while they're
    // typing, so the form comes back (with their story in it) afterwards.
    const { loginRequired } = useStoryAdd()

    loginRequired()

    expect(globalThis.__mockAuthStore.forceLogin).toBe(true)
    expect(mockStoryStore.addWanted).toBe(true)

    logIn()
    expect(useStoryAdd().showStoryAddModal.value).toBe(true)
  })
})
