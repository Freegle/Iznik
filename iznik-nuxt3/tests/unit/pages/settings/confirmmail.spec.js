import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

const mockRoute = reactive({
  params: { key: 'abc123' },
  query: {},
  path: '/settings/confirmmail/abc123',
  name: 'settings-confirmmail-key',
  fullPath: '/settings/confirmmail/abc123',
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRoute,
  }
})
globalThis.__testUseRoute = () => mockRoute

const mockAuthStore = vi.hoisted(() => ({
  me: { id: 1, email: 'me@example.com' },
  saveAndGet: vi.fn(),
  saveEmail: vi.fn(),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: vi.fn().mockReturnValue({}),
}))

// The page calls useHead as a Nuxt auto-import, which the unit setup does not provide.
globalThis.useHead = () => {}

async function mountPage() {
  const ConfirmMailPage = (
    await import('~/pages/settings/confirmmail/[key].vue')
  ).default
  const wrapper = mount(ConfirmMailPage, {
    global: {
      mocks: { me: { id: 1, email: 'me@example.com' } },
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-modal': {
          props: ['modelValue', 'title'],
          template:
            '<div v-if="modelValue" class="modal" :data-title="title"><slot /><slot name="footer" /></div>',
        },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        ExternalLink: { template: '<a><slot /></a>', props: ['href'] },
      },
    },
  })
  await flushPromises()
  return wrapper
}

function modal(wrapper, title) {
  return wrapper.find(`.modal[data-title="${title}"]`)
}

describe('settings/confirmmail/[key]', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('confirms the key and says so', async () => {
    mockAuthStore.saveAndGet.mockResolvedValue({})
    const wrapper = await mountPage()

    expect(mockAuthStore.saveAndGet).toHaveBeenCalledWith({ key: 'abc123' })
    expect(modal(wrapper, 'Verification Succeeded').exists()).toBe(true)
  })

  it('offers to resend when the key fails for no stated reason', async () => {
    mockAuthStore.saveAndGet.mockRejectedValue(new Error('boom'))
    const wrapper = await mountPage()

    const failed = modal(wrapper, 'Verification Failed')
    expect(failed.exists()).toBe(true)
    expect(failed.text()).toContain('Please try again')
    expect(failed.text()).toContain('Resend verification mail')
  })

  it('says why a TrashNothing address cannot be confirmed, and offers no resend', async () => {
    mockAuthStore.saveAndGet.mockRejectedValue({
      response: {
        status: 403,
        data: { error: 403, message: 'That is a TrashNothing address.' },
      },
    })
    const wrapper = await mountPage()

    const failed = modal(wrapper, 'Verification Failed')
    expect(failed.exists()).toBe(true)
    expect(failed.text()).toContain('That is a TrashNothing address.')
    expect(failed.text()).not.toContain('Please try again')
    expect(failed.text()).not.toContain('Resend verification mail')
  })

  it('says why a resend to a TrashNothing address is refused', async () => {
    mockAuthStore.saveAndGet.mockRejectedValue(new Error('boom'))
    mockAuthStore.saveEmail.mockResolvedValue({
      ret: 12,
      status: 'That is a TrashNothing address.',
    })
    const wrapper = await mountPage()

    const resend = modal(wrapper, 'Verification Failed')
      .findAll('button')
      .find((b) => b.text().includes('Resend verification mail'))
    await resend.trigger('click')
    await flushPromises()

    expect(modal(wrapper, 'Verification Sent').exists()).toBe(false)
    expect(modal(wrapper, 'Verification Failed').text()).toContain(
      'That is a TrashNothing address.'
    )
  })

  it('says the mail was resent when it was', async () => {
    mockAuthStore.saveAndGet.mockRejectedValue(new Error('boom'))
    mockAuthStore.saveEmail.mockResolvedValue({ ret: 10 })
    const wrapper = await mountPage()

    const resend = modal(wrapper, 'Verification Failed')
      .findAll('button')
      .find((b) => b.text().includes('Resend verification mail'))
    await resend.trigger('click')
    await flushPromises()

    expect(modal(wrapper, 'Verification Sent').exists()).toBe(true)
  })
})
