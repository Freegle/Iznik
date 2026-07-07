# Selling clearance items on eBay - a plain English summary

## What we're thinking of doing

Organisations (an office move, a school refit, a business closing a building) would pay
Freegle to make their unwanted items disappear - pay as you go, a fee per item we actually
shift. The items stay at their premises; we do the work of finding takers, and anything we
don't shift remains theirs. We already have most of the machinery: structured bulk
"clearance" offers on Freegle (item list with names, quantities, condition, photos) and an
AI Helper that manages replies on the offerer's behalf.

The new idea is to also list the same items on eBay, cheap, for collection in person. That
reaches the great majority of people who are not Freegle members, and a nominal price (99p
to a few pounds) tends to filter for buyers who actually turn up. Because the item list is
already structured, creating the eBay listings is nearly free effort: one command drafts
them all, a human reviews prices and descriptions, one command publishes. The same AI
Helper then handles eBay buyer messages (questions, offers, arranging collection) the way
it already handles email and Freegle chat, with a human approving anything consequential.
Buyers pay through eBay up front and collect using a QR code, so there is no cash handling
and no postage.

Commercially, our revenue is the per-item disposal fee from the client, not the item price.
Selling a desk for £2 that would otherwise cost the client skip-hire money is a win all
round, and eBay's fees on a £2 sale are pennies (possibly less if we qualify for eBay's
charity seller rate).

## Are there technical issues?

Nothing that looks like a showstopper, and most of the pattern already runs today over
email. The genuinely open points are all checkable in about a week of sandbox testing
before we commit to building:

1. **Collection-only listings.** We need eBay to accept a listing with no postage option at
   all. Now confirmed: eBay's own API specification supports it directly (a "local pickup"
   setting with no postage services), and several live production systems create exactly
   these listings today. The postage-forcing rules eBay introduced for private sellers in
   2024-25 explicitly do not apply to business accounts, which ours would be. We still
   create one test listing on our actual account first, as belt and braces.
2. **Keeping stock straight across two channels.** The same item can be free on Freegle and
   cheap on eBay, so the two must never promise the same last unit. This is designed (one
   source of truth, a sync every few minutes, humans resolve the rare clash) - careful
   engineering, not research.
3. **eBay's messaging rules.** No contact details in messages before a sale; our outgoing
   replies are automatically checked before sending. Solved.
4. **Confirming handover.** eBay's pickup-code confirmation is a manual step in their
   interface, so someone confirms each collection (us remotely at first). Mildly annoying,
   not blocking.
5. **Buyer no-shows on cheap items.** Expected and handled (reminders, then cancellation),
   but a new eBay account with very few sales can be flagged if cancellations are a high
   proportion of them. So we start small, track it, and use the right cancellation reasons.

Verdict: basically soluble. The build is staged so the risky assumptions are tested with
throwaway scripts before any real code, and the whole thing is a few weeks of part-time
effort.

## What are the legal issues, and the options for solving them

1. **Business seller status.** Being paid to sell other people's goods is trading, so the
   eBay account must be a business account. Straightforward registration. Worth asking eBay
   for Charity UK whether a paid clearance service can use their Charity Direct Seller
   programme, which cuts selling fees to a token level.
2. **Consumer rights.** Business sellers must honour a 14-day change-of-mind right and
   goods must be "as described" (judged fairly for second-hand). At our price points the
   sensible policy is refund on request, no arguing. The real protection is honest
   descriptions - the human review step before publishing checks each description and photo
   against the item's actual condition.
3. **VAT and contract shape** - the one that genuinely needs an adviser. We considered
   having clients gift the goods to Freegle (which could zero-rate the sales as donated
   goods), but that is wrong for this service: we would then own the unsold residue and its
   disposal liability, and a "gift" paired with a per-item fee would not survive HMRC's
   substance-over-form scrutiny anyway. The recommended shape: items remain the client's
   property; our per-item fee is a normal VATable service; we keep the small eBay proceeds
   (title passes at the moment of each sale); anything unsold stays the client's. VAT on
   the sale proceeds is pennies at these prices - the adviser question is confirming the
   structure, not a big number. Same conversation should cover whether this activity needs
   to sit in a trading subsidiary, and whether the fee income changes our VAT registration
   position.
4. **Data protection.** We would hold eBay buyers' usernames and messages. We delete them
   90 days after a clearance closes, honour eBay's automated account-deletion notices, and
   add a line to the privacy policy.
5. **Liability at the client's site.** Buyers collect from the client's premises, so the
   clearance contract should state that the client controls access and insurance on their
   own site - the same position as any collection-in-person sale they might run themselves.
   Because we never take possession of or transport the goods, we also stay clear of waste
   carrier licensing questions.

None of this is unusual for what is, in effect, a small consignment operation without the
shop. Items 1, 2, 4 and 5 are process and paperwork; item 3 is one conversation with a VAT
adviser before the first paid clearance.
