import { describe, it, expect } from 'vitest'
import {
  cronJobDeadline,
  cronJobGraceMinutes,
  cronJobNextDueText,
  isCronJobOverdue,
} from '~/modtools/composables/useCronJobDue'

const NOW = new Date('2026-09-20T14:20:00Z')

function jobStartedMinutesAgo(minutes, intervalMinutes) {
  return {
    last_run_at: new Date(NOW.getTime() - minutes * 60000).toISOString(),
    interval_minutes: intervalMinutes,
  }
}

describe('useCronJobDue', () => {
  it('gives short jobs two minutes of grace and long jobs up to an hour', () => {
    expect(cronJobGraceMinutes(1)).toBe(2)
    expect(cronJobGraceMinutes(5)).toBe(5)
    expect(cronJobGraceMinutes(60)).toBe(60)
    expect(cronJobGraceMinutes(1440)).toBe(60)
  })

  it('does not flag a per-minute job that started a moment ago', () => {
    const job = jobStartedMinutesAgo(1.5, 1)
    expect(isCronJobOverdue(job, NOW)).toBe(false)
    expect(cronJobNextDueText(job, NOW)).toBe('due')
  })

  it('flags a per-minute job that has missed a whole cycle plus the grace', () => {
    expect(isCronJobOverdue(jobStartedMinutesAgo(3, 1), NOW)).toBe(false)
    expect(isCronJobOverdue(jobStartedMinutesAgo(3.1, 1), NOW)).toBe(true)
    expect(cronJobNextDueText(jobStartedMinutesAgo(4, 1), NOW)).toBe('overdue')
  })

  it('gives an hourly job a full extra hour before it is overdue', () => {
    expect(cronJobNextDueText(jobStartedMinutesAgo(61, 60), NOW)).toBe('due')
    expect(isCronJobOverdue(jobStartedMinutesAgo(119, 60), NOW)).toBe(false)
    expect(isCronJobOverdue(jobStartedMinutesAgo(121, 60), NOW)).toBe(true)
  })

  it('caps the grace for a daily job at an hour', () => {
    const job = jobStartedMinutesAgo(24 * 60 + 30, 1440)
    expect(cronJobDeadline(job).toISOString()).toBe(
      new Date(
        NOW.getTime() - 30 * 60000 - 24 * 3600000 + (1440 + 60) * 60000
      ).toISOString()
    )
    expect(cronJobNextDueText(job, NOW)).toBe('due')
    expect(
      isCronJobOverdue(jobStartedMinutesAgo(25 * 60 + 1, 1440), NOW)
    ).toBe(true)
  })

  it('shows minutes remaining for the last five minutes and defers beyond', () => {
    expect(cronJobNextDueText(jobStartedMinutesAgo(2, 5), NOW)).toBe('~3m')
    expect(cronJobNextDueText(jobStartedMinutesAgo(4.5, 5), NOW)).toBe('~1m')
    const format = (d) => `at ${d.toISOString()}`
    expect(cronJobNextDueText(jobStartedMinutesAgo(10, 60), NOW, format)).toBe(
      `at ${new Date(NOW.getTime() + 50 * 60000).toISOString()}`
    )
  })

  it('treats a job with no run or no interval as neither due nor overdue', () => {
    expect(
      isCronJobOverdue({ last_run_at: null, interval_minutes: 1 }, NOW)
    ).toBe(false)
    expect(
      cronJobNextDueText({ last_run_at: null, interval_minutes: 1 }, NOW)
    ).toBe('-')
    expect(cronJobDeadline({ last_run_at: NOW.toISOString() })).toBeNull()
  })
})
