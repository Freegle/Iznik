# Rippling: reject doesn't withdraw already-rippled copies (Wyre Forest / Worcester duplicate)

Discourse "Rippling Out" #344 (Wendy_B, Worcester) + #350 (Mod-John, Wyre Forest).

## What happened (from prod data)

Member 44577909 ("Catchems End DY12", Bewdley) is a member of **both** Worcester and Wyre
Forest, and on 31 May posted three near-identical items that have auto-reposted for a month:

- `120479150` "OFFER: Settee (Catchems End DY12)" — **origin group = Wyre Forest** (`rippled_in=0`)
- `120479252` "OFFER: Sofa" — the surviving copy
- `120479294` `OFFER: " seater settee` — **origin group = Worcester** (`rippled_in=0`)

Both `120479150` and `120479294` had rippled (auto-approved) to ~14 neighbouring groups
(Worcester, Birmingham, Ludlow, Malvern, Bromsgrove, Bridgnorth, Stourbridge, etc.).

On 30 Jun 07:31–07:36 Mod-John (user 43899574) held-then-rejected the two dupes **on Wyre
Forest** via the duplicate button (log subtype `Rejected`, reason "Message duplicated").

Result in `messages_groups` afterwards:

- `120479150`: Wyre Forest = Rejected; **all 14 rippled copies still Approved** (incl. Worcester).
- `120479294`: Wyre Forest (a rippled-in copy) = Rejected; **Worcester (its origin) still Approved**.

So Worcester kept a live duplicate → a member reported it to Wendy, and Worcester's
multi-group view showed it "held by the Wyre Forest Mod" (the rippled-in copy's state
leaking into the aggregated display).

## Why reject didn't clear it

Live reject = Go apiv2 `handleReject` (`iznik-server-go/message/message.go`). By design:

1. **Group-scoped + Pending-only.** It flips `Pending → Rejected` only for copies that are
   Pending *in the acting mod's own groups*
   (`WHERE msgid=? AND groupid IN (mod pending groups) AND collection='Pending'`).
   Already-**Approved** rippled copies are immune — reject is a no-op on them.
2. **Origin-aware secondary reject.** It computes `MessageOriginGroup` (earliest arrival).
   A reject on a non-origin group is a silent `secondary_reject` that `ClipReachForRejectedGroup`
   (trims that area from the ripple reach) rather than withdrawing the post.

This is the deliberate rippling design (issues #6, #9, #9815): a *secondary* group's mod must
not be able to withdraw someone else's origin post. The trade-off is the inverse case here —
rejecting a duplicate on its **origin** does not cascade to the approved rippled copies.

NB this is a change from classic Freegle: V1 `Message::reject` was message-wide
(`UPDATE messages_groups SET collection='Rejected' WHERE msgid=?`, no group filter), so a
reject there withdrew the post from every group. That path is retired.

## The gap / fix candidate

There is no "origin reject/delete cascades to withdraw the rippled copies" rule.
`MessageOriginGroup` is already computed inside `handleReject`, so the natural home for a fix
is: when the acting group **is** the origin, also act on the `rippled_in=1` copies.

**Open product decision (Edward's call):**
- Should origin-reject/-delete *withdraw* already-**approved** rippled copies, or only *clip
  their reach* (leaving live copies that already have replies alone)? Withdrawing an approved
  copy that has active chats could be disruptive; reach-clip is gentler but leaves the item
  visible to people already looking.
- Does this apply to **Reject** (which normally means "back to poster to edit") or only to the
  **duplicate/Delete** action? Reject-to-edit cascading a withdrawal everywhere may be wrong;
  a duplicate/spam removal cascading is what mods expect.

Separately (already acknowledged in #338): the multi-group moderation view mislabels which
group is "holding"/"rejected" a rippled post — worth fixing alongside so mods can see the
true per-group state.
