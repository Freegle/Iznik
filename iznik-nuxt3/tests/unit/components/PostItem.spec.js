import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import PostItem from '~/components/PostItem.vue'

const mockMessage = vi.fn()
const mockSetItem = vi.fn()
const mockMessages = ref([])

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    message: mockMessage,
    setItem: mockSetItem,
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    get all() {
      return mockMessages.value
    },
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myid: ref(123),
  }),
}))

vi.mock('~/composables/useId', () => ({
  uid: (type) => `test-${type}`,
}))

describe('PostItem', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessage.mockReturnValue({ item: '' })
    mockMessages.value = []
  })

  function createWrapper(props = {}) {
    return mount(PostItem, {
      props: {
        id: 1,
        type: 'Offer',
        ...props,
      },
      global: {
        stubs: {
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @blur="$emit(\'blur\')" />',
            props: [
              'modelValue',
              'id',
              'placeholder',
              'maxlength',
              'spellcheck',
              'size',
            ],
            emits: ['update:modelValue', 'blur'],
          },
          'v-icon': {
            template: '<i :data-icon="icon"></i>',
            props: ['icon', 'scale'],
          },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders input with label', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('label').text()).toBe('What is it?')
      expect(wrapper.find('input').exists()).toBe(true)
    })
  })

  describe('vague item detection (warning only)', () => {
    it.each([
      ['furniture', true],
      ['household', true],
      ['sofa', false],
      ['table', false],
      // Content-free terms are now a hard block (invalidVague), shown in place
      // of the warning, so vague is false for them.
      ['anything', false],
      ['stuff', false],
    ])('item "%s" is vague=%s', (item, isVague) => {
      mockMessage.mockReturnValue({ item })
      const wrapper = createWrapper()
      expect(wrapper.vm.vague).toBe(isVague)
    })

    it('shows a warning for a broad-but-postable category', () => {
      mockMessage.mockReturnValue({ item: 'furniture' })
      const wrapper = createWrapper()
      expect(wrapper.find('.notice-message.warning').exists()).toBe(true)
      expect(wrapper.text()).toContain('Please avoid very general terms')
    })
  })

  describe('unpostable vague item rejection', () => {
    it.each([
      ['anything', true],
      ['everything', true],
      ['stuff', true],
      ['all', true],
      ['furniture', false],
      ['sofa', false],
      ['', false],
    ])('item "%s" is invalidVague=%s', (item, expected) => {
      mockMessage.mockReturnValue({ item })
      const wrapper = createWrapper()
      expect(wrapper.vm.invalidVague).toBe(expected)
    })

    it('shows a danger message for a content-free item', () => {
      mockMessage.mockReturnValue({ item: 'everything' })
      const wrapper = createWrapper()
      expect(wrapper.find('.notice-message.danger').exists()).toBe(true)
      expect(wrapper.text()).toContain('Please be specific')
    })
  })

  describe('numeric-only item rejection', () => {
    it.each([
      ['123', true],
      ['  42 ', true],
      ['0', true],
      ['3 chairs', false],
      ['Sofa', false],
      ['', false],
    ])('item "%s" is invalidNumeric=%s', (item, expected) => {
      mockMessage.mockReturnValue({ item })
      const wrapper = createWrapper()
      expect(wrapper.vm.invalidNumeric).toBe(expected)
    })

    it('shows a danger message for a purely-numeric item', () => {
      mockMessage.mockReturnValue({ item: '123' })
      const wrapper = createWrapper()
      expect(wrapper.find('.notice-message.danger').exists()).toBe(true)
      expect(wrapper.text()).toContain('not a valid item')
    })

    it('shows no danger message for a normal item', () => {
      mockMessage.mockReturnValue({ item: 'Sofa' })
      const wrapper = createWrapper()
      expect(wrapper.find('.notice-message.danger').exists()).toBe(false)
    })
  })

  describe('item warnings', () => {
    it.each([
      ['sofa', 'Upholstered household items'],
      ['mattress', 'Upholstered household items'],
      ['helmet', 'Motorcycle and cycle helmets'],
      ['car seat', 'Car seats'],
      ['knife', 'Knives'],
    ])('shows warning for "%s"', (item, expectedType) => {
      mockMessage.mockReturnValue({ item })
      const wrapper = createWrapper()
      expect(wrapper.vm.warn).toBeTruthy()
      expect(wrapper.vm.warn.type).toContain(expectedType)
    })

    it('shows no warning for safe items', () => {
      mockMessage.mockReturnValue({ item: 'books' })
      const wrapper = createWrapper()
      expect(wrapper.vm.warn).toBeNull()
    })
  })

  describe('duplicate detection', () => {
    it('detects duplicate posts from same user', () => {
      mockMessage.mockReturnValue({ item: 'Table' })
      mockMessages.value = [
        {
          id: 2,
          fromuser: 123,
          type: 'Offer',
          item: { name: 'table' },
          subject: 'OFFER: Table',
        },
      ]
      const wrapper = createWrapper({ id: 1, type: 'Offer' })
      expect(wrapper.vm.duplicate).toBeTruthy()
    })

    it('does not flag different items as duplicate', () => {
      mockMessage.mockReturnValue({ item: 'Chair' })
      mockMessages.value = [
        {
          id: 2,
          fromuser: 123,
          type: 'Offer',
          item: { name: 'Table' },
        },
      ]
      const wrapper = createWrapper({ id: 1, type: 'Offer' })
      expect(wrapper.vm.duplicate).toBeNull()
    })

    it('does not flag posts from other users', () => {
      mockMessage.mockReturnValue({ item: 'Table' })
      mockMessages.value = [
        {
          id: 2,
          fromuser: 456, // different user
          type: 'Offer',
          item: { name: 'table' },
        },
      ]
      const wrapper = createWrapper({ id: 1, type: 'Offer' })
      expect(wrapper.vm.duplicate).toBeNull()
    })
  })

  describe('edit mode', () => {
    it('uses edititem prop in edit mode', () => {
      const wrapper = createWrapper({ edit: true, edititem: 'Test Item' })
      expect(wrapper.vm.item).toBe('Test Item')
    })

    it('emits update:edititem in edit mode', () => {
      const wrapper = createWrapper({ edit: true, edititem: 'Test' })
      wrapper.vm.item = 'New Value'
      expect(wrapper.emitted('update:edititem')).toBeTruthy()
    })
  })

  describe('blur event', () => {
    it('emits blur with item value on input blur', async () => {
      mockMessage.mockReturnValue({ item: 'Chair' })
      const wrapper = createWrapper()
      await wrapper.find('input').trigger('blur')
      expect(wrapper.emitted('blur')).toBeTruthy()
      expect(wrapper.emitted('blur')[0]).toEqual(['Chair'])
    })

    it('does not emit blur when item is empty', async () => {
      mockMessage.mockReturnValue({ item: '' })
      const wrapper = createWrapper()
      await wrapper.find('input').trigger('blur')
      expect(wrapper.emitted('blur')).toBeFalsy()
    })
  })
})
