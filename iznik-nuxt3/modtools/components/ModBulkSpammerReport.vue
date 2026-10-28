<template>
  <b-modal
    id="bulkspamreportmodal"
    ref="modal"
    :title="title"
    size="lg"
    no-stacking
  >
    <template #default>
      <p>
        {{ who }} will be reported to the Spam team, who will check before
        adding anyone to the list. Anyone already reported or already on the
        list is left as they are.
      </p>
      <p>
        Spammers must have
        <strong>clear intent to deliberately spam/scam</strong>, or be a
        multijoiner across communities a long way apart, without a good reason.
        See
        <ExternalLink
          href="https://wiki.ilovefreegle.org/Spammers#SPAM_checking_before_submission"
        >
          the wiki
        </ExternalLink>
        for more details and advice.
      </p>
      <p>
        One reason is used for all of them. It will be visible to other
        moderators, so please make sure it's helpful.
      </p>
      <b-form-textarea
        v-model="reason"
        placeholder="Enter a reason"
        data-test="bulk-spam-reason"
      />
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Cancel </b-button>
      <b-button
        variant="danger"
        :disabled="!reason || !reason.trim()"
        data-test="bulk-spam-send"
        @click="send"
      >
        Send report
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, computed } from 'vue'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  count: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['confirm'])

const { modal, hide } = useOurModal()

const reason = ref(null)

const title = computed(() =>
  props.count === 1
    ? 'Report 1 as a spammer'
    : `Report ${props.count} as spammers`
)

const who = computed(() =>
  props.count === 1 ? 'This member' : `These ${props.count} members`
)

function send() {
  if (reason.value && reason.value.trim()) {
    emit('confirm', reason.value.trim())
    hide()
  }
}
</script>
