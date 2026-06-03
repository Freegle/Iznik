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

          <!-- Where (postcode) first: it picks the community. -->
          <h2 class="bulk-section">Where are you?</h2>
          <PostCode
            :value="postcode"
            @selected="postcodeSelect"
            @cleared="postcodeClear"
          />
          <ComposeGroup v-if="postcodeValid" class="mt-2" />
          <NoticeMessage v-if="noGroups" variant="warning" class="mt-2">
            There's no Freegle community covering that area yet.
          </NoticeMessage>

          <h2 class="bulk-section">What are you offering?</h2>
          <b-form-input
            v-model="title"
            placeholder="e.g. Office Clearance"
            maxlength="60"
            data-testid="clearance-title"
          />
          <b-form-textarea
            v-model="description"
            class="mt-2"
            rows="2"
            placeholder="A few words about the offer (optional) — e.g. Charity office clearance, collection from Brighton."
            maxlength="2000"
          />

          <h2 class="bulk-section">The items</h2>
          <BulkItemEditor v-model="items" />

          <h2 class="bulk-section">When can people collect?</h2>
          <p class="bulk-help">
            People pick one of these when they reply, so collections stay in set
            times.
          </p>
          <div
            v-for="(s, i) in slots"
            :key="i"
            class="d-flex gap-2 mb-2 align-items-center"
          >
            <b-form-input
              v-model="slots[i]"
              placeholder="e.g. Tue 7 Apr, 10am–4pm"
              maxlength="120"
              :data-testid="'slot-' + i"
            />
            <b-button
              variant="link"
              class="text-danger p-0"
              aria-label="Remove collection time"
              @click="removeSlot(i)"
            >
              <v-icon icon="trash" />
            </b-button>
          </div>
          <b-button
            variant="outline-primary"
            size="sm"
            data-testid="add-slot"
            @click="slots.push('')"
          >
            <v-icon icon="plus" /> Add a collection time
          </b-button>

          <h2 class="bulk-section">Access instructions</h2>
          <p class="bulk-help">
            Only shared with someone once you promise them an item — e.g. the
            exact address, a gate code, or intercom instructions.
          </p>
          <b-form-textarea
            v-model="accessInstructions"
            rows="2"
            placeholder="e.g. 12 High St, side door, buzz flat 3. Optional."
            maxlength="2000"
            data-testid="clearance-access"
          />

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
import { ref, computed, onMounted } from 'vue'
import { useRouter } from '#imports'
import {
  setup,
  postcodeSelect,
  postcodeClear,
  makeCanSubmit,
} from '~/composables/useCompose'
import { useComposeStore } from '~/stores/compose'
import { useAuthStore } from '~/stores/auth'
import PostCode from '~/components/PostCode.vue'
import ComposeGroup from '~/components/ComposeGroup.vue'
import BulkItemEditor from '~/components/BulkItemEditor.vue'
import SpinButton from '~/components/SpinButton.vue'
import NoticeMessage from '~/components/NoticeMessage.vue'

const composeStore = useComposeStore()
const authStore = useAuthStore()
const router = useRouter()

const { email, loggedIn, postcode, postcodeValid, noGroups } = await setup(
  'Offer'
)

const title = ref('')
const description = ref('')
const items = ref([])
const slots = ref([''])
const accessInstructions = ref('')
const wentWrong = ref(false)

// Bulk freegling is login-only — there's no logged-out flow. Show the
// sign-up/login modal immediately so people start from a signed-in state.
onMounted(() => {
  if (!loggedIn.value) {
    authStore.forceLogin = true
  }
})

function removeSlot(i) {
  slots.value.splice(i, 1)
  if (!slots.value.length) slots.value.push('')
}

const messageValid = computed(
  () => !!title.value.trim() && items.value.some((i) => i.name && i.name.trim())
)

const canSubmit = makeCanSubmit({
  messageValid,
  loggedIn,
  emailValid: computed(() => false),
  emailBelongsToSomeoneElse: computed(() => false),
  postcodeValid,
  closed: computed(() => false),
  noGroups,
  requirePostcode: true,
})

async function submit(callback) {
  if (!loggedIn.value) {
    authStore.forceLogin = true
    if (callback) callback()
    return
  }
  wentWrong.value = false
  try {
    const message = {
      type: 'Offer',
      item: title.value.trim(),
      description: description.value,
      availablenow: 1,
      attachments: [],
      bulkitems: items.value,
      bulkslots: slots.value.map((s) => s.trim()).filter(Boolean),
      accessinstructions: accessInstructions.value.trim(),
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

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

/* One consistent heading style for every section on the page. */
.bulk-section {
  font-size: 1.15rem;
  font-weight: 600;
  color: $color-green--darker;
  margin: 1.25rem 0 0.5rem;
}

.bulk-help {
  font-size: 0.85rem;
  color: $color-gray--dark;
  margin-bottom: 0.5rem;
}
</style>
