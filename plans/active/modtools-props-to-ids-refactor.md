# ModTools Props-to-IDs Refactor

## Principle
Props should be IDs (numbers), not objects. Components fetch their own data from stores.
All APIs go via stores. Remove all `user || member` fallback patterns.

## Key stores
- `useUserStore` — keyed by userid, has `byId(userid)` and `fetch(userid)`
- `useMessageStore` — keyed by messageid, has `byId(messageid)` and `fetch(messageid)`
- `useMemberStore` — keyed by membershipid, has `get(membershipid)`
- Member objects: `.id` = membershipid, `.userid` = user ID

## Pattern
```javascript
const props = defineProps({
  userid: { type: Number, required: true }
})
const userStore = useUserStore()
const user = computed(() => userStore.byId(props.userid))
watch(() => props.userid, (uid) => {
  if (uid && !userStore.byId(uid)) userStore.fetch(uid)
}, { immediate: true })
```

## Completed (prior sessions)

| # | Component | Change | Status |
|---|-----------|--------|--------|
| C1 | ModCommentAddModal.vue | `user: Object` → `userid: Number` | ✅ |
| C2 | ModSpammerReport.vue | `user: Object` → `userid: Number` | ✅ |
| C3 | ModMemberActions.vue (caller) | `:user` → `:userid` for AddModal & SpammerReport | ✅ |
| C4 | ModMemberButtons.vue (caller) | `:user` → `:userid` for AddModal | ✅ |
| C5 | ModMemberButton.vue (caller) | `:user` → `:userid` for SpammerReport | ✅ |
| C6 | ModMessage.vue (caller) | `:user` → `:userid` for SpammerReport | ✅ |
| C7 | ModSupportUser.vue (caller) | `:user` → `:userid` for both | ✅ |
| C8 | ModChatFooter.vue (caller) | `:user` → `:userid` for both | ✅ |
| C9 | ModComment.vue | `user: Object` → `userid: Number` | ✅ |
| C10 | ModCommentEditModal.vue | `user: Object` → `userid: Number` | ✅ |
| C11 | ModChatReviewUser.vue | `user: Object` → `userid: Number` | ✅ |
| C12 | ModModeration.vue | removed `user: Object`, kept `userid: Number` | ✅ |
| C13 | ModComments.vue (caller) | `:user` → `:userid` | ✅ |
| C14 | ModChatReview.vue (caller) | `:user` → `:userid` for ModChatReviewUser | ✅ |
| C15 | ModMember.vue (caller) | removed `:user` from ModModeration | ✅ |
| C16 | ModMessageUserInfo.vue (caller) | `:user` → `:userid` for ModModeration | ✅ |
| C17 | ModStdMessageModal.vue | accepts messageid/membershipid, fetches from stores | ✅ |
| C18 | ModMemberButton.vue | passes membershipid to ModStdMessageModal | ✅ |
| C19 | ModMessageButton.vue | passes messageid to ModStdMessageModal | ✅ |
| C20 | ModMemberSummary.vue | `member: Object` → `userid: Number` | ✅ |
| C21 | ModMemberEngagement.vue | `member: Object` → `userid: Number` | ✅ |
| C22 | ModMemberLogins.vue | `member: Object` → `userid: Number` | ✅ |
| C23 | ModBouncing.vue | `user: Object` → `userid: Number` | ✅ |
| C24 | ModDeletedOrForgotten.vue | `user: Object` → `userid: Number` | ✅ |
| C25 | ModSpammer.vue | `user: Object` → `userid: Number` | ✅ |
| C26 | ModPostingHistoryModal.vue | `user: Object` → `userid: Number` | ✅ |
| C27 | ModMember.vue heldby | V2 heldby (numeric ID) handled via computed | ✅ |
| C28 | ModMemberReview.vue | member.id bugs fixed, passes userid to children | ✅ |

## Remaining — user: Object → userid: Number

| # | Component | Prop | Fields used | Callers | Status |
|---|-----------|------|------------|---------|--------|
| U1 | ModLogUser.vue | `user: Object` | displayname, email, systemrole, id | ModLog.vue (20+ uses) | ✅ |
| U2 | ModMicrovolunteeringDetailsButton.vue | `user: Object` | passes to Modal | microvolunteering page | ✅ |
| U3 | ModMicrovolunteeringModal.vue | `user: Object` | displayname | ModMicrovolunteeringDetailsButton | ✅ |
| U4 | ModTeamMember.vue | `member: Object` | displayname, id, profile | team page | ✅ |

## Remaining — member/membership: Object → IDs

| # | Component | Prop | Fields used | Status |
|---|-----------|------|------------|--------|
| M1 | ModMember.vue | `member: Object` | userid, groupid, added, bandate, heldby, role, emailfrequency + many more | ⬜ |
| M2 | ModMemberReview.vue | `member: Object` | userid, joined, added, fullname, spammer, bandate, bannedby, memberships[] | ⬜ |
| M3 | ModMemberButtons.vue | `member: Object` | userid, groupid, bandate, spammer, heldby, collection, memberships[] | ⬜ |
| M4 | ModMemberReviewActions.vue | `membership: Object` | id, added, heldby, reviewreason, reviewrequestedat, membershipid | ⬜ |
| M5 | ModSupportMembership.vue | `membership: Object` | role, nameshort, added, id, emailfrequency, ourpostingstatus, membershipid | ⬜ |
| M6 | ModRelatedMember.vue | `member: Object` | id, relatedto, lastaccess, messagehistory, memberships, email, emails, displayname | ⬜ |

