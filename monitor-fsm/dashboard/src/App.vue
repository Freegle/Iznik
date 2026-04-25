<template>
  <div id="app">
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #20c997;">
      <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">Monitor FSM Dashboard</span>
        <button
          type="button"
          class="btn btn-light btn-sm ms-auto"
          @click="handlePushStatus"
          :disabled="pushing"
        >
          <span v-if="pushing" class="spinner-border spinner-border-sm me-1" role="status"></span>
          <i v-else class="bi bi-arrow-up-circle me-1"></i>
          Push to Discourse
        </button>
        <span v-if="pushResult" :class="['ms-2 small', pushResult.ok ? 'text-white' : 'text-warning']">
          {{ pushResult.ok ? '✓ posted' : '✗ ' + pushResult.msg }}
        </span>
      </div>
    </nav>

    <div class="container-fluid mt-4 pb-4">
      <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
          <button
            class="nav-link active"
            data-bs-toggle="tab"
            data-bs-target="#bugs-panel"
            type="button"
            role="tab"
          >
            Bugs
            <span class="badge bg-secondary ms-2">{{ bugsData.state.bugs.length }}</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button
            class="nav-link"
            data-bs-toggle="tab"
            data-bs-target="#drafts-panel"
            type="button"
            role="tab"
          >
            Drafts
            <span class="badge bg-secondary ms-2">{{ draftsData.state.drafts.length }}</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button
            class="nav-link"
            data-bs-toggle="tab"
            data-bs-target="#iters-panel"
            type="button"
            role="tab"
          >
            Iterations
            <span class="badge bg-secondary ms-2">{{ itersData.state.iterations.length }}</span>
          </button>
        </li>
      </ul>

      <div class="tab-content">
        <div id="bugs-panel" class="tab-pane fade show active" role="tabpanel">
          <BugTable
            :bugs="bugsData.state.bugs"
            :loading="bugsData.state.loading"
            @bug-state-change="bugsData.refresh()"
          />
        </div>

        <div id="drafts-panel" class="tab-pane fade" role="tabpanel">
          <div class="row">
            <div v-for="draft in draftsData.state.drafts" :key="draft.id" class="col-lg-6">
              <DraftCard :draft="draft" @updated="draftsData.refresh()" />
            </div>
          </div>
          <div v-if="draftsData.state.drafts.length === 0" class="alert alert-info">
            No drafts pending.
          </div>
        </div>

        <div id="iters-panel" class="tab-pane fade" role="tabpanel">
          <IterTable
            :iterations="itersData.state.iterations"
            :loading="itersData.state.loading"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useBugs, useDrafts, useIterations, pushStatusPost } from './composables/useApi'
import BugTable from './components/BugTable.vue'
import DraftCard from './components/DraftCard.vue'
import IterTable from './components/IterTable.vue'

const bugsData = useBugs()
const draftsData = useDrafts()
const itersData = useIterations()

const pushing = ref(false)
const pushResult = ref<{ ok: boolean; msg?: string } | null>(null)

const handlePushStatus = async () => {
  pushing.value = true
  pushResult.value = null
  try {
    const result = await pushStatusPost()
    pushResult.value = { ok: result.posted, msg: result.reason }
    setTimeout(() => { pushResult.value = null }, 5000)
  } catch (err: any) {
    pushResult.value = { ok: false, msg: String(err?.message ?? err) }
  } finally {
    pushing.value = false
  }
}
</script>

<style scoped>
#app {
  min-height: 100vh;
  background-color: #f8f9fa;
}

nav {
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.container-fluid {
  max-width: 1400px;
  margin: 0 auto;
}

.nav-tabs {
  border-bottom: 2px solid #dee2e6;
}

.nav-link {
  color: #6c757d;
  border: none;
  border-bottom: 3px solid transparent;
  font-weight: 500;
}

.nav-link:hover {
  color: #20c997;
  border-bottom-color: transparent;
}

.nav-link.active {
  color: #20c997;
  border-bottom-color: #20c997;
  background-color: transparent;
}
</style>
