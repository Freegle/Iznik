import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModSettingsSection from '~/modtools/components/ModSettingsSection.vue'

const stubs = {
  'b-card': { template: '<div class="card"><slot /></div>', props: ['noBody'] },
  'b-card-header': { template: '<div class="card-header"><slot /></div>' },
  'b-card-body': { template: '<div class="card-body"><slot /></div>' },
  'b-collapse': {
    template: '<div class="collapse" :data-open="modelValue"><slot /></div>',
    props: ['id', 'modelValue', 'role'],
  },
  // No explicit @click - the parent's handler falls through to the root
  // button as a native listener. Emitting as well would fire it twice.
  'b-button': {
    template: '<button class="btn"><slot /></button>',
    props: ['variant', 'block', 'href'],
  },
}

function mountSection(openSection, props = {}) {
  return mount(ModSettingsSection, {
    props: { id: 'accordion-spam', title: 'Spam Detection', ...props },
    slots: { default: '<p>body content</p>' },
    global: {
      stubs,
      provide: { settingsOpenSection: openSection },
    },
  })
}

describe('ModSettingsSection', () => {
  it('shows its title', () => {
    expect(mountSection(ref(null)).text()).toContain('Spam Detection')
  })

  it('is closed when a different section is open', () => {
    const wrapper = mountSection(ref('accordion-maps'))

    expect(wrapper.find('.collapse').attributes('data-open')).toBe('false')
  })

  it('is open when it is the open section', () => {
    const wrapper = mountSection(ref('accordion-spam'))

    expect(wrapper.find('.collapse').attributes('data-open')).toBe('true')
  })

  it('opens itself when its header is clicked', async () => {
    const openSection = ref(null)
    const wrapper = mountSection(openSection)

    await wrapper.find('button').trigger('click')

    expect(openSection.value).toBe('accordion-spam')
  })

  it('closes itself when its header is clicked again', async () => {
    const openSection = ref('accordion-spam')
    const wrapper = mountSection(openSection)

    await wrapper.find('button').trigger('click')

    expect(openSection.value).toBeNull()
  })

  // Only one section is expanded at a time, so opening one must displace the
  // other rather than leaving both open.
  it('displaces whichever section was open', async () => {
    const openSection = ref('accordion-maps')
    const wrapper = mountSection(openSection)

    await wrapper.find('button').trigger('click')

    expect(openSection.value).toBe('accordion-spam')
  })

  it('renders its body content', () => {
    expect(mountSection(ref('accordion-spam')).text()).toContain('body content')
  })
})
