<template>
  <div id="app">
    <!-- Top bar -->
    <nav class="navbar navbar-dark" style="background-color: #20c997;">
      <div class="container-fluid">
        <span class="navbar-brand mb-0 h4">Triage Dashboard</span>
      </div>
    </nav>

    <!-- Main layout -->
    <div class="container-fluid" style="padding: 1rem;">
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

      <!-- Recently Fixed -->
      <div v-if="recentlyFixed.length > 0" class="mt-4">
        <div class="d-flex align-items-center mb-2">
          <h6 class="mb-0 text-muted">Recently Fixed</h6>
          <span class="badge bg-success ms-2">{{ recentlyFixed.length }}</span>
        </div>
        <div class="card">
          <table class="table table-sm mb-0" style="table-layout: fixed; width: 100%;">
            <colgroup>
              <col style="width: 14%;">
              <col style="width: 11%;">
              <col>
              <col style="width: 6%;">
              <col style="width: 7%;">
            </colgroup>
            <thead class="table-light">
              <tr>
                <th>Area</th>
                <th>Reporter</th>
                <th>Summary</th>
                <th>PR</th>
                <th>Fixed</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="bug in recentlyFixed" :key="`${bug.topic}-${bug.post}`">
                <td class="text-muted small" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ (bug as any).group_key || bug.feature_area || 'Uncategorised' }}</td>
                <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  <a :href="`https://discourse.ilovefreegle.org/t/${bug.topic}/${bug.post}`" target="_blank" rel="noopener" class="text-decoration-none">
                    {{ bug.reporter || 'Unknown' }}
                  </a>
                </td>
                <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  <span :title="bug.excerpt || bug.topic_title || ''">{{ bug.excerpt || bug.topic_title || '—' }}</span>
                </td>
                <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  <a v-if="bug.pr_number" :href="`https://github.com/Freegle/Iznik/pull/${bug.pr_number}`" target="_blank" rel="noopener" class="text-decoration-none">#{{ bug.pr_number }}</a>
                  <span v-else class="text-muted">—</span>
                </td>
                <td class="text-muted small" style="white-space: nowrap;">{{ formatFixedAge(bug.fixed_at) }}</td>
              </tr>
            </tbody>
          </table>
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
import { computed } from 'vue'
import { useBugs, useDrafts, useIterations, usePrsLive } from './composables/useApi'
import PrPanel from './components/PrPanel.vue'
import BugPanel from './components/BugPanel.vue'
import ReplyQueue from './components/ReplyQueue.vue'
import IterTable from './components/IterTable.vue'

const bugsData = useBugs()
const draftsData = useDrafts()
const itersData = useIterations()
const prsData = usePrsLive()

const recentlyFixed = computed(() => {
  const sevenDaysAgo = new Date()
  sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7)
  return bugsData.state.bugs.filter(bug =>
    bug.state === 'fixed' && bug.fixed_at && new Date(bug.fixed_at) > sevenDaysAgo
  )
})

function formatFixedAge(date: string | null): string {
  if (!date) return ''
  const now = new Date()
  const d = new Date(date)
  const hours = Math.floor((now.getTime() - d.getTime()) / 3600000)
  if (hours < 24) return `${hours}h ago`
  return `${Math.floor(hours / 24)}d ago`
}


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
