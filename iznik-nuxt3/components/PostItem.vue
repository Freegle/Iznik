<template>
  <div>
    <div class="d-flex flex-column">
      <label :for="$id('what')">What is it?</label>
      <b-form-input
        :id="$id('what')"
        v-model="item"
        placeholder="In a single word or phrase, what is it?"
        maxlength="60"
        spellcheck="true"
        size="lg"
        @blur="onBlur"
      />
    </div>
    <div>
      <NoticeMessage v-if="invalidNumeric" variant="danger" class="mt-1 mb-1">
        <p class="mb-0">
          {{ INVALID_ITEM_MESSAGE }}
        </p>
      </NoticeMessage>
      <NoticeMessage v-if="invalidVague" variant="danger" class="mt-1 mb-1">
        <p class="mb-0">
          {{ VAGUE_ITEM_MESSAGE }}
        </p>
      </NoticeMessage>
      <NoticeMessage v-if="vague" variant="warning" class="mt-1 mb-1">
        <p>
          Please avoid very general terms. Be precise - you'll get a better
          response.
        </p>
      </NoticeMessage>
      <PostItemWarning :item="item" :type="type" />
      <NoticeMessage v-if="duplicate" variant="warning" class="mt-2">
        <p>
          You already have an open post
          <span class="fw-bold">{{ duplicate.subject }}</span
          >.
        </p>
        <p>
          If it's the same item, please go to
          <nuxt-link no-prefetch to="/myposts">My Posts</nuxt-link> and use the
          <em>Repost</em> button.
        </p>
        <p>
          If it's actually a different item, please change the name slightly -
          then it'll be clearer for everyone that it's not the same.
        </p>
      </NoticeMessage>
    </div>
  </div>
</template>
<script setup>
import NoticeMessage from './NoticeMessage'
import PostItemWarning from './PostItemWarning'
import { uid } from '~/composables/useId'
import { useComposeStore } from '~/stores/compose'
import { useMessageStore } from '~/stores/message'
import { computed } from '#imports'
import { useMe } from '~/composables/useMe'
import {
  isNumericOnlyItem,
  isVagueItem,
  INVALID_ITEM_MESSAGE,
  VAGUE_ITEM_MESSAGE,
} from '~/composables/useItemValidation'

const emit = defineEmits(['update:edititem', 'blur'])

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  type: {
    type: String,
    required: true,
  },
  edit: {
    type: Boolean,
    required: false,
    default: false,
  },
  edititem: {
    type: String,
    required: false,
    default: null,
  },
})

const composeStore = useComposeStore()
const messageStore = useMessageStore()
const { myid } = useMe()

// Data properties
const vagueness = [
  '^eney fink$',
  '^eney think$',
  '^furniture$',
  '^household$',
  '^anything$',
  '^stuff$',
  '^things$',
  '^tools$',
  '^garden$',
  '^goods$',
  "^don't know$",
  '^items$',
  '^browsing$',
  '^browse$',
  '^any$',
]

// Computed properties
const item = computed({
  get() {
    if (!props.edit) {
      const msg = composeStore?.message(props.id)
      return msg?.item
    } else {
      return props.edititem
    }
  },
  set(newValue) {
    if (!props.edit) {
      composeStore.setItem({
        id: props.id,
        item: newValue,
      })
    } else {
      console.log('Set new item', newValue)
      emit('update:edititem', newValue)
    }
  },
})

// A purely-numeric item (e.g. "123") is never a valid description; reject it.
const invalidNumeric = computed(() => isNumericOnlyItem(item.value))

// A content-free catch-all term ("anything", "everything") can't be posted —
// this is a hard block, shown in place of the softer "too vague" warning below.
const invalidVague = computed(() => isVagueItem(item.value))

const vague = computed(() => {
  // The unpostable cases show their own danger message, so don't also warn.
  if (invalidNumeric.value || invalidVague.value) {
    return false
  }

  let ret = false
  let currentItem = item.value

  if (currentItem) {
    currentItem = currentItem.toLowerCase()

    vagueness.forEach((v) => {
      if (currentItem.match(v)) {
        ret = true
      }
    })
  }

  return ret
})

const duplicate = computed(() => {
  let ret = null

  const messages = messageStore?.all

  // This may be a repost, in which case we don't want to flag the original.
  const composing = composeStore?.message(props.id)

  const repostof = composing?.repostof

  messages.forEach((m) => {
    if (
      m.fromuser &&
      m.fromuser === myid.value &&
      m.type === props.type &&
      m.item?.name &&
      item.value &&
      m.item.name.toLowerCase() === item.value.toLowerCase() &&
      m.id !== props.id &&
      (!repostof || repostof !== m.id) &&
      (!m.outcomes || !m.outcomes.length)
    ) {
      // Exactly duplicate of open post.
      ret = m
    }
  })

  return ret
})

// Methods
const $id = (type) => {
  return uid(type)
}

const onBlur = () => {
  // Emit blur event with the current item name for AI illustration fetching
  if (item.value && item.value.trim()) {
    emit('blur', item.value.trim())
  }
}
</script>
