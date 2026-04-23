# Multi-Group Messages: Stats Query Audit

## Summary

With multi-group messages, a single `messages.id` can have multiple `messages_groups` rows. Any query doing `COUNT(*)` on a join of these tables without `DISTINCT` will double-count.

## Fixed (Go API)

| File | Line | Query | Fix |
|------|------|-------|-----|
| `dashboard/dashboard.go` | 119 | `COUNT(*)` on messages+messages_groups for "newmessages" | Changed to `COUNT(DISTINCT messages.id)` |
| `dashboard/dashboard.go` | 193 | `COUNT(*)` on messages+messages_groups for "newmessages" | Changed to `COUNT(DISTINCT messages.id)` |

## Safe — Work Queue Counts (Per-Group is Correct)

These count items needing moderation per group. A message pending in two groups _should_ appear in both counts.

| File | Line | Query |
|------|------|-------|
| `session/session.go` | 818, 826, 837, 852 | Pending/spam counts scoped to groupid IN |
| `PushNotificationService.php` | 190, 206 | Pending/spam counts for push notifications |

## Safe — Already Using DISTINCT

| File | Line |
|------|------|
| `iznik-server/include/misc/Stats.php` | 72, 84, 138, 152, 166 |
| `iznik-server/include/user/User.php` | 1701 |
| `iznik-server/include/group/Group.php` | 487, 494 |

## V1 PHP — Not Fixed (Deprecated)

These queries in `iznik-server/` have the same double-count risk but V1 is being retired:

| File | Line | Query |
|------|------|-------|
| `include/dashboard/Dashboard.php` | 235 | `COUNT(*)` on messages+messages_groups |
| `include/user/Engage.php` | 84 | `COUNT(*)` for user engagement |
| `include/user/User.php` | 1644, 1737 | `COUNT(*)` with GROUP BY |
| `include/misc/Stats.php` | 183, 200 | `COUNT(*)` for source/type stats |
| `include/group/Group.php` | 419 | `COUNT(*)` for collection counts |

These will be retired with V1. No fix needed.
