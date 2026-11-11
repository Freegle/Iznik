import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import StoryAddModal from '~/components/StoryAddModal.vue'

const mockModal = ref(null)

const mockStoryStore = {
  add: vi.fn().mockResolvedValue(undefined),
}

const mockComposeStore = {
  uploading: false,
  storyDraft: null,
  saveStoryDraft: vi.fn(function (draft) {
    mockComposeStore.storyDraft = draft
  }),
  clearStoryDraft: vi.fn(function () {
    mockComposeStore.storyDraft = null
  }),
}

const mockHide = vi.fn()

const mockImageStore = {
  post: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/stories', () => ({
  useStoryStore: () => mockStoryStore,
}))

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => mockComposeStore,
}))

vi.mock('~/stores/image', () => ({
  useImageStore: () => mockImageStore,
}))

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModal,
    hide: mockHide,
  }),
}))

describe('StoryAddModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockComposeStore.uploading = false
    mockComposeStore.storyDraft = null
    globalThis.__mockAuthStore = { user: { id: 42 }, forceLogin: false }
  })

  afterEach(() => {
    delete globalThis.__mockAuthStore
  })

  function fillIn(wrapper, headline, story) {
    return Promise.all([
      wrapper.find('.b-form-input').setValue(headline),
      wrapper.find('.b-form-textarea').setValue(story),
    ])
  }

  function clickButton(wrapper, text) {
    return wrapper
      .findAll('.b-button')
      .find((b) => b.text().includes(text))
      .trigger('click')
  }

  // Open the uploader and have it hand back one uploaded photo, as the real
  // OurUploader does once a file is processed.
  async function attachPhoto(wrapper, id = 7) {
    await clickButton(wrapper, 'Add a photo')
    wrapper
      .findComponent('.our-uploader')
      .vm.$emit('update:modelValue', [
        { id, ouruid: 'uid' + id, externalmods: {} },
      ])
    await flushPromises()
  }

  function createWrapper() {
    return mount(StoryAddModal, {
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="b-modal"><slot /><slot name="footer" /></div>',
            props: ['scrollable', 'title', 'size', 'noStacking'],
            emits: ['shown'],
            // useOurModal shows the modal as soon as it mounts, so shown fires
            // right away in real life too.
            mounted() {
              this.$emit('shown')
            },
            methods: {
              show() {},
              hide() {},
            },
          },
          'b-row': {
            template: '<div class="b-row"><slot /></div>',
          },
          'b-col': {
            template: '<div class="b-col"><slot /></div>',
            props: ['cols'],
          },
          'b-form-input': {
            template:
              '<input class="b-form-input" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" :class="{ \'is-invalid\': $attrs.class?.includes(\'is-invalid\') }" />',
            props: ['modelValue', 'spellcheck', 'maxlength', 'placeholder'],
            emits: ['update:modelValue'],
          },
          'b-form-textarea': {
            template:
              '<textarea class="b-form-textarea" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" :class="{ \'is-invalid\': $attrs.class?.includes(\'is-invalid\') }"></textarea>',
            props: ['modelValue', 'spellcheck', 'rows', 'placeholder'],
            emits: ['update:modelValue'],
          },
          'b-button': {
            template:
              '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
            emits: ['click'],
          },
          'b-img': {
            template: '<img class="b-img" :src="src" />',
            props: ['thumbnail', 'width', 'src'],
          },
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon', 'size', 'flip'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
          OurUploader: {
            template: '<div class="our-uploader" />',
            props: ['modelValue', 'type'],
            emits: ['update:modelValue'],
          },
          OurUploadedImage: {
            template: '<img class="our-uploaded-image" :src="src" />',
            props: ['src', 'modifiers', 'alt', 'width'],
          },
          NuxtPicture: {
            template: '<img class="nuxt-picture" :src="src" />',
            props: [
              'src',
              'fit',
              'format',
              'provider',
              'modifiers',
              'alt',
              'width',
            ],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders modal', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-modal').exists()).toBe(true)
    })

    it('shows headline input', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-input').exists()).toBe(true)
    })

    it('shows story textarea', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-textarea').exists()).toBe(true)
    })

    it('shows add photo button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Add a photo')
    })

    it('shows notice about sharing', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('share your story')
    })

    it('names the Freegle name that will be shared', () => {
      globalThis.__mockAuthStore.user.displayname = 'Jo Freegler'
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('with your Freegle name (Jo Freegler)')
    })

    it('leaves out the brackets when there is no name', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('with your Freegle name')
      expect(wrapper.text()).not.toContain('name (')
    })
  })

  describe('form labels', () => {
    it('shows summary prompt', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('quick summary')
    })

    it('shows story prompt', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('why you freegle')
    })

    it('shows photo prompt', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Please add a photo')
    })

    it('says why a photo matters', () => {
      // Only about one story in six arrives with a photo, so the ask has to
      // explain what the photo does rather than call it optional.
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('more people freegling')
      expect(wrapper.text()).not.toContain('Photos are option')
    })

    it('asks what difference freegling made', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('difference')
    })
  })

  describe('footer buttons', () => {
    it('shows cancel button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Cancel')
    })

    it('shows submit button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Add Your Story')
    })
  })

  describe('photo upload', () => {
    it('shows uploader when add photo clicked', async () => {
      const wrapper = createWrapper()
      const addPhotoBtn = wrapper
        .findAll('.b-button')
        .find((b) => b.text().includes('Add a photo'))
      await addPhotoBtn.trigger('click')
      expect(wrapper.find('.our-uploader').exists()).toBe(true)
    })
  })

  describe('validation', () => {
    it('shows error for missing headline on submit', async () => {
      const wrapper = createWrapper()
      // Fill only story, not headline
      const textarea = wrapper.find('.b-form-textarea')
      await textarea.setValue('My story content')

      const submitBtn = wrapper
        .findAll('.b-button')
        .find((b) => b.text().includes('Add Your Story'))
      await submitBtn.trigger('click')
      await flushPromises()

      // Store should not be called without headline
      expect(mockStoryStore.add).not.toHaveBeenCalled()
    })

    it('shows error for missing story on submit', async () => {
      const wrapper = createWrapper()
      // Fill only headline, not story
      const input = wrapper.find('.b-form-input')
      await input.setValue('My headline')

      const submitBtn = wrapper
        .findAll('.b-button')
        .find((b) => b.text().includes('Add Your Story'))
      await submitBtn.trigger('click')
      await flushPromises()

      // Store should not be called without story
      expect(mockStoryStore.add).not.toHaveBeenCalled()
    })
  })

  describe('successful submission', () => {
    it('posts straight away when a photo is attached', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await attachPhoto(wrapper, 7)
      expect(wrapper.find('.our-uploaded-image').exists()).toBe(true)

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(wrapper.text()).not.toContain('No photo yet')
      expect(mockStoryStore.add).toHaveBeenCalledWith(
        'Test Headline',
        'Test Story Content',
        7,
        true
      )
    })

    it('asks for a photo once before posting without one', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(mockStoryStore.add).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('No photo yet')
    })

    it('posts without a photo when they say so', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      await clickButton(wrapper, 'Post without a photo')
      await flushPromises()

      expect(mockStoryStore.add).toHaveBeenCalledWith(
        'Test Headline',
        'Test Story Content',
        null,
        true
      )
    })

    it('posts without a photo on a second press of the main button', async () => {
      // The nudge is asked once; pressing Add Your Story again means "no, post it".
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(mockStoryStore.add).toHaveBeenCalledTimes(1)
      expect(mockStoryStore.add).toHaveBeenCalledWith(
        'Test Headline',
        'Test Story Content',
        null,
        true
      )
    })

    it('lets them add a photo from the nudge instead', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      const nudgeAdd = wrapper
        .find('.photo-nudge')
        .findAll('.b-button')
        .find((b) => b.text().includes('Add a photo'))
      await nudgeAdd.trigger('click')
      await flushPromises()

      expect(wrapper.find('.our-uploader').exists()).toBe(true)
      expect(wrapper.text()).not.toContain('No photo yet')
      expect(mockStoryStore.add).not.toHaveBeenCalled()
    })

    it('does not post twice while the first post is still in flight', async () => {
      let finish
      mockStoryStore.add.mockReturnValueOnce(
        new Promise((resolve) => {
          finish = resolve
        })
      )
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      await clickButton(wrapper, 'Post without a photo')
      await clickButton(wrapper, 'Post without a photo')
      await clickButton(wrapper, 'Add Your Story')
      finish()
      await flushPromises()

      expect(mockStoryStore.add).toHaveBeenCalledTimes(1)
      expect(wrapper.text()).toContain('grateful')
    })

    it('treats a double-click on the main button as one press', async () => {
      // The second click of a double-click lands before the nudge has rendered,
      // so it must not count as the answer to a question nobody has seen yet.
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      const submit = wrapper
        .findAll('.b-button')
        .find((b) => b.text().includes('Add Your Story'))
      submit.trigger('click')
      submit.trigger('click')
      await flushPromises()

      expect(mockStoryStore.add).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('No photo yet')
    })

    it('asks about a photo again after a validation error', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()
      expect(wrapper.text()).toContain('No photo yet')

      await wrapper.find('.b-form-input').setValue('')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()
      expect(wrapper.text()).not.toContain('No photo yet')

      await wrapper.find('.b-form-input').setValue('Fixed Headline')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(mockStoryStore.add).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('No photo yet')
    })

    it('shows thank you message after submission', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()
      await clickButton(wrapper, 'Post without a photo')
      await flushPromises()

      expect(wrapper.text()).toContain('grateful')
    })
  })

  describe('login required', () => {
    beforeEach(() => {
      globalThis.__mockAuthStore = { user: null, forceLogin: false }
    })

    it('does not try to add the story when logged out', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(mockStoryStore.add).not.toHaveBeenCalled()
    })

    it('asks for a login when logged out', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(globalThis.__mockAuthStore.forceLogin).toBe(true)
      expect(wrapper.emitted('login-required')).toHaveLength(1)
    })

    it('saves what they typed so the login modal closing it does not lose it', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(mockComposeStore.saveStoryDraft).toHaveBeenCalledWith({
        headline: 'Test Headline',
        story: 'Test Story Content',
        image: null,
      })
    })

    it('still complains about an empty story rather than asking for a login', async () => {
      const wrapper = createWrapper()

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(globalThis.__mockAuthStore.forceLogin).toBe(false)
      expect(mockComposeStore.saveStoryDraft).not.toHaveBeenCalled()
    })
  })

  describe('draft', () => {
    it('picks up a saved draft when shown', async () => {
      mockComposeStore.storyDraft = {
        headline: 'Saved Headline',
        story: 'Saved Story',
        image: null,
      }

      const wrapper = createWrapper()
      await flushPromises()

      expect(wrapper.find('.b-form-input').element.value).toBe('Saved Headline')
      expect(wrapper.find('.b-form-textarea').element.value).toBe('Saved Story')
    })

    it('submits the restored draft once they are logged back in', async () => {
      mockComposeStore.storyDraft = {
        headline: 'Saved Headline',
        story: 'Saved Story',
        image: { id: 9, imageuid: 'uid9', imagemods: {} },
      }

      const wrapper = createWrapper()
      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()

      expect(mockStoryStore.add).toHaveBeenCalledWith(
        'Saved Headline',
        'Saved Story',
        9,
        true
      )
    })

    it('clears the draft once the story is added', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      await clickButton(wrapper, 'Add Your Story')
      await flushPromises()
      await clickButton(wrapper, 'Post without a photo')
      await flushPromises()

      expect(mockComposeStore.clearStoryDraft).toHaveBeenCalled()
    })

    it('clears the draft if they cancel', async () => {
      const wrapper = createWrapper()
      await fillIn(wrapper, 'Test Headline', 'Test Story Content')

      await clickButton(wrapper, 'Cancel')

      expect(mockComposeStore.clearStoryDraft).toHaveBeenCalled()
      expect(mockHide).toHaveBeenCalled()
    })
  })

  describe('disabled state', () => {
    it('disables buttons while uploading', () => {
      mockComposeStore.uploading = true
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('.b-button')
      const cancelBtn = buttons.find((b) => b.text().includes('Cancel'))
      expect(cancelBtn.attributes('disabled')).toBeDefined()
    })
  })

  describe('image handling', () => {
    it('initially has no image displayed', () => {
      const wrapper = createWrapper()
      // No image is shown initially since story.image is null
      expect(wrapper.find('.our-uploaded-image').exists()).toBe(false)
    })
  })

  describe('icons', () => {
    it('shows camera icon on add photo button', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('[data-icon="camera"]').exists()).toBe(true)
    })
  })

  describe('placeholders', () => {
    it('shows headline placeholder', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-input').attributes('placeholder')).toContain(
        'summary'
      )
    })

    it('shows story placeholder', () => {
      const wrapper = createWrapper()
      expect(
        wrapper.find('.b-form-textarea').attributes('placeholder')
      ).toContain('given')
    })
  })
})
