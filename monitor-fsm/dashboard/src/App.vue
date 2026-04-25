<template>
  <div id="app">
    <!-- Top bar -->
    <nav class="navbar navbar-dark" style="background-color: #20c997;">
      <div class="container-fluid">
        <span class="navbar-brand mb-0 h4">Freegle Monitor</span>
        <div class="d-flex gap-3 ms-auto align-items-center">
          <small v-if="lastRefreshTime" class="text-white-50">
            Last: {{ lastRefreshTime }}
          </small>
          <button
            class="btn btn-light btn-sm"
            @click="handlePushStatus"
            :disabled="pushing"
            title="Push status to Discourse"
          >
            <span v-if="pushing" class="spinner-border spinner-border-sm me-1"></span>
            <i v-else class="bi bi-arrow-up-circle me-1"></i>
            Push Status
          </button>
          <span v-if="pushResult" :class="['small', pushResult.ok ? 'text-white' : 'text-warning']">
            {{ pushResult.ok ? '✓' : '✗ ' + pushResult.msg }}
          </span>
        </div>
      </div>
    </nav>

    <!-- Main layout -->
    <div class="container-fluid" style="padding: 1rem; max-width: 1600px; margin: 0 auto;">
      <div class="row g-3">
        <!-- Left column: Bugs (primary work items) -->
        <div class="col-lg-5">
          <BugPanel
            :bugs="bugsData.state.bugs"
            :loading="bugsData.state.loading"
            @refresh="bugsData.refresh()"
          />
        </div>

        <!-- Middle column: PRs -->
        <div class="col-lg-4">
          <PrPanel
            :prs="prsData.state.prs"
            :loading="prsData.state.loading"
            :lastRefreshed="prsData.state.lastRefreshed"
            @refresh="prsData.refresh()"
          />
        </div>

        <!-- Right column: Reply Queue -->
        <div class="col-lg-3">
          <ReplyQueue
            :drafts="draftsData.state.drafts"
            :loading="draftsData.state.loading"
            @refresh="draftsData.refresh()"
          />
        </div>
      </div>

      <!-- Iteration history (collapsible) -->
      <div class="mt-4">
        <details class="card">
          <summary class="card-header" style="cursor: pointer; user-select: none;">
            <span class="ms-2">Iteration History</span>
            <span class="badge bg-secondary ms-2">{{ itersData.state.iterations.length }}</span>
          </summary>
          <div class="card-body" style="padding: 0; overflow-x: auto;">
            <IterTable
              :iterations="itersData.state.iterations"
              :loading="itersData.state.loading"
            />
          </div>
        </details>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useBugs, useDrafts, useIterations, usePrsLive, pushStatusPost } from './composables/useApi'
import PrPanel from './components/PrPanel.vue'
import BugPanel from './components/BugPanel.vue'
import ReplyQueue from './components/ReplyQueue.vue'
import IterTable from './components/IterTable.vue'

const bugsData = useBugs()
const draftsData = useDrafts()
const itersData = useIterations()
const prsData = usePrsLive()

const pushing = ref(false)
const pushResult = ref<{ ok: boolean; msg?: string } | null>(null)
const lastRefreshTime = ref<string>('')

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

const updateRefreshTime = () => {
  lastRefreshTime.value = new Date().toLocaleTimeString()
}

onMounted(() => {
  updateRefreshTime()
  const interval = setInterval(updateRefreshTime, 60000)
  return () => clearInterval(interval)
})
</script>

<style scoped>
#app {
  min-height: 100vh;
  background-color: #f8f9fa;
}

nav {
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  margin-bottom: 1rem;
}

details > summary {
  outline: none;
}

details > summary::-webkit-details-marker {
  display: none;
}

details > summary::before {
  content: '▶ ';
  display: inline-block;
  margin-right: 0.5rem;
  transition: transform 0.2s;
}

details[open] > summary::before {
  transform: rotate(90deg);
}

.card {
  border: 1px solid #dee2e6;
  border-radius: 0.25rem;
}

.card-header {
  background-color: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
  padding: 1rem;
  font-weight: 500;
}

.card-header:hover {
  background-color: #e9ecef;
}
</style>
