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

---

## Status (2026-10-28)

Nothing is built. The detailed design and the implementation plan were removed from this folder on 2026-10-28 and are in git history at `3e65b6af25` (`plans/2026-07-03-ebay-clearance-channel-design.md`, `plans/2026-07-03-ebay-clearance-channel-implementation.md`). Regenerate the build plan from this summary and the Phase 0 findings below when the work is picked up.

## Phase 0 findings (merged here 2026-10-28)

Running findings doc for the Phase 0 feasibility spike
(`plans/2026-07-03-ebay-clearance-channel-implementation.md`). Seeded 2026-07-03 with
desk-research results; sandbox checkboxes remain to be run.

### Go/no-go summary (updated as checks complete)

| Check | Status | Finding |
|---|---|---|
| 0.2 Collection-only (no postage) listing | RESEARCH-CONFIRMED, sandbox pending | Mechanism confirmed two independent ways (below); residual risk is account classification only |
| 0.1 OAuth | pending | |
| 0.3 External image URLs | pending | |
| 0.4 Quantity 0 = out-of-stock vs ended | pending | |
| 0.5 Best Offer | pending | |
| 0.6 Messaging + REST Message API GA status | pending | |
| 0.7 Order flow / pickup-code mechanics | pending | remote web confirmation by ops unverified |
| 0.8 Minimum price + fee schedule | pending | believed £0.99 min; charity rate unverified |
| 0.9 MAD challenge + signature verification | pending | |
| 0.11 Seller-standards / defect mechanics | pending | |
| 0.12 Finances API shapes | pending | |

### 0.2 Collection-only listing - desk research (2026-07-03, multi-agent, adversarially cross-checked)

**Verdict: collection-in-person-only, zero-postage eBay UK listings are an API capability
today, for business-seller accounts.** Confirmed via eBay's own spec text (mirrored on
GitHub, since developer.ebay.com and ebay.co.uk time out on automated fetch) and multiple
unrelated live production codebases. The empirical sandbox + real-account checks are still
required (account-side defaults can surprise), but a failure there means an
account-classification problem to escalate with eBay, not a missing capability.

#### Primary mechanism (REST, matches the design):
`createFulfillmentPolicy` with `localPickup: true` and NO `shippingOptions` array:

```json
{
  "name": "Collection only",
  "marketplaceId": "EBAY_GB",
  "categoryTypes": [ { "name": "ALL_EXCLUDING_MOTORS_VEHICLES" } ],
  "localPickup": true
}
```

Evidence (eBay's own Account API OAS3 spec, vendored verbatim at
https://raw.githubusercontent.com/hendt/ebay-api/master/src/types/restful/specs/sell_account_v1_oas3.ts):
- `localPickup`: "This field should be included and set to true if local pickup is one of
  the fulfillment options available to the buyer... Default: false."
- `handlingTime`: "conditionally required when the seller is offering one or more domestic
  or international shipping options, but it is not applicable when the item is only
  available through local pickup (\"localPickup\": \"true\")".
- `shippingOptions`: "conditionally required if the seller is offering one or more domestic
  and/or international shipping service options" - i.e. omissible.
- Every FulfillmentPolicyRequest field is optional in the schema (also confirmed in the
  generated PHP SDK: https://raw.githubusercontent.com/zVPS/ebay-sell-account-php-client/main/docs/Model/FulfillmentPolicyRequest.md).
- **Do NOT use `pickupDropOff`**: same spec text - "Click and Collect", "available to only
  large retail merchants" with physical stores. Wrong feature.
- Offer path: `publishOffer` requires categoryId, listingDescription, listingDuration,
  listingPolicies (all three policy ids), merchantLocationKey
  (https://raw.githubusercontent.com/hendt/ebay-api/master/src/types/restful/specs/sell_inventory_v1_oas3.ts).

#### Fallback mechanism (Trading XML, evidenced by live production integrations):
`AddFixedPriceItem`/`AddItem`, SiteID 3, a SINGLE ShippingServiceOptions block:

```xml
<ShippingDetails>
  <ShippingType>Flat</ShippingType>
  <ShippingServiceOptions>
    <ShippingService>UK_CollectInPerson</ShippingService>
    <ShippingServiceCost>0.0</ShippingServiceCost>
    <ShippingServicePriority>1</ShippingServicePriority>
  </ShippingServiceOptions>
</ShippingDetails>
```

Evidence:
- Enum value `UK_CollectInPerson` (+ `UK_CollectInPersonInternational`) in WSDL-derived SDK
  enums: https://raw.githubusercontent.com/codyfauser/ebay/3bc46fa29480e0e47fa74af3739124c0b5bd1c33/lib/ebay/types/shipping_service_code.rb
  and https://metacpan.org/pod/eBay::API::XML::DataType::Enum::ShippingServiceCodeType
- Live UK listing tool mapping "collect" -> `UK_CollectInPerson`:
  https://github.com/Trupson2/ebay-hub-uk/blob/master/modules/ebay_api.py (master branch)
- Production Ruby integration emitting the single-block XML:
  https://github.com/CD2/commercity-public/blob/8b6c75a2b928ae049eeb01bad10fd7434288c60e/lib/ebay/ebay_product_push.rb
- Live AddItem POST to api.ebay.com/ws/api.dll SiteID 3 combining Royal Mail 2nd class +
  UK_CollectInPerson: https://github.com/Adam-Developing/Amazon-To-Ebay-Bot/blob/main/ebay.py
- **Do NOT spike the bare `LocalPickup` enum on the UK site**: it exists in eBay's WSDL/docs
  but no verified UK-site (SiteID 3) usage was found anywhere - testing it instead of
  `UK_CollectInPerson` risks a false negative.

#### C2C postage mandates do not apply to business sellers:
- "eBay Local" (UK, ~Nov 2024) ADDS a collection option to shipped C2C listings (not the
  reverse) and is scoped to private sellers:
  https://channelx.world/2024/11/how-ebay-local-works-in-the-uk/
- "Simple Delivery" (mandatory from 15 Apr 2025 for eligible private-seller listings)
  explicitly exempts collection-only listings AND business sellers:
  https://channelx.world/2025/03/ebay-simple-delivery-coming-to-all-c2c-sellers/ ,
  https://www.valueaddedresource.net/ebay-uk-makes-opting-out-of-simple-delivery-harder/
- UK seller UI still offers "Collection only" as a choice (default is postage+collection):
  https://www.ebay.co.uk/help/selling/posting-items/setting-postage-options/local-collection?id=4181
- Residual unknowns: whether a **Charity Direct Seller** account is classified as business
  for these purposes (assumed yes, unverified), and API-created vs UI-created listing
  behaviour on the real account. Hence the non-skippable Task 2.3 real-account listing.

#### Payment/handover (re-confirmed):
- Cash on collection withdrawn UK-wide 1 Oct 2024 except cars/motorcycles/vehicles; buyers
  pay via eBay checkout; QR + 6-digit code confirms handover and releases funds:
  https://www.valueaddedresource.net/ebay-uk-drops-cash-on-collection/ ,
  https://community.ebay.co.uk/t5/Announcements/Changes-to-payment-methods-for-listings-with-collection-in/ba-p/7665204
- Category exclusions for collection-only: Authenticity Guarantee items and
  age-verification categories (irrelevant to office clearance stock):
  https://www.ebay.co.uk/help/selling/posting-items/setting-postage-options/local-collection?id=4181

