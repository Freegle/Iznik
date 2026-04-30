<template>
  <div>
    <h1>AI Images</h1>
    <p class="text-muted">
      Images flagged by volunteers as needing regeneration. Review and replace where needed.
    </p>

    <div v-if="loading" class="text-center py-4">
      <b-spinner />
    </div>

    <div v-else-if="localImages.length === 0" class="text-muted py-4">
      No images currently need regeneration.
    </div>

    <div v-else>
      <div
        v-for="img in localImages"
        :key="img.id"
        class="mb-3 border rounded p-3"
      >
        <div class="row g-3 align-items-start">
          <!-- Image column: current image, then preview below -->
          <div class="col-auto" style="width: 172px">
            <!-- Current image -->
            <div class="text-center mb-2">
              <div class="text-muted small mb-1">Current image</div>
              <b-img
                v-if="img.image_url"
                :src="img.image_url"
                width="160"
                height="120"
                style="object-fit: cover; border: 2px solid #dc3545"
                :alt="img.name"
              />
              <div
                v-else
                class="bg-light border d-flex align-items-center justify-content-center"
                style="width: 160px; height: 120px"
              >
                <span class="text-muted small">No image</span>
              </div>
            </div>

            <!-- Preview image below current (shown after regeneration) -->
            <div v-if="previewFor(img)" class="text-center">
              <div class="text-muted small mb-1">Preview (new)</div>
              <b-img
                :src="previewFor(img)"
                width="160"
                height="120"
                style="object-fit: cover; border: 2px solid #28a745"
                :alt="'Preview for ' + img.name"
              />
            </div>

            <!-- Spinner while generating -->
            <div v-else-if="regenerating[img.id]" class="text-center">
              <div class="text-muted small mb-1">Generating…</div>
              <div
                class="bg-light border d-flex align-items-center justify-content-center"
                style="width: 160px; height: 120px"
              >
                <b-spinner />
              </div>
            </div>
          </div>

          <!-- Details column -->
          <div class="col">
            <h6 class="mb-1">{{ img.name }}</h6>

            <!-- Vote summary -->
            <div class="mb-2">
              <b-badge variant="danger" class="me-1">{{ img.reject_count }} Reject</b-badge>
              <b-badge variant="success">{{ img.approve_count }} Approve</b-badge>
            </div>

            <!-- Voter list -->
            <div v-if="img.votes && img.votes.length" class="mb-2">
              <div class="text-muted small fw-bold">Votes:</div>
              <ul class="list-unstyled mb-0">
                <li
                  v-for="vote in img.votes"
                  :key="vote.userid"
                  class="small"
                >
                  <span :class="vote.result === 'Reject' ? 'text-danger' : 'text-success'">
                    {{ vote.result }}
                  </span>
                  — {{ vote.displayname }}
                  <span v-if="vote.containspeople === 1" class="text-warning ms-1">(contains people)</span>
                </li>
              </ul>
            </div>

            <!-- Notes textarea -->
            <b-form-textarea
              v-model="notes[img.id]"
              placeholder="What's wrong? (e.g. shows a person, wrong item, inappropriate)"
              rows="2"
              class="mb-2"
            />

            <!-- Action buttons -->
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <b-button
                v-if="previewFor(img)"
                data-testid="accept-btn"
                variant="primary"
                :disabled="accepting[img.id]"
                @click="handleAccept(img)"
              >
                <b-spinner v-if="accepting[img.id]" small class="me-1" />
                Accept New Image
              </b-button>

              <b-button
                data-testid="regenerate-btn"
                variant="white"
                :disabled="regenerating[img.id]"
                @click="handleRegenerate(img)"
              >
                <b-spinner v-if="regenerating[img.id]" small class="me-1" />
                {{ previewFor(img) ? 'Try Again' : 'Regenerate' }}
              </b-button>

              <span v-if="errors[img.id]" class="text-danger small">
                {{ errors[img.id] }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAIImages } from '~/modtools/composables/useAIImages'

definePageMeta({ layout: 'default' })

const { images, loading, fetchReview, regenerate, accept } = useAIImages()

const localImages = ref([])
const notes = ref({})
const localPreviews = ref({}) // set after clicking Regenerate
const regenerating = ref({})
const accepting = ref({})
const errors = ref({})

onMounted(async () => {
  await fetchReview()
  localImages.value = [...images.value]
})

// Returns the preview URL for an image — locally generated takes precedence,
// then falls back to pending_image_url already stored on the server.
function previewFor(img) {
  return localPreviews.value[img.id] || img.pending_image_url || null
}

async function handleRegenerate(img) {
  regenerating.value[img.id] = true
  errors.value[img.id] = null

  try {
    const result = await regenerate(img.id, notes.value[img.id] || '')
    if (result?.preview_url) {
      localPreviews.value[img.id] = result.preview_url
    } else {
      errors.value[img.id] = 'Generation returned no image. Please try again.'
    }
  } catch (e) {
    errors.value[img.id] = 'Generation failed. Please try again.'
  } finally {
    regenerating.value[img.id] = false
  }
}

async function handleAccept(img) {
  const preview = previewFor(img)
  if (!preview) return
  accepting.value[img.id] = true
  errors.value[img.id] = null

  try {
    await accept(img.id, img.pending_externaluid || '')
    localImages.value = localImages.value.filter((i) => i.id !== img.id)
  } catch (e) {
    errors.value[img.id] = 'Failed to accept image. Please try again.'
  } finally {
    accepting.value[img.id] = false
  }
}
</script>