## Remaining — message: Object → messageid: Number

| # | Component | Prop | Fields used | Status |
|---|-----------|------|------------|--------|
| G1 | ModMessage.vue | `message: Object` | id + 20+ fields, uses messageStore | ⬜ |
| G2 | ModMessageButton.vue | `message: Object` | id, subject, groups, heldby | ⬜ |
| G3 | ModMessageButtons.vue | `message: Object` | id, heldby, type, outcomes, groups | ⬜ |
| G4 | ModChatReview.vue | `message: Object` | id, fromuser, touser, group, held, chatid, date + many | ⬜ |
| G5 | ModMessageRelated.vue | `message: Object` | id, subject, arrival | ⬜ |
| G6 | ModMessageDuplicate.vue | `message: Object` | id, subject, arrival, outcome, collection, groups | ⬜ |
| G7 | ModMessageCrosspost.vue | `message: Object` | id, subject, arrival, collection, outcome, groupid | ⬜ |
| G8 | ModMessageWorry.vue | `message: Object` | id, worry[] | ⬜ |
| G9 | ModMessageMicroVolunteering.vue | `message: Object` + `microvolunteering: Object` | message fields + microvolunteering | ⬜ |

## Remaining — other Object props

| # | Component | Prop | Fields used | Status |
|---|-----------|------|------------|--------|
| O1 | ModCommentUser.vue | `comment: Object` → `commentid: Number` | comment store | ✅ |
| O2 | ModSupportChat.vue | `chat: Object` → `chatid: Number` | chat store | ✅ |
| O3 | ModMemberButtons.vue | `modconfig: Object` → `modconfigid: Number` | modconfig store | ✅ |
| O4 | ModMessageButtons.vue | `modconfig: Object` → `modconfigid: Number` | modconfig store | ✅ |
| O5 | ModStdMessageModal.vue | `stdmsg: Object` → `stdmsgid: Number` + `stdmsgaction: String` | stdmsg store | ✅ |
| O6 | ModSettingsStandardMessageButton.vue | `stdmsg: Object` → `stdmsgid: Number` | stdmsg store | ✅ |
| O7 | ModLog.vue | `log: Object` → `logid: Number` | logs store | ✅ |
| O8 | ModLogGroup.vue | `log: Object` → `logid: Number` | logs store | ✅ |
| O9 | ModLogStdMsg.vue | `log: Object` → `logid: Number` | logs store | ✅ |
| O10 | ModLogMessage.vue | `log: Object` → `logid: Number` | logs store | ✅ |
| O11 | ModPhoto.vue | `attachment/message: Object` → `messageid/attachmentid: Number` | message store | ✅ |
| O12 | ModPhotoModal.vue | `attachment/message: Object` → `messageid/attachmentid: Number` | message store | ✅ |
| O13 | ModVolunteerOpportunity.vue | `volunteering: Object` → `volunteeringid: Number` | volunteering store | ✅ |
| O14 | ModCommunityEvent.vue | `event: Object` → `eventid: Number` | community event store | ✅ |
| O15 | ModGiftAid.vue | `giftaid: Object` → `giftaidid: Number` | new giftaid store | ✅ |
| O16 | ModStoryReview.vue | `story: Object` → `storyid: Number` | story store | ✅ |
| O17 | ModMemberRating.vue | `rating: Object` → `ratingid: Number` | member store + Go API | ✅ |
| O18 | ModGroupMapLocation.vue | `location: Object` | ⏸️ Parked for discussion |
| O19 | ModSpamKeywordBadge.vue | `spamKeyword: Object` → `spamKeywordId: Number` | systemconfig store | ✅ |
| O20 | ModWorryWordBadge.vue | `worryword: Object` → `worrywordid: Number` | systemconfig store | ✅ |
| O21 | ModChangedMapping.vue | `changed: Object` + `highlighted: Object` | ⏸️ Parked for discussion |
| O22 | ModAlertHistory.vue | `alert: Object` → `alertid: Number` | alert store | ✅ |
| O23 | ModSettingShortlink.vue | `shortlink: Object` → `shortlinkid: Number` | shortlink store | ✅ |
| O24 | ModSettingsGroupFacebook.vue | Removed (retired) | ✅ |
| O25 | ModSystemLogEntry.vue | `log: Object` → `logId: Number` | systemlogs store | ✅ |
| O26 | ModSystemLogTreeNode.vue | `node: Object` → `nodeKey: String` | systemlogs store | ✅ |
| O27 | ModDashboardDiscourseTopic.vue | `topic: Object` | ✅ No change (display only) |
| O28 | ModIncomingEmailDetail.vue | `entry: Object` | ✅ No change (display-only modal) |
| O29 | ModMessageSummary.vue | `matchedon: Object` → derived from message store | ✅ |
| O30 | DiffPart.vue | `part: Object` | ✅ No change (display only) |
| O31 | ModMemberActions.vue | `spam: Object` → `spammerid: Number` | spammer store | ✅ |
| O32 | ModComment.vue | `comment: Object` → `commentid: Number` | user comments | ✅ |

## Notes
- member/membership items (M-series) are complex — they contain group-specific fields. Need to determine if member store has all needed fields or if we need userid+groupid composite lookup.
- message items (G-series): message store exists and is used by some components already.
