<template>
  <div data-testid="lockdown-notice">
    <h4>Member notice</h4>
    <p class="small text-muted">
      Shown across the top of every page on the member site. Leave it empty for
      no notice.
      <template v-if="!active">
        After a lockdown closes, a notice set here shows for a day.
      </template>
    </p>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <b-button
        variant="outline-secondary"
        size="sm"
        data-testid="lockdown-notice-none"
        @click="text = ''"
      >
        No notice
      </b-button>
      <b-button
        v-for="w in wordings"
        :key="w.label"
        variant="outline-secondary"
        size="sm"
        :data-testid="'lockdown-notice-wording-' + w.label"
        @click="text = w.text"
      >
        {{ w.label }}
      </b-button>
    </div>
    <b-form-textarea
      v-model="text"
      rows="3"
      :maxlength="LOCKDOWN_NOTICE_MAX"
      placeholder="No notice"
      data-testid="lockdown-notice-text"
    />
    <div class="d-flex align-items-center gap-2 mt-2">
      <b-button
        variant="primary"
        :disabled="!changed || busy"
        data-testid="lockdown-notice-save"
        @click="save"
      >
        Save
      </b-button>
      <span class="small text-muted" data-testid="lockdown-notice-current">
        {{ saved ? 'Members see: "' + saved + '"' : 'Members see no notice.' }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import {
  LOCKDOWN_BACK_TO_NORMAL,
  LOCKDOWN_NOTICE_MAX,
  LOCKDOWN_NOTICE_WORDINGS,
} from '~/modtools/utils/lockdownAreas'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: "No notice" or
// custom text, edited here and only sent when Save is pressed. The earlier
// wordings are offered as starting text, and "Things are back to normal" once
// the lockdown has closed.
const props = defineProps({
  notice: {
    type: String,
    default: null,
  },
  active: {
    type: Boolean,
    default: false,
  },
  busy: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['save'])

const saved = computed(() => props.notice ?? '')
const text = ref(saved.value)

// A save from elsewhere (or closing, which clears the notice) resets the box.
watch(saved, (v) => {
  text.value = v
})

const wordings = computed(() =>
  props.active
    ? LOCKDOWN_NOTICE_WORDINGS
    : [{ label: 'Back to normal', text: LOCKDOWN_BACK_TO_NORMAL }]
)

const changed = computed(() => text.value.trim() !== saved.value)

function save() {
  const trimmed = text.value.trim()
  emit('save', trimmed === '' ? null : trimmed)
}
</script>
