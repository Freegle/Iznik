import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BulkOfferUpdateItem from '~/components/BulkOfferUpdateItem.vue'

function makeItem(overrides = {}) {
  return {
    id: 10,
    name: 'Filing cabinet',
    quantity: 4,
    condition: 'Good',
    dimensions: '120x80cm',
    available: true,
    photo: 'https://images.example/timg_1.jpg',
    ...overrides,
  }
}

function mountItem(item, props = {}) {
  return mount(BulkOfferUpdateItem, {
    props: { item, index: 0, ...props },
  })
}

describe('BulkOfferUpdateItem', () => {
  it('renders the item name, condition and dimensions', () => {
    const w = mountItem(makeItem())
    expect(w.text()).toContain('Filing cabinet')
    expect(w.text()).toContain('Good')
    expect(w.text()).toContain('120x80cm')
    expect(w.find('img').attributes('src')).toContain('timg_1.jpg')
  })

  it('maps LikeNew to a friendly "Like new" label', () => {
    const w = mountItem(makeItem({ condition: 'LikeNew' }))
    expect(w.text()).toContain('Like new')
  })

  it('emits an availability change when the toggle is switched off', async () => {
    const w = mountItem(makeItem())
    const cb = w.find('[data-testid="bulkupdate-toggle-10"] input')
    await cb.setValue(false)
    const ev = w.emitted('update')
    expect(ev).toBeTruthy()
    expect(ev[0][0]).toEqual({ itemid: 10, available: false })
  })

  it('increments the count and emits the new quantity', async () => {
    const w = mountItem(makeItem())
    await w.find('[data-testid="bulkupdate-inc-10"]').trigger('click')
    const ev = w.emitted('update')
    expect(ev[0][0]).toEqual({ itemid: 10, quantity: 5 })
  })

  it('decrements the count but never below zero', async () => {
    const w = mountItem(makeItem({ quantity: 1 }))
    await w.find('[data-testid="bulkupdate-dec-10"]').trigger('click')
    expect(w.emitted('update')[0][0]).toEqual({ itemid: 10, quantity: 0 })
  })

  it('commits a typed count on change', async () => {
    const w = mountItem(makeItem())
    const input = w.find('[data-testid="bulkupdate-qty-10"]')
    input.element.value = '2'
    await input.trigger('input')
    await input.trigger('change')
    const ev = w.emitted('update')
    expect(ev[ev.length - 1][0]).toEqual({ itemid: 10, quantity: 2 })
  })

  it('shows a taken item as struck-through/dimmed', () => {
    const w = mountItem(makeItem({ available: false }))
    expect(w.classes()).toContain('bulkupdate-item--taken')
  })

  it('shows a placeholder when there is no photo', () => {
    const w = mountItem(makeItem({ photo: '' }))
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('.bulkupdate-item__nophoto').exists()).toBe(true)
  })
})
