// Pure helpers for the "audience-based reach" boundary (task #26): given the ripple-schedule's
// tick curve (drive_min, cumulative_users pairs, ascending by drive_min) and a target audience
// size (nstar), find the drive-time at which cumulative_users first reaches nstar, linearly
// interpolating between the bracketing ticks (and from the implicit (0 min, 0 users) origin for
// a crossing inside the first tick).

// ticks: [{ drive_min: number, cumulative_users: number }, ...] ascending by drive_min.
// Returns the interpolated drive-time (minutes) at first crossing, or:
//  - 0 if nstar <= 0
//  - the last tick's drive_min if the curve never reaches nstar (audience smaller than target
//    even at max reach — the caller clamps this to the [10,30] floor/ceiling separately)
//  - null if ticks is empty/missing
export function driveMinForAudience(ticks, nstar) {
  if (!ticks || !ticks.length) return null
  if (!(nstar > 0)) return 0
  let prev = { drive_min: 0, cumulative_users: 0 }
  for (const t of ticks) {
    if (t.cumulative_users >= nstar) {
      const span = t.cumulative_users - prev.cumulative_users
      if (span <= 0) return t.drive_min
      const frac = (nstar - prev.cumulative_users) / span
      return prev.drive_min + frac * (t.drive_min - prev.drive_min)
    }
    prev = t
  }
  return prev.drive_min
}

// Clamp to the task's fixed [10,30]-minute band. Returns null through unchanged (caller decides
// whether null means "no data" vs "hide the boundary").
export function clampAudienceMinutes(mins, min = 10, max = 30) {
  if (mins === null || mins === undefined || Number.isNaN(mins)) return null
  return Math.min(max, Math.max(min, mins))
}
