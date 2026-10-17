import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSamples from '~/modtools/components/ModSysAdminUxSamples.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const sampleData = {
  date: '2026-10-07',
  flow: 'post',
  step: 'photos',
  sessions: [
    {
      session_id: 'abc123',
      events: [
        {
          ts: '2026-10-07T10:00:00Z',
          event_type: 'flow_step',
          action_name: 'enter',
          step: 'item',
          dwell_ms: null,
          label: '',
          component: '',
          page_route: '/give',
        },
        {
          ts: '2026-10-07T10:00:09Z',
          event_type: 'interaction',
          action_name: 'click',
          step: 'photos',
          dwell_ms: 9000,
          label: 'Add photo',
          component: 'PhotoUpload',
          page_route: '/give',
        },
      ],
    },
  ],
}

describe('ModSysAdminUxSamples', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.load = vi.fn().mockResolvedValue()
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxSamples, {
      props: {
        modelValue: true,
        flow: 'post',
        step: 'photos',
        start: '2026-10-05',
        end: '2026-10-07',
        ...props,
      },
      global: {
        stubs: {
          ...uxStubs,
          'b-form-group': {
            template: '<div class="form-group"><slot /></div>',
          },
          'b-form-select': {
            name: 'SelectStub',
            template: '<select class="sample-day" />',
            props: ['modelValue', 'options'],
          },
          'b-modal': {
            name: 'ModalStub',
            template:
              '<div class="modal-stub" v-if="modelValue"><slot /></div>',
            props: ['modelValue', 'title'],
          },
        },
      },
    })

  describe('the title', () => {
    const title = (props) =>
      make(props).findComponent({ name: 'ModalStub' }).props('title')

    it('names the funnel and the step in words, as the step detail it opened from does', () => {
      expect(title({ flow: 'give', step: 'give-mobile-photos' })).toBe(
        'Sample sessions: Offering something / Photos on 2026-10-07'
      )
    })

    it('says which page where two pages share a name', () => {
      expect(title({ flow: 'give', step: 'give-mobile-options' })).toBe(
        'Sample sessions: Offering something / Options (mobile) on 2026-10-07'
      )
    })

    it('prints a funnel or a step it does not know as it is', () => {
      expect(title({ flow: 'newflow', step: 'odd-step' })).toBe(
        'Sample sessions: newflow / odd-step on 2026-10-07'
      )
    })
  })

  it('says that sample sessions are not filtered by device or sign-in', () => {
    store.samples.data = { sessions: [] }
    expect(make().text()).toContain(
      'Sample sessions are not filtered by device or sign-in.'
    )
  })

  it('lists every day of the period, latest first', () => {
    const select = make().findComponent({ name: 'SelectStub' })
    expect(select.props('options')).toEqual([
      '2026-10-07',
      '2026-10-06',
      '2026-10-05',
    ])
    expect(select.props('modelValue')).toBe('2026-10-07')
  })

  it('offers no day choice for a one-day period', () => {
    const w = make({ start: '2026-10-07' })
    expect(w.findComponent({ name: 'SelectStub' }).exists()).toBe(false)
  })

  it('loads the chosen day', async () => {
    const w = make()
    await flushPromises()
    store.load.mockClear()
    w.findComponent({ name: 'SelectStub' }).vm.$emit(
      'update:modelValue',
      '2026-10-05'
    )
    await flushPromises()
    expect(store.load).toHaveBeenCalledWith('samples', {
      date: '2026-10-05',
      flow: 'post',
      step: 'photos',
    })
  })

  it('goes back to the last day when reopened', async () => {
    const w = make()
    w.findComponent({ name: 'SelectStub' }).vm.$emit(
      'update:modelValue',
      '2026-10-05'
    )
    await w.setProps({ modelValue: false })
    await flushPromises()
    store.load.mockClear()
    await w.setProps({ modelValue: true })
    await flushPromises()
    expect(store.load).toHaveBeenCalledTimes(1)
    expect(store.load.mock.calls[0][1].date).toBe('2026-10-07')
  })

  it('loads the samples for the flow, step and date when opened', async () => {
    make()
    await flushPromises()
    expect(store.load).toHaveBeenCalledWith('samples', {
      date: '2026-10-07',
      flow: 'post',
      step: 'photos',
    })
  })

  it('does not load while closed', async () => {
    make({ modelValue: false })
    await flushPromises()
    expect(store.load).not.toHaveBeenCalled()
  })

  it('loads when it becomes open', async () => {
    const w = make({ modelValue: false })
    await w.setProps({ modelValue: true })
    await flushPromises()
    expect(store.load).toHaveBeenCalledTimes(1)
  })

  it('shows each session as a timeline of its events in order', () => {
    store.samples.data = sampleData
    const w = make()
    const sessions = w.findAll('.session')
    expect(sessions).toHaveLength(1)
    expect(sessions[0].text()).toContain('abc123')
    const events = sessions[0].findAll('.event')
    expect(events).toHaveLength(2)
    expect(events[0].text()).toContain('flow_step')
    expect(events[0].text()).toContain('enter')
    expect(events[1].text()).toContain('click')
    expect(events[1].text()).toContain('Add photo')
    expect(events[1].text()).toContain('PhotoUpload')
    expect(events[1].text()).toContain('9.0 s')
  })

  it('warns when Loki could not be read', () => {
    store.samples.data = { ...sampleData, sessions: [], loki_error: 'timeout' }
    const w = make()
    expect(w.find('.notice-message.warning').text()).toContain('timeout')
  })

  it('shows the empty message with no sessions', () => {
    store.samples.data = { sessions: [] }
    expect(make().text()).toContain('No sampled sessions')
  })

  it('emits update:modelValue when the modal asks to close', async () => {
    const w = make()
    w.findComponent({ name: 'ModalStub' }).vm.$emit('update:modelValue', false)
    expect(w.emitted('update:modelValue')[0]).toEqual([false])
  })
})
