export interface BugRow {
  topic: number
  post: number
  topic_title: string | null
  reporter: string | null
  excerpt: string | null
  state: string          // 'open' | 'investigating' | 'fix-queued' | 'deferred' | 'fixed'
  pr_number: number | null
  feature_area: string | null
  reason: string | null
  first_seen_at: string
  last_seen_at: string
  fixed_at: string | null
}

export interface DraftRow {
  id: number
  topic: number
  post: number
  username: string
  quote: string
  body: string
  pr_number: number | null
  pr_url: string | null
  pr_state: string | null
  queued_at: string
  approved_at: string | null
  posted_at: string | null
  rejected_at: string | null
  rejection_reason: string | null
}

export interface IterRow {
  id: number
  started_at: string
  ended_at: string | null
  outcome: string | null
  steps_used: number | null
  prs_created: number | null
  note: string | null
}
