import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// The bulk offer the manager renders (owner === me).
const mockMessage = {
  id: 1,
  subject: 'Office clearance',
  fromuser: 99,
  bulkcount: 1,
  bulkitems: [{ id: 10, position: 0, name: 'Cabinet', quantity: 2, interest: [] }],
}

const bulkEditLink = vi.hoisted(() => vi.fn())

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: () => mockMessage, fetch: vi.fn() }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetchMultiple: vi.fn(), byId: () => null }),
}))
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ myid: { value: 99 } }),
}))
vi.mock('~/components/NoticeMessage', () => ({
  default: { name: 'NoticeMessage', template: '<div class="notice-stub"><slot /></div>' },
}))
vi.mock('~/components/ClearanceManageItem', () => ({
  default: { name: 'ClearanceManageItem', template: '<div class="item-stub" />', props: ['message', 'item', 'index'] },
}))
vi.mock('~/components/HelperProposalCard', () => ({
  default: { name: 'HelperProposalCard', template: '<div class="proposal-stub" />' },
}))

// #imports supplies the Nuxt composables the component uses, incl. a fake $api.
vi.mock('#imports', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRuntimeConfig: () => ({ public: { USER_SITE: 'https://www.ilovefreegle.org' } }),
  useNuxtApp: () => ({ $api: { message: { bulkEditLink } } }),
}))

import ClearanceManager from '~/components/ClearanceManager.vue'

const mountOpts = {
  props: { id: 1 },
  global: { stubs: { 'b-spinner': true, 'b-form-textarea': true } },
}

describe('ClearanceManager share update link', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    bulkEditLink.mockResolvedValue({ ret: 0, token: 'abc123token' })
  })

  it('offers a "Get an update link" button to the owner', () => {
    const w = mount(ClearanceManager, mountOpts)
    expect(w.find('[data-testid="clearance-sharelink-get"]').exists()).toBe(true)
  })

  it('mints a link and shows a shareable /clearance/update URL', async () => {
    const w = mount(ClearanceManager, mountOpts)
    await w.find('[data-testid="clearance-sharelink-get"]').trigger('click')
    await flushPromises()
    expect(bulkEditLink).toHaveBeenCalledWith(1)
    expect(w.vm.shareLink).toBe(
      'https://www.ilovefreegle.org/clearance/update/abc123token'
    )
    expect(w.find('[data-testid="clearance-sharelink-input"]').exists()).toBe(true)
  })
})
