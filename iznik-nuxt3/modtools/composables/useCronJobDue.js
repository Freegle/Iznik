// Due and overdue arithmetic for the SysAdmin cron jobs tab.
//
// The scheduler stamps last_run_at when a job STARTS, and interval_minutes is
// the job's nominal cadence from the API's registry. Comparing "now" against
// last start plus one interval marks every per-minute job overdue within sixty
// seconds of the page loading, and every windowed daily job the moment its
// window closes. A job is only overdue here once it has missed a whole cycle
// (capped at an hour) plus two minutes of scheduler jitter.

export function cronJobNominalDue(job) {
  if (!job?.last_run_at || !job.interval_minutes) return null
  return new Date(
    new Date(job.last_run_at).getTime() + job.interval_minutes * 60000
  )
}

export function cronJobGraceMinutes(intervalMinutes) {
  return Math.max(2, Math.min(intervalMinutes, 60))
}

export function cronJobDeadline(job) {
  const due = cronJobNominalDue(job)
  if (!due) return null
  return new Date(
    due.getTime() + cronJobGraceMinutes(job.interval_minutes) * 60000
  )
}

export function isCronJobOverdue(job, now = new Date()) {
  const deadline = cronJobDeadline(job)
  return deadline !== null && now > deadline
}

// Text for the Next Due column: 'overdue' past the grace deadline, 'due' once
// the nominal time has passed but the grace has not, minutes for the last five,
// and the supplied relative formatter for anything further out.
export function cronJobNextDueText(
  job,
  now = new Date(),
  format = (d) => d.toISOString()
) {
  const due = cronJobNominalDue(job)
  if (!due) return '-'
  if (isCronJobOverdue(job, now)) return 'overdue'
  if (now > due) return 'due'
  const remainMins = Math.ceil((due - now) / 60000)
  if (remainMins <= 5) return remainMins <= 1 ? '~1m' : `~${remainMins}m`
  return format(due)
}
