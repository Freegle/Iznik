# V1→V2 Migration Parity Audit — Complete Findings

## Audit Coverage
- **Tier 1**: Message mod, chat mod, memberships, user, group (8 agents)
- **Tier 2**: Message create/edit/delete, chat rooms, newsfeed, notifications, address, isochrone, donations, microvolunteering, noticeboard, tryst, team, merge (10 agents)
- **Background**: Laravel scheduled jobs (11 commands), incoming email processing
- **Total agents dispatched**: ~25

## Fixed This Session (not committed)

| # | Handler | Fix | Lines |
|---|---------|-----|-------|
| A | Session volunteering | LEFT JOIN (was INNER) | session.go |
| B | GET /memberships | displayname + settings | membership_mod.go |
| C | GET /message | groupsnear from unblurred coords | message.go |
| D | POST /memberships | Logging for all actions | membership_mod.go |
| E | POST /chatmessages | Logging for approve/reject | chatmessage_moderation.go |
| F | PATCH /admin | Edit tracking (editedat/editedby) | admin.go |
| G | POST/PATCH/DELETE /modconfig | Logging + bulkops copy + protected | modconfig.go |
| H | POST /message mod actions | Push notifications | message_mod.go |
| I | PATCH /group | Audit logging + geometry validation | group_write.go |
| J | BackToPending | Hold + log + push notify | message_mod.go |
| K | GET /logs | logtype=user support | logs.go |
| L | PUT /message submit | Posting status check (was hardcoded Approved) | message_mod.go |
| M | PUT /message submit | Ban check | message_mod.go |
| N | PUT /message submit | Push notify mods on Pending | message_mod.go |
| O | CopyAdmins | Push notification (was TODO) | CopyAdminsCommand.php |

## Remaining Genuine Bugs (need fixing)

### HIGH: Functional bugs
| # | Handler | Gap | Notes |
|---|---------|-----|-------|
| 1 | POST /user Merge | Missing 25+ table merges, no transaction, missing role merge | Complex — needs careful implementation |
| 2 | Merge POST Accept | Doesn't actually merge users | Only updates timestamp |
| 3 | PATCH /message | Missing rejected→pending resubmission | User edits rejected message but it stays rejected |
| 4 | PATCH /message | Missing mod notification on edit review | Mods not alerted to pending edits |
| 5 | PATCH /message | Missing log entry for edits | No audit trail |
| 6 | DELETE /message (user) | Missing per-group tracking | Cross-post deletion tracking broken |
| 7 | Chat rooms PUT | Missing updateRoster param support | Can't unblock chats after creation |
| 8 | Chat rooms POST | Missing ACTION_ALLSEEN | Can't bulk-mark all seen |
| 9 | PATCH /giftaid | Can't set fields to empty/NULL | `!= ""` check prevents clearing |
| 10 | Microvolunteering GET | Missing moderator list path | Mod list endpoint absent |
| 11 | Microvolunteering PATCH | Missing modFeedback endpoint | Entirely absent |
| 12 | Isochrone | Default minutes 15→30 mismatch | User experience regression |

### MEDIUM: Missing side effects
| # | Handler | Gap |
|---|---------|-----|
| 13 | PATCH /user | Missing ourPostingStatus/ourEmailFrequency log |
| 14 | DELETE /user | Missing log entry + membership cleanup |
| 15 | Notifications Seen/AllSeen | Missing push notification queuing |
| 16 | Newsfeed mod actions | Missing logging for hide/unhide/attach |
| 17 | Tryst | Missing calendarLink computed field |
| 18 | Team | Missing member list sorting by displayname |

### LOW: Minor/cosmetic
| # | Handler | Gap |
|---|---------|-----|
| 19 | GET /logs | Missing outcome text trimming |
| 20 | Noticeboard | Wandsworth special-case (marked TODO remove in V1) |
| 21 | Address | Missing PAF lookup by postcode |
| 22 | Incoming email | messages.lastroute field not populated |

## N/A: Intentional architectural differences
- V2 doesn't construct email envelopes/Message-ID (web-only posting)
- V2 doesn't call submit() (email delivery handled by Laravel batch)
- V2 returns IDs instead of full objects (reactive store pattern)
- V2 uses JWT instead of PHP sessions
- V2 uses background_tasks table instead of Pheanstalk
- Search indexing handled by PHP cron `message_unindexed.php` (every 30 min)
- Spatial indexing handled by PHP cron `message_spatial.php` (every 5 min)
- URL expansion not needed for web-posted messages
- Cache clearing not needed (reactive frontend stores)
