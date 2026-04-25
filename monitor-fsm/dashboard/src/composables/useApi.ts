import { reactive } from 'vue'
import type { BugRow, DraftRow, IterRow, PrLive } from '../types'

export function useBugs() {
  const state = reactive({ bugs: [] as BugRow[], loading: false })

  const refresh = async () => {
    state.loading = true
    try {
      const data = await fetch('/api/bugs').then(r => r.json())
      state.bugs = Array.isArray(data) ? data : []
    } catch (error) {
      console.error('Failed to fetch bugs:', error)
    } finally {
      state.loading = false
    }
  }

  refresh()
  const interval = setInterval(refresh, 60000)
  const stop = () => clearInterval(interval)

  return { state, refresh, stop }
}

export function useDrafts() {
  const state = reactive({ drafts: [] as DraftRow[], loading: false })

  const refresh = async () => {
    state.loading = true
    try {
      const data = await fetch('/api/drafts').then(r => r.json())
      state.drafts = Array.isArray(data) ? data : []
    } catch (error) {
      console.error('Failed to fetch drafts:', error)
    } finally {
      state.loading = false
    }
  }

  refresh()
  const interval = setInterval(refresh, 60000)
  const stop = () => clearInterval(interval)

  return { state, refresh, stop }
}

export function useIterations() {
  const state = reactive({ iterations: [] as IterRow[], loading: false })

  const refresh = async () => {
    state.loading = true
    try {
      const data = await fetch('/api/iterations').then(r => r.json())
      state.iterations = Array.isArray(data) ? data : []
    } catch (error) {
      console.error('Failed to fetch iterations:', error)
    } finally {
      state.loading = false
    }
  }

  refresh()
  const interval = setInterval(refresh, 30000)
  const stop = () => clearInterval(interval)

  return { state, refresh, stop }
}

export async function approveDraft(id: number): Promise<void> {
  const resp = await fetch(`/api/drafts/${id}/approve`, { method: 'POST' })
  if (!resp.ok) throw new Error(`Failed to approve draft: ${resp.statusText}`)
}

export async function rejectDraft(id: number, reason?: string): Promise<void> {
  const resp = await fetch(`/api/drafts/${id}/reject`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ reason }),
  })
  if (!resp.ok) throw new Error(`Failed to reject draft: ${resp.statusText}`)
}

export async function editDraft(id: number, body: string): Promise<void> {
  const resp = await fetch(`/api/drafts/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ body }),
  })
  if (!resp.ok) throw new Error(`Failed to save draft: ${resp.statusText}`)
}

export async function setBugState(topic: number, post: number, state: string, reason?: string): Promise<void> {
  const resp = await fetch(`/api/bugs/${topic}/${post}/state`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ state, reason }),
  })
  if (!resp.ok) throw new Error(`Failed to set bug state: ${resp.statusText}`)
}

export async function pushStatusPost(): Promise<{ posted: boolean; reason?: string }> {
  const resp = await fetch('/api/status/push', { method: 'POST' })
  return resp.json()
}

export function usePrsLive() {
  const state = reactive({ prs: [] as PrLive[], loading: false, lastRefreshed: null as string | null })

  const refresh = async () => {
    state.loading = true
    try {
      const data = await fetch('/api/prs/live').then(r => r.json())
      state.prs = Array.isArray(data) ? data : []
      state.lastRefreshed = new Date().toLocaleTimeString()
    } catch (error) {
      console.error('Failed to fetch PRs:', error)
    } finally {
      state.loading = false
    }
  }

  refresh()
  const interval = setInterval(refresh, 60000)
  const stop = () => clearInterval(interval)

  return { state, refresh, stop }
}

export async function sendDraft(id: number): Promise<void> {
  const resp = await fetch(`/api/drafts/${id}/send`, { method: 'POST' })
  if (!resp.ok) throw new Error(`Failed to send draft: ${resp.statusText}`)
}
