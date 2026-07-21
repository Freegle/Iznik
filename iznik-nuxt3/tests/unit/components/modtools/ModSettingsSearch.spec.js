import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSettingsSearch from '~/modtools/components/ModSettingsSearch.vue'

// The real click-outside handler needs a live document listener we don't
// exercise here; the component's own logic is what these tests cover.
vi.mock('@vueuse/core', () => ({
  onClickOutside: vi.fn(),
}))

const stubs = {
  'b-input-group': { template: '<div class="input-group"><slot /></div>' },
  'b-input-group-text': { template: '<span><slot /></span>' },
  // Only v-model is re-emitted. focus and keydown are left to fall through to
  // the root input as native listeners - emitting them as well would run the
  // parent's handler twice, so a single arrow key would move the selection two
  // rows.
  'b-form-input': {
    template:
      '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'type', 'placeholder', 'autocomplete'],
    emits: ['update:modelValue'],
  },
  'b-button': {
    template: '<button class="btn" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant'],
  },
  'v-icon': { template: '<span />', props: ['icon'] },
}

async function search(term) {
  const wrapper = mount(ModSettingsSearch, { global: { stubs } })
  const input = wrapper.find('input')

  await input.trigger('focus')
  await input.setValue(term)

  return wrapper
}

describe('ModSettingsSearch', () => {
  it('shows nothing until something is typed', async () => {
    const wrapper = mount(ModSettingsSearch, { global: { stubs } })
    await wrapper.find('input').trigger('focus')

    expect(wrapper.find('.results').exists()).toBe(false)
  })

  it('lists matching settings', async () => {
    const wrapper = await search('tagline')
    const results = wrapper.findAll('.result')

    expect(results.length).toBeGreaterThan(0)
    expect(results[0].text()).toContain('Tagline')
  })

  it('says where each result lives', async () => {
    const wrapper = await search('tagline')

    // Without the tab and section, a result tells a moderator nothing about
    // where the setting actually is.
    expect(wrapper.find('.result').text()).toContain('Community')
    expect(wrapper.find('.result').text()).toContain('How It Looks')
  })

  it('reports when nothing matches', async () => {
    const wrapper = await search('zzzznotasetting')

    expect(wrapper.findAll('.result')).toHaveLength(0)
    expect(wrapper.text()).toContain('No settings match')
  })

  it('emits the chosen setting when a result is clicked', async () => {
    const wrapper = await search('tagline')
    await wrapper.find('.result').trigger('click')

    const emitted = wrapper.emitted('select')
    expect(emitted).toHaveLength(1)
    expect(emitted[0][0].id).toBe('tagline')
  })

  it('hides the results once one is chosen', async () => {
    const wrapper = await search('tagline')
    await wrapper.find('.result').trigger('click')

    expect(wrapper.find('.results').exists()).toBe(false)
  })

  it('highlights the first result before any arrow key is pressed', async () => {
    const wrapper = await search('repost')

    expect(wrapper.findAll('.result')[0].classes()).toContain('active')
  })

  it('moves the selection with the arrow keys and picks it with Enter', async () => {
    const wrapper = await search('repost')
    const input = wrapper.find('input')
    const rows = wrapper.findAll('.result')

    expect(rows.length).toBeGreaterThan(1)
    const secondLabel = rows[1].text()

    await input.trigger('keydown.down')

    expect(wrapper.findAll('.result')[1].classes()).toContain('active')

    await input.trigger('keydown.enter')

    // Enter must pick the row the arrow key moved to, not the first one.
    expect(secondLabel).toContain(wrapper.emitted('select')[0][0].label)
  })

  it('wraps around when arrowing up from the top', async () => {
    const wrapper = await search('repost')
    const input = wrapper.find('input')
    const count = wrapper.findAll('.result').length

    await input.trigger('keydown.up')

    expect(wrapper.findAll('.result')[count - 1].classes()).toContain('active')
  })

  it('clears the query on Escape', async () => {
    const wrapper = await search('tagline')

    await wrapper.find('input').trigger('keydown.esc')

    expect(wrapper.find('input').element.value).toBe('')
    expect(wrapper.find('.results').exists()).toBe(false)
  })

  it('clears the query with the Clear button', async () => {
    const wrapper = await search('tagline')

    await wrapper.find('.btn').trigger('click')

    expect(wrapper.find('input').element.value).toBe('')
  })

  it('shortens long descriptions rather than filling the dropdown', async () => {
    // The welcome-email description runs to several hundred characters.
    const wrapper = await search('welcome email')
    const text = wrapper.find('.result').text()

    expect(text).toContain('...')
    expect(text.length).toBeLessThan(300)
  })
})
