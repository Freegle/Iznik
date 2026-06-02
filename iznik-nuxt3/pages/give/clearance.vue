<template>
  <div>
    <client-only>
      <b-row class="m-0">
        <b-col cols="12" lg="8" offset-lg="2" class="bg-white">
          <h1 class="mt-3">Offer lots of items at once</h1>
          <p class="text-muted">
            Doing a clearance? List everything in one post. People can then tell
            you which items they'd like and how many — all in a single
            conversation.
          </p>

          <b-form-group label="What's the overall offer called?" label-for="bulk-title">
            <b-form-input
              id="bulk-title"
              v-model="title"
              placeholder="e.g. Office Clearance"
              maxlength="60"
              data-testid="clearance-title"
            />
          </b-form-group>

          <b-form-group label="A few words about the offer (optional)" label-for="bulk-desc">
            <b-form-textarea
              id="bulk-desc"
              v-model="description"
              rows="2"
              placeholder="e.g. Charity office clearance, collection from Brighton on Tue 7th / Wed 8th."
              maxlength="2000"
            />
          </b-form-group>

          <h3 class="mt-3 h5">The items</h3>
          <BulkItemEditor v-model="items" />

          <h3 class="mt-4 h5">Where are you?</h3>
          <PostCode :value="postcode" @selected="postcodeSelect" @cleared="postcodeClear" />
          <ComposeGroup v-if="postcodeValid" class="mt-2" />
          <NoticeMessage v-if="noGroups" variant="warning" class="mt-2">
            There's no Freegle community covering that area yet.
          </NoticeMessage>

          <div v-if="!loggedIn" class="mt-3">
            <b-form-group label="Your email address" label-for="bulk-email">
              <b-form-input
                id="bulk-email"
                v-model="email"
                type="email"
                placeholder="you@example.com"
                data-testid="clearance-email"
              />
            </b-form-group>
          </div>

          <div class="my-4">
            <SpinButton
              variant="primary"
              size="lg"
              icon-name="gift"
              :disabled="!canSubmit"
              label="Post these items"
              data-testid="clearance-submit"
              @handle="submit"
            />
            <NoticeMessage v-if="wentWrong" variant="danger" class="mt-2">
              Something went wrong posting your items. Please try again.
            </NoticeMessage>
          </div>
        </b-col>
      </b-row>
    </client-only>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from '#imports'
import {
  setup,
  postcodeSelect,
  postcodeClear,
  makeCanSubmit,
} from '~/composables/useCompose'
import { useComposeStore } from '~/stores/compose'
import PostCode from '~/components/PostCode.vue'
import ComposeGroup from '~/components/ComposeGroup.vue'
import BulkItemEditor from '~/components/BulkItemEditor.vue'
import SpinButton from '~/components/SpinButton.vue'
import NoticeMessage from '~/components/NoticeMessage.vue'

const composeStore = useComposeStore()
const router = useRouter()

const { email, loggedIn, postcode, postcodeValid, noGroups } =
  await setup('Offer')

const title = ref('')
const description = ref('')
const items = ref([])
const wentWrong = ref(false)

const messageValid = computed(
  () =>
    !!title.value.trim() &&
    items.value.some((i) => i.name && i.name.trim())
)

const emailValid = computed(() => /^\S+@\S+\.\S+$/.test(email.value || ''))

const canSubmit = makeCanSubmit({
  messageValid,
  loggedIn,
  emailValid,
  emailBelongsToSomeoneElse: computed(() => false),
  postcodeValid,
  closed: computed(() => false),
  noGroups,
  requirePostcode: true,
})

async function submit(callback) {
  wentWrong.value = false
  try {
    const message = {
      type: 'Offer',
      item: title.value.trim(),
      description: description.value,
      availablenow: 1,
      attachments: [],
      bulkitems: items.value,
    }
    const id = await composeStore.createDraft(message, email.value)
    await composeStore.submitDraft(id, email.value)
    router.push('/myposts')
  } catch (e) {
    console.error('Clearance submit failed', e)
    wentWrong.value = true
  } finally {
    if (callback) callback()
  }
}

definePageMeta({ layout: 'default' })
useHead({ title: 'Offer lots of items at once' })
</script>
