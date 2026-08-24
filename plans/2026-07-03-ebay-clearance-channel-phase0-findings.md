# eBay clearance channel - Phase 0 findings

Running findings doc for the Phase 0 feasibility spike
(`plans/2026-07-03-ebay-clearance-channel-implementation.md`). Seeded 2026-07-03 with
desk-research results; sandbox checkboxes remain to be run.

## Go/no-go summary (updated as checks complete)

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

## 0.2 Collection-only listing - desk research (2026-07-03, multi-agent, adversarially cross-checked)

**Verdict: collection-in-person-only, zero-postage eBay UK listings are an API capability
today, for business-seller accounts.** Confirmed via eBay's own spec text (mirrored on
GitHub, since developer.ebay.com and ebay.co.uk time out on automated fetch) and multiple
unrelated live production codebases. The empirical sandbox + real-account checks are still
required (account-side defaults can surprise), but a failure there means an
account-classification problem to escalate with eBay, not a missing capability.

### Primary mechanism (REST, matches the design):
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

### Fallback mechanism (Trading XML, evidenced by live production integrations):
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

### C2C postage mandates do not apply to business sellers:
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

### Payment/handover (re-confirmed):
- Cash on collection withdrawn UK-wide 1 Oct 2024 except cars/motorcycles/vehicles; buyers
  pay via eBay checkout; QR + 6-digit code confirms handover and releases funds:
  https://www.valueaddedresource.net/ebay-uk-drops-cash-on-collection/ ,
  https://community.ebay.co.uk/t5/Announcements/Changes-to-payment-methods-for-listings-with-collection-in/ba-p/7665204
- Category exclusions for collection-only: Authenticity Guarantee items and
  age-verification categories (irrelevant to office clearance stock):
  https://www.ebay.co.uk/help/selling/posting-items/setting-postage-options/local-collection?id=4181
