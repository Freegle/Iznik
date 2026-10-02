# Rippling: safeguarding posts, home Back to pending, and delete after a freeze

Trigger: Discourse 9808/835 (Saira, Birmingham). Post 122141630, a Wanted from a domestic
violence housing provider in Selly Oak, rippled to six groups and emailed 27 members; it
stayed live on those six groups after it was deleted on its home group.

## What happened (prod, 2026-10-01/02)

| Time | Event |
|---|---|
| 16:31:19 | A Birmingham moderator approved the home copy. Saira's hold landed in the same second, so it did not block the approval (the hold/approve timing window). |
| 16:32:31 | Reach created, tick 1: 22.8 min drive budget, 2,800 freeglers, six groups (Solihull, Bromsgrove, Halesowen, West Bromwich, Rowley Regis, Smethwick). Furthest group centre about 10.7 mi by road. |
| 16:33 | 27 members mailed by the reach mailer. |
| 16:37:37 | Saira's Back to pending pulled all six copies to Pending, set `needs_moderator`, froze the reach (`status='held'`). Worked as designed. |
| 17:24, 05:00 | Moderators of the receiving groups approved their own copies by hand. They saw only "A moderator moved this post back to pending for review." |
| 07:32 | Saira deleted the home copy. The six copies stayed live. |
| 08:40 | Copies removed by hand on prod (deleted=1, Message/Deleted logs, spatial and reach rows dropped, message soft-deleted). |

## 1. Delete on the home group must retract copies after a freeze (bug)

The docs (`docs/moderators/rippling-out.md`, "Rejecting or removing a post on its home
community") say removing a post at home pulls every rippled copy within a minute.
`ExpandService::retractCopiesOrphanedByOriginRemoval` does this but skips
`rippling_reach.status = 'held'`, and nothing ever clears `held`. So once a post has been
sent back to pending, a later delete at home never cascades.

- Retract when the post has **no undeleted home row at all** (`rippled_in = 0`), whatever the
  reach status. Keep skipping `held` when the home row exists and is Pending: those copies are
  left for per-group moderation.
- Same consideration for `removeStaleAndRetract`, which also skips `held`.
- Test: seed the reach with `status='held'` (as Go's `FreezeReachIfOriginPending` really
  does); `test_back_to_pending_on_home_group_retracts_rippled_copies` seeds `expanding`, so it
  never covers the frozen case.
- Also reject on the home group, and the ripple-join membership clean-up.

## 2. Back to pending by a home-group moderator locks the other copies

Decided 2026-10-02 (Edward): narrower than making every copy depend on the home copy.

- When a moderator of the **home** group does Back to pending, the rippled copies cannot be
  approved by the receiving groups until the home copy is approved again. Their moderators are
  told why (the home group is reviewing it), not just that it was sent back.
- When the copies are pulled back by a members' report quorum, or by a receiving group's own
  moderator, each group stays independent as now (PR #946 decision: "worth a look everywhere,
  not definitely bad").
- Needs a durable marker of who pulled it back (e.g. a column or reason on the copy) so
  `handleApprove` can refuse with a clear message, and ModTools can show it.
- On home re-approval, the locked copies return to normal per-group moderation (the reach
  stays frozen, no re-notify; see the existing freeze behaviour).
- Update `docs/moderators/rippling-out.md` and `moderating-posts.md`.

## 3. Safeguarding concern keywords

No concern keyword currently covers refuges or domestic abuse; the post's content check was
clean. About 16 posts in 90 days mention these, most of them disclosing where a survivor is
or is going ("fleeing dv to Cornwall", "moved from woman refuge", "hosting a friend who
escaped domestic abuse").

- New `concern_keywords.category = 'safeguarding'` (enum migration), action `flag`, literal
  matching: refuge, domestic violence, domestic abuse, DV, fleeing, escaped/escaping abuse,
  women's aid, safe house. Exclude "refugee(s)". Expect some harmless matches (offers to a
  refuge).
- Guidance on the ModTools warning card (`ModMessageWorry.vue`): this post may reveal where
  someone escaping abuse lives; check the location; suggest a public place or just the town,
  and not naming the service.
- Check `ContentEmbeddingService::isInnocentContext` does not suppress the new category.
- Decided 2026-10-02 (Edward): once a home-group moderator approves a flagged post, it is
  treated as normal and ripples out as usual. The flag's job is to get a moderator to check
  the location before approval, not to change rippling.
- Decided 2026-10-02 (Edward): a safeguarding flag routes the post to Pending, as other
  concern keywords do. No new code for that: member posts are no longer Approved on arrival
  (`IncomingMailService`), so every post waits in Pending for the content check, which only
  promotes a clean post. A flag keeps it Pending, and nothing ripples until a moderator
  approves the home copy.
- Interim, done on prod 2026-10-02: 11 global `review` / `flag` / literal keywords, ids
  3838-3868 (refuge, refuges, domestic violence, domestic abuse, dv, fleeing, escaping abuse,
  escaped abuse, women's aid, womens aid, safe house), plus hostel and hostels (3871, 3874), and shelter and shelters (3877, 3880, with an exclusion for animal/bus/garden-type shelters; noisy, about 4 holds a week, kept by Edward's choice). Literal matching is word-bounded, so
  "refugee" does not match. The safeguarding PR's migration should move these rows to the new
  category rather than add duplicates.

## 4. Location blurring

The map pin is road-blurred (`BLUR_USER = 400` m; at least 100 m crow / 200 m road from the
true point) and the title shows only area and postcode district. Adequate for most posts; for
a refuge a few hundred metres can narrow it to a street or two. Covered in practice by 3 (the
moderator asks the poster to change location). No change proposed here unless 3 shows it is
not enough.

## 5. Hold/approve timing window

A hold and an approval by two moderators in the same second both succeeded. Worth checking
whether `handleApprove` should re-check `heldby` inside the update (`WHERE heldby IS NULL OR
heldby = me`). Low priority; noted here because it is how this post first went live.
