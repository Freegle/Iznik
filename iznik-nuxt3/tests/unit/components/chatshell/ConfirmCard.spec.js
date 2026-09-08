import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfirmCard from '~/components/chatshell/ConfirmCard.vue'

describe('ConfirmCard', () => {
  it('shows what will be posted and lets each line be changed', async () => {
    const w = mount(ConfirmCard, {
      props: { postType: 'Offer', slots: { item: 'Grey sofa', description: 'Good nick', quantity: 2, postcode: 'EH3 6SS' }, community: 'Edinburgh Freegle', locationName: 'Edinburgh' },
      global: { stubs: { ProxyImage: true } },
    })
    expect(w.find('[data-testid="confirm-item"]').text()).toContain('Grey sofa')
    expect(w.find('[data-testid="confirm-item"]').text()).toContain('2 available')
    expect(w.find('[data-testid="confirm-where"]').text()).toContain('Near Edinburgh · Edinburgh Freegle')
    await w.find('[data-testid="confirm-description"]').trigger('click')
    expect(w.emitted('edit')[0]).toEqual(['description'])
  })
  it('offers the deliver toggle only for offers and says when there are no details', async () => {
    const offer = mount(ConfirmCard, { props: { postType: 'Offer', slots: { item: 'x' } }, global: { stubs: { ProxyImage: true } } })
    expect(offer.find('input[type="checkbox"]').exists()).toBe(true)
    expect(offer.find('[data-testid="confirm-description"]').text()).toContain('None added')
    await offer.find('input[type="checkbox"]').setValue(true)
    expect(offer.emitted('toggle')[0]).toEqual(['delivery', true])
    const wanted = mount(ConfirmCard, { props: { postType: 'Wanted', slots: { item: 'x' } }, global: { stubs: { ProxyImage: true } } })
    expect(wanted.find('input[type="checkbox"]').exists()).toBe(false)
  })
})
