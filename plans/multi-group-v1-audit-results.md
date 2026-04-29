# V1 PHP Audit Confirmation for Multi-Group Messages

## Go V2 API Per-Group Operation Verification

| V1 Issue | V2 Handler | Status | Notes |
|----------|-----------|--------|-------|
| `reject()` updates ALL groups | `handleReject` (message.go:1463) | CORRECT | Takes optional groupid, scoped WHERE clause |
| `sendForReview()` updates ALL groups | Not needed | N/A | Pending messages auto-notify per-group mods |
| `autoapprove()` deletes all groups | `handleJoinAndPost` (message.go:1972) | CORRECT | Checks `ourPostingStatus` per user per groupid |
| `move()` deletes all, inserts one | `handleMove` (message.go:3313) | CORRECT | Transaction-based DELETE + INSERT, checks mod perms on both groups |
| `spam()` is global delete | `handleSpam` (message.go:1582) | CORRECT | Soft-deletes only specified group's row; only marks message deleted if no non-deleted groups remain |
| `ModBot` uses first group for rules | Not implemented | N/A | No AutoMod/ModBot logic in Go codebase yet |

## Summary

All migrated operations correctly handle per-group behavior:
- **reject**: Scoped to groupid parameter
- **autoapprove**: Checks posting status per group
- **move**: Atomic DELETE+INSERT between groups with dual permission check
- **spam**: Per-group soft-delete with global fallback only when all groups deleted

**ModBot** has not been migrated to Go yet. When it is, it will need to apply rules per group rather than using `groups[0]`.
