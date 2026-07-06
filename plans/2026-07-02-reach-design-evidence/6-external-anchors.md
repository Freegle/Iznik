# External anchors: what counts as a "reasonable" travel time/distance to collect a free item

Design-only input to the rippling reach parameter question. This note asks: independent of Freegle's own
data, what do (a) the UK's official travel-behaviour statistics, (b) comparable sharing/marketplace
platforms, and (c) retail-geography / urban-planning literature treat as a normal, acceptable travel
budget for a **discretionary, non-essential, low-value** trip like collecting a free secondhand item?

Method: web search + fetch, cross-checked against primary sources where possible. DfT National Travel
Survey figures below were pulled directly from the official ODS data tables (NTS0403e/f, NTS9912b,
NTS9914b — "Average trip length/duration by trip purpose, region and rural-urban classification of
residence: England"), not from secondary summaries. Everything else is attributed inline; anything I
could not verify from a primary or clearly-authoritative secondary source is flagged as unverified.

---

## 1. UK DfT National Travel Survey (NTS) — the closest official proxy

There is no NTS purpose category for "collecting a free item" or "freecycling." The two closest official
purposes are **Shopping** and **Personal business** (errands: banking, post office, medical, etc. — closer
in character to a discretionary short errand than "shopping" is). Figures are for **England**, weighted,
from the DfT's official NTS data tables (dataset last updated 27 Aug 2025, series runs 2002–2024).

### 1a. National averages, all areas (NTS0403e trip length in miles, NTS0403f trip duration in minutes)

| Year | Purpose | Avg. trip length (miles) | Avg. trip duration (minutes) |
|---|---|---|---|
| 2023 | Shopping | 3.7 | 17 |
| 2023 | Personal business | 5.1 | 19 |
| 2024 | Shopping | 3.7 | 17 |
| 2024 | Personal business | 5.0 | 20 |
| 2023 | All purposes (avg.) | 6.5 | 23 |
| 2024 | All purposes (avg.) | 6.6 | 24 |

Trip duration is *total journey time including waiting* (DfT note 2), not just drive time, so these are a
conservative (i.e. slightly generous) proxy for a pure drive-time budget. Shopping trip duration has been
remarkably stable: 16–19 minutes every year 2002–2024, regardless of big swings in distance and mode share.
That stability across two decades is itself informative — it suggests **people have a roughly fixed time
budget for shopping-type trips (~17 min), and distance/speed adjust to fit that budget**, not the reverse.
This is consistent with the well-known "travel time budget" literature in transport economics (Marchetti's
constant, ~consistent ~1hr/day, ~20-30 min one-way commute budget across cultures and eras) though I have
not independently verified Marchetti's constant here — flagging as background colour, not a load-bearing
figure.

### 1b. Rural/urban split (NTS9912b length, NTS9914b duration) — 2023, old classification

The old 4-way rural-urban classification of residence (used through 2023) gives directly what's needed:
urban-vs-rural asymmetry for the same purposes.

| Area type (2023) | Shopping: length (mi) / duration (min) | Personal business: length (mi) / duration (min) |
|---|---|---|
| Urban Conurbation | 2.9 / 17 | 4.0 / 21 |
| Urban City and Town | 3.5 / 16 | 5.2 / 19 |
| Rural Town and Fringe | 5.8 / 18 | 5.8 / 18 |
| Rural Village, Hamlet and Isolated Dwelling | 6.4 / 18 | 7.8 / 21 |
| **All areas** | **3.7 / 17** | **5.1 / 19** |

Key pattern: **duration barely moves (16-18 min shopping across all four settlement types) while distance
more than doubles (2.9 mi urban conurbation → 6.4 mi rural village)**. i.e. rural people travel roughly
2.2x the distance in roughly the same time, because rural average speeds are higher (fewer junctions/lights,
more A-road driving vs urban stop-start). This is the single most load-bearing external fact for Freegle's
problem: **it is time budgets, not distance budgets, that stay constant across urban/rural — which is
exactly what a drive-time-based reach (rather than a fixed-radius reach) already gets right.** The thing
that's wrong isn't the choice of drive-time as the unit; it's that Freegle holds the time constant instead
of holding something else (audience size) constant, and time and audience diverge by ~80x urban/rural (per
prior Freegle measurement, see main design doc).

### 1c. 2024 classification change — a useful reframing

From 2024 the ONS/DfT rural-urban classification changed to a 6-way split based on settlement type AND
proximity to a major town/city ("Urban: nearer", "Urban: further", "Larger Rural: nearer/further",
"Smaller Rural: nearer/further"). 2024 shopping figures:

| Area type (2024, new classification) | Shopping: length (mi) / duration (min) |
|---|---|
| Urban, nearer to a major town/city | 3.2 / 16 |
| Urban, further from a major town/city | 3.7 / 16 |
| Larger Rural, nearer to a major town/city | 5.2 / 17 |
| Larger Rural, further from a major town/city | 6.1 / 17 |
| Smaller Rural, nearer to a major town/city | 6.3 / 17 |
| Smaller Rural, further from a major town/city | 8.1 / 20 |
| **All areas** | **3.7 / 17** |

Same pattern holds even more granularly — duration is flat at 16-20 minutes across all six settlement
bands; distance ranges 3.2 to 8.1 miles (2.5x spread). This strengthens the case: DfT's own more granular
"how rural/remote are you" classification still finds travel *time* for shopping essentially constant, so
using minutes as Freegle's reach unit is externally well supported — the question is what number of
minutes, and the "hold the time constant" logic is exactly what generates the 80x audience spread problem
that started this whole design effort.

### 1d. Sanity check against Freegle's own reach ceiling

Freegle's current max reach is ~30 min drive (with a discussed 45 min ceiling for an extent-governor MVP).
NTS shopping-trip duration nationally is 16-20 minutes **one-way, all-purpose, all-mode** (car and non-car
blended; car-only would likely be a few minutes higher due to faster average speeds pulling harder on the
mean, but DfT doesn't publish shopping-only car-only duration in the tables pulled here — flagging as a
gap, not fabricating a number). Freegle's 30-45 min drive-time ceiling is therefore roughly **1.5-2.5x the
national average one-way shopping trip time**, even before accounting for the fact that collecting a free
item is a lower-stakes, more discretionary trip than "shopping" as a category (which includes routine
grocery runs people make out of necessity). This is directionally consistent with the moderator complaints
that reach "feels too large," at least for the outer part of the reach envelope — though it doesn't by
itself tell you the right number, since NTS is an average across ALL shopping (including the weekly big
supermarket shop that people plan to spend real time on), not specifically the marginal, optional "would I
go get this free thing" decision, which is likely to sit below the shopping-trip average, not above it.

### Sources
- [NTS0403: Average number of trips, miles and time spent travelling by trip purpose: England, 2002 onwards](https://www.gov.uk/government/statistical-data-sets/nts04-purpose-of-trips) — sheets NTS0403e (trip length) and NTS0403f (trip duration), .ods downloaded and parsed directly, last updated 27 Aug 2025.
- [NTS9912: Average trip length by trip purpose, region and rural-urban classification of residence](https://www.gov.uk/government/statistical-data-sets/ad-hoc-national-travel-survey-analysis) — sheet NTS9912b, same vintage.
- [NTS9914: Average trip duration by trip purpose, region and rural-urban classification of residence](https://www.gov.uk/government/statistical-data-sets/ad-hoc-national-travel-survey-analysis) — sheet NTS9914b, same vintage.
- [NTS 2023: Trips by purpose, age, mode and sex](https://www.gov.uk/government/statistics/national-travel-survey-2023/nts-2023-trips-by-purpose-age-mode-and-sex) — corroborates 169 shopping trips/person/yr, 622 miles/person/yr in 2023.
- [National Travel Survey 2023 factsheet (PDF)](https://assets.publishing.service.gov.uk/media/66c5c0b6cbe60889bddd278d/nts-2023-factsheet.pdf)

---

## 2. How comparable platforms bound visibility

| Platform | Unit used to bound reach | Value / mechanism | Rationale given (if any) |
|---|---|---|---|
| **Olio** | Distance (user-chosen radius) + a hard time cap for food safety | User-settable 0.3-16 miles for browsing; but for **Food Waste Hero collections specifically**, Olio's own guidance is "ideally within 2 km" and a hard ceiling of "never more than 1.5 hours" travel with the food | Two different rationales for two different bounds: the *ideal* 2 km is explicitly about **fostering local community connections** (a social/behavioural goal, not a technical one); the *hard* 1.5h ceiling is **food safety**, not community design. Notably Olio explicitly says it will let advertised distance extend beyond 2 km in rural areas or where volunteers are scarce — i.e. Olio already does something like an audience/availability-based fallback, not a fixed radius everywhere. |
| **Nextdoor** | Administrative/social unit ("neighbourhood"), not distance or time | Boundaries generated to enclose a target **number of households**, snapped to real/perceived boundaries (roads, rivers, school catchments, existing neighbourhood edges) rather than a radius; minimum 10 households to found a neighbourhood page | Patent filings describe explicit design goal: bound by household count first, then adjust the resulting polygon to avoid crossing "major boundaries" and to keep the group socially homogeneous (shared schools, similar house prices) — i.e. **audience size is the primary bounded unit, geography is secondary and adjusted to fit it**. This is structurally the same idea as Freegle's already-drafted extent-governor (expand until N* reached), just for a different content type. |
| **Buy Nothing Project** | Audience size (population), explicitly, with a defined split trigger | Target population **10,000-35,000 residents** per group footprint (lower end for high-engagement/high-internet-access communities, higher end for lower-engagement ones); groups are told to "sprout" (split) once they hit **~800-1,000 members** engaged, or population coverage gets too large | Explicit, named, and number-anchored rationale for population-based (not distance-based) bounding: too large → community feel and visibility of individual posts collapses; too small → not enough activity. This is the single clearest existing precedent for "bound by audience size, not distance," and it's exactly the audience-sized/extent-governor logic already drafted for Freegle. **Caveat (important, and directly relevant to the "no per-group slider" decision)**: BNP's *original* method of snapping group boundaries to existing informal/administrative neighbourhood lines reproduced historic redlining patterns and drew public criticism (Vice/other coverage, and an internal "equity overhaul" in 2020); BNP responded by changing its *boundary-drawing guidance* to explicitly avoid reproducing segregation lines, while keeping the population-count target. Lesson for Freegle: an audience-size target is sound, but the geographic *shape* used to reach that audience needs a rule that isn't a manual/local decision (which is exactly why the product owner rejected a per-group slider) — it should be a symmetric, algorithmic expansion (e.g. drive-time isochrone, which Freegle already uses) rather than a hand-drawn or locally-negotiated boundary. |
| **Freecycle** | Administrative unit (town) + a distance/population viability rule for *starting* a group, not for member-level reach | Coverage area described internally as "a circle with radius 10-20 miles depending on population density/transport"; but the *rule for whether a new town group is allowed to exist* is explicitly audience-based: "at least 20,000 people within a 30-minute [drive] radius of your proposed town" | This is a hybrid: distance is the descriptive shape, but the actual **decision rule for whether a boundary is viable is audience-size-within-a-drive-time**, i.e. almost exactly Freegle's own extent-governor formula (expand a drive-time isochrone until it contains N* people) — except Freecycle applies it once, at group-founding time, not dynamically per post. |
| **Gumtree** | Distance (user/search-chosen radius, km) | No official published default found; user sets a km radius per search | No stated rationale found; distance is just a search filter, not a moderation/visibility control — not really comparable to Freegle's problem (Freegle bounds who *sees a post exists*, Gumtree bounds a live search over an unbounded index). |
| **Facebook Marketplace** | Distance, but a "soft" radius | Reports of default radius varying (as low as 1 mi/km, commonly cited 25-50 mi); confirmed by multiple sources that the radius filter is soft — listings from well outside the chosen radius still surface | Not a considered design choice as far as could be found — more a side-effect of a recommendation-ranking system that treats distance as one signal among many, not a hard boundary. Weak comparator for Freegle's use case (which needs a genuine hard/near-hard cutoff for moderator sanity and email-blast volume control). |
| **Trash Nothing / OfferUp** | Distance (user-settable radius) | User-configurable distance filter in settings; no published default or rationale found | Not useful as an anchor beyond confirming "distance slider" is the generic default UX pattern most platforms fall back to — which is precisely the pattern Freegle's product owner already rejected for groups (for good reason: users/mods would set it arbitrarily, same failure mode BNP explicitly built its 10-35k *fixed target with algorithmic derivation* to avoid). |

### Bottom-line pattern across platforms
Two clusters emerge:
1. **Search-style marketplaces** (Gumtree, FB Marketplace, OfferUp, Trash Nothing) bound by **user-chosen
   distance**, because they are pull/search systems — the user decides how far they're willing to look, and
   a "wrong" radius just costs the user a wasted search, not anyone else anything. This is not a good
   precedent for Freegle, which is a push/broadcast system (the platform decides who gets emailed/notified
   about a post), where an over-wide radius costs *other people's* attention, not just the searcher's.
2. **Community/group-style platforms with a genuine push/membership component** (Nextdoor, Buy Nothing,
   Freecycle-at-founding-time) bound by **audience size** (households/residents/members), using distance or
   drive-time only as the *mechanism* to reach that audience target, not as the target itself. Freegle's
   own draft extent-governor MVP already follows this second, better-precedented pattern; the open gap is
   only the *calibration* of N* (the audience-size parameter), which none of these platforms publish a
   transferable numeric rationale for — BNP's 10k-35k is a *community cohesion* number for a slow-moving,
   low-frequency friendship-and-gifting community, not a *transaction-clearing* number for a
   high-frequency, one-off item exchange, so it should not be imported directly as Freegle's N*.

### Sources
- [Olio: How far can I travel for Food Waste Hero collections?](https://help.olioapp.com/article/649-traveling-distance)
- [Olio (app) — Grokipedia summary](https://grokipedia.com/page/Olio_(app)) (secondary, cross-checked against Olio's own help centre above)
- [Nextdoor neighborhood social network method, apparatus, and system (US patent)](https://image-ppubs.uspto.gov/dirsearch-public/print/downloadPdf/8863245)
- [Nextdoor: Add or modify your agency's jurisdictional boundary](https://help.nextdoor.com/s/article/How-to-modify-your-service-area?language=en_US)
- [Buy Nothing Project: Determining Group Footprints](http://buynothingproject.org/determine-group-footprint)
- [Buy Nothing Project: Various Sprout Posts](http://buynothingproject.org/various-sprout-posts)
- [Are Buy Nothing Groups Really Free? — Next City](https://nextcity.org/urbanist-news/are-buy-nothing-groups-really-free) (redlining/equity-overhaul context)
- [Buy Nothing Project — Wikipedia](https://en.wikipedia.org/wiki/Buy_Nothing_Project)
- [Freecycle: Point-Centered Town Groups](https://www.freecycle.org/pages/Teams/Point-Centered_Groups)
- [Freecycle: Start a New Town](https://www.freecycle.org/pages/StartATown)
- [Facebook: Sort by distance or new listings on Marketplace](https://www.facebook.com/help/fblite/248514105820200)
- [Understanding Facebook Marketplace's Distance Dilemma](https://www.oreateai.com/blog/understanding-facebook-marketplaces-distance-dilemma/c60552e73b900f7a1088fd9fc39597d5)
- [Gumtree search settings](https://www.gumtree.com/uk/srpsearch+settings)

---

## 3. Distance-decay literature: gravity/Huff models, 15/20-minute norms, willingness-to-travel

### 3a. Gravity/Huff retail models — decay exponent by good type
The Huff model's distance-decay exponent (λ in P ∝ Attractiveness / Distance^λ) is **empirically
data-fitted per study/city**, not a fixed universal constant — commonly reported in the **1.5-2.0** range
overall, but with a clear and consistently-replicated qualitative pattern: **convenience goods (groceries,
everyday small purchases) have a *larger* exponent (steeper decay, i.e. people are much less willing to
travel far) than comparison goods (furniture, big one-off purchases), which have a *smaller* exponent
(shallower decay, willing to travel further)**. This is the standard retail-geography finding and is
repeated across multiple independent secondary sources (GIS Geography's Huff-model explainer, ArcGIS Pro's
own Huff-model documentation, and academic Huff-calibration papers), though I was not able to pin an
exact, single, citable numeric pair (e.g. "grocery = 2.2, furniture = 0.9") to one primary study in the
time available — flagging the *qualitative* direction as well-supported, the *specific numbers* as
unverified/likely-varies-by-study.

**Relevance to Freegle**: a free secondhand item sits ambiguously between the two categories. It has
convenience-good economics (zero price, no real reason to travel far — nobody "needs" this specific free
lamp) but comparison-good psychology for anything with perceived resale/desirability value (a good sofa,
a bike). The gravity-model literature would predict **most free-item collection trips should look more
like convenience-good trips (short decay, most collectors very close) with a long thin tail for unusually
desirable items** — which is consistent with Freegle's own measured facts (0.9-1.0 distinct repliers/post,
56-66% zero-reply, and the "dense collector is typically the ~1,719th nearest active member" pattern
described in the design brief, i.e. even where lots of people COULD reply, in practice almost nobody comes
from far away for a typical item).

### 3b. 20-minute neighbourhood / 15-minute city — the wrong mode, right ballpark number
- **15-minute city** (Carlos Moreno, popularised via Paris 2020-21): daily essentials reachable within
  **15 minutes by foot or bicycle** — explicitly **not** a car-based or driving standard.
- **20-minute neighbourhood** (Melbourne/Portland planning policy, adopted from ~2014): "living locally"
  within a **20-minute walk, cycle, or local public transport trip**; Victorian state government
  operationalised it as an 800m radius for a round trip on foot. Also explicitly **not** a driving
  standard — "the concept is not about travel by car."

**Important caveat for Freegle**: both of these are **active-travel/public-transport, not-driving**
policy norms aimed at reducing car dependency, and they bound a *resident's* walk to fixed *amenities*
(shops, GP, park), not a *platform's* broadcast radius for a one-off transaction. They are not directly
transferable as a numeric anchor for Freegle's *drive-time* reach parameter — importing "15" or "20" as
literal minutes would be a category error, since Freegle's constant is drive-minutes and these are
walk/cycle-minutes (roughly 4-5x shorter distance for the same minute value than driving). Their value here
is as **corroborating evidence that ~15-20 minutes is a broadly-recognised, cross-domain threshold for
"an acceptable amount of everyday-life friction,"** not as a number to copy directly into a drive-time
parameter.

### 3c. Retail trade-area convention: the "10-minute drive" primary catchment
Independent of Huff-model math, applied retail site-selection practice commonly treats the **first
5-10 minutes' drive time as the "primary trade area,"** generating the majority (commonly cited 50-80%) of
a convenience-oriented retailer's footfall, with a rapidly-thinning "secondary" catchment beyond that.
This is standard commercial-geography practice (used by chains/site-selection consultancies) rather than a
single peer-reviewed number, so should be read as an industry heuristic, not a scientific constant — but it
is a second independent line of evidence (alongside NTS's flat ~17min shopping-trip duration) pointing at
a **single-digit-to-low-teens-minutes core catchment for convenience-type activity**, with the "tail"
beyond that generating rapidly diminishing returns per additional minute.

### 3d. Willingness-to-travel for free/secondhand goods specifically — thin evidence base
- Explicit freecycling-and-distance academic literature is **thin**. A 2026 cross-cultural study
  ("Exploring the associations of generalized trust, climate change conspiracy beliefs and freecycling,"
  *British Journal of Psychology*) exists and studies freecycling behaviour across 34 cultures, but its
  focus is trust/psychology, not travel distance, and the full text was paywalled (HTTP 402) so its
  distance-related content (if any) could not be verified here — **do not cite this as a distance-anchor
  source**, flagging only that it exists as the closest named academic freecycling study found.
- Secondhand/charity-shop retail research (multiple sources) confirms travel distance for secondhand
  shopping is driven mainly by **store-specific attitude, perceived quality, and economic motivation**
  rather than distance being a fixed, universal budget — i.e. willingness to travel for secondhand goods is
  **item/venue-dependent, not a flat constant**, which supports Freegle's own instinct that reach should
  probably not be one fixed number but adapt to something (which is exactly what the extent-governor MVP
  already proposes, just currently uncalibrated).
- No rigorous, citable primary study was found that puts a specific minute or mile figure on "how far
  people will travel to collect a free item." This is a genuine evidence gap, not just a search-failure —
  worth stating plainly rather than papering over with a low-confidence number.

### Sources
- [Huff Gravity Model: Store Customer Predictions — GIS Geography](https://gisgeography.com/huff-gravity-model/)
- [How Huff Model works — ArcGIS Pro documentation (Esri)](https://pro.arcgis.com/en/pro-app/3.5/tool-reference/business-analyst/understanding-huff-model.htm)
- [Accessibility Analysis: Reach, Gravity, and Huff Model — Medium/AxU Platform](https://axuplatform.medium.com/advanced-huff-mode-894e0012c5d5)
- [The Distance-Decay Function of Geographical Gravity Model: Power Law or Exponential Law? (arXiv)](https://arxiv.org/pdf/1503.02915)
- [15-minute city — Wikipedia](https://en.wikipedia.org/wiki/15-minute_city)
- [Operationalising the 20-minute neighbourhood — International Journal of Behavioral Nutrition and Physical Activity (Springer)](https://link.springer.com/article/10.1186/s12966-021-01243-3)
- [People love the idea of 20-minute neighbourhoods — The Conversation](https://theconversation.com/people-love-the-idea-of-20-minute-neighbourhoods-so-why-isnt-it-top-of-the-agenda-131193)
- [Trade Area Analysis for Retail Site Selection — Geod](https://www.geod.app/blog/trade-area-analysis)
- [Trade Area: Definition, Mapping Methods & Analysis Guide — GrowthFactor](https://www.growthfactor.ai/resources/blog/what-is-a-trade-area)
- [Research: How Far Will Consumers Travel to Make Routine Purchases? — Access Development / Gary Toyn, Aug 2021](https://blog.accessdevelopment.com/research-how-far-will-consumers-travel-to-make-routine-purchases) (national US survey, n=2,131; 87% travel ≤15min, 93.2% ≤20min for everyday purchases; urban 97.2% ≤20min vs rural 70.3% ≥20min — note this is a commissioned industry survey, not peer-reviewed, treat as directional not definitive)
- [Effects of store image and attitude toward secondhand stores on shopping frequency and distance traveled (ResearchGate)](https://www.researchgate.net/publication/235289697_Effects_of_store_image_and_attitude_toward_secondhand_stores_on_shopping_frequency_and_distance_traveled)
- Freecycling/trust study (BJP, paywalled, not independently verified beyond its existence): [Exploring the associations of generalized trust, climate change conspiracy beliefs and freecycling — BJoP](https://bpspsychub.onlinelibrary.wiley.com/doi/10.1111/bjop.70058)

---

## 4. Urban-rural asymmetry — direct answer

Combining sections 1 and 3:

- **DfT NTS (official, primary source, England, 2023-24)**: shopping trip **duration** is flat at
  16-20 minutes across all rural/urban settlement bands (old 4-way and new 6-way ONS classifications alike);
  shopping trip **distance** ranges from **2.9-3.5 miles in urban areas to 5.8-8.1 miles in rural areas**
  — i.e. **rural residents travel roughly 2-2.5x further, in essentially the same time**, because rural
  average road speeds are higher. This is the single most robust, directly-applicable, quantified
  urban-rural asymmetry finding in this research, and it independently corroborates Freegle's own measured
  ~80x active-member-pool spread at a *fixed* drive-time: if Freegle holds drive-time constant, and rural
  roads are faster so the isochrone footprint is larger in km² for the same minutes, AND rural population
  density is far lower per km², the two effects compound multiplicatively rather than cancelling — which
  is exactly the mechanism generating the reported 80x urban/rural active-member-pool spread.
- **Industry survey (Access Development, US, 2021, non-peer-reviewed)**: 97.2% of urban consumers travel
  ≤20 min for everyday purchases vs only 29.7% of rural consumers doing so (i.e. 70.3% of rural consumers
  need >20 min) — same qualitative direction as DfT, though this is a different national context
  (US, industry-commissioned) so should be weighted as corroborating, not primary, evidence.

---

## Anchor table (source, metric, value, applicability)

| # | Source | Metric | Value | Applicability to Freegle's reach parameter |
|---|---|---|---|---|
| 1 | DfT NTS0403f (2023-24, England, official) | Avg. shopping trip duration, all areas | **17 min** (one-way, all modes, incl. waiting) | High — closest official proxy for "how long is a normal discretionary local errand." Freegle's 30-45 min ceiling is ~1.5-2.5x this. |
| 2 | DfT NTS0403f (2023-24) | Avg. personal-business trip duration, all areas | 19-20 min | High — a second, independent official purpose-category giving the same ballpark (17-20 min). |
| 3 | DfT NTS9914b (2023, England, official) | Shopping trip duration, urban conurbation vs rural village | 17 min vs 18 min (**time ≈ constant**) | Very high — direct evidence that drive-*time* budgets, not distance budgets, are the invariant across settlement types; validates time as the reach unit, not the reach ceiling. |
| 4 | DfT NTS9912b (2023, England, official) | Shopping trip length, urban conurbation vs rural village | 2.9 mi vs 6.4 mi (**~2.2x**) | High — quantifies the urban-rural asymmetry that a fixed drive-time reach must reconcile; helps explain (not fully — density does the rest) Freegle's ~80x audience spread. |
| 5 | Access Development survey (2021, US, industry, n=2,131) | % travelling ≤20 min for everyday purchases | Urban 97.2% vs rural 29.7% | Medium — corroborating (different country, non-peer-reviewed), same direction as DfT. |
| 6 | Retail trade-area convention (industry heuristic) | "Primary trade area" drive time for convenience retail | 5-10 min generates 50-80% of custom | Medium — supports a short "core" catchment with a fast-thinning tail, i.e. non-linear value of extra minutes; not a scientific constant. |
| 7 | Huff/gravity model literature | Distance-decay exponent, convenience vs comparison goods | λ≈1.5-2.0 typical; convenience > comparison qualitatively | Medium — direction well-supported across sources, exact numbers not pinned to one citable study; free items sit ambiguously between the two categories. |
| 8 | Buy Nothing Project (self-published policy) | Target population per hyperlocal group | 10,000-35,000 residents; split trigger ~800-1,000 active members | High as a *precedent for audience-size bounding*, low as a *transferable number* (different use-case: slow-moving gift community, not fast-clearing transaction platform). Also carries an explicit cautionary tale: hand-drawn/local boundary-setting reproduced redlining — reinforces why an algorithmic (not per-group-manual) rule is right. |
| 9 | Nextdoor (patent filings) | Bounding unit | Household count, with geography adjusted to fit it, not the reverse | High as a structural precedent — same "expand geography to hit an audience target" logic as Freegle's draft extent-governor. |
| 10 | Freecycle (self-published policy) | New-town viability rule | ≥20,000 people within a 30-min drive radius | High as structural precedent (drive-time isochrone containing a population threshold = literally Freegle's extent-governor formula), applied once at founding rather than per-post. |
| 11 | Olio (self-published policy) | Ideal vs hard collection distance | Ideal ≤2 km (community-cohesion rationale); hard ceiling 1.5h (food-safety rationale, not comparable) | Medium — shows a platform explicitly separating a "soft, socially-motivated" target from a "hard, safety-motivated" ceiling, a pattern Freegle could mirror (soft audience-based target, hard outer drive-time ceiling) but Olio's own numbers aren't transferable (food perishability ≠ Freegle's item durability). |
| 12 | 15-minute city (Moreno) / 20-minute neighbourhood (Melbourne/Portland) policy | "Acceptable" walk/cycle/transit minutes for daily needs | 15-20 min | Low-medium — corroborates the general ~15-20 min order of magnitude as a widely-recognised "everyday life" threshold, but wrong travel mode (walk/cycle, not drive) so not directly transferable; a category error to import literally. |

---

## Bottom line

External evidence brackets **reasonable discretionary local-errand travel time at roughly 15-20 minutes
one-way** as the *typical/average* case, essentially flat across urban and rural England when measured in
time (DfT NTS, official, primary) — with rural residents covering **~2-2.5x the distance** in that same
time window due to higher average road speeds. Industry retail-geography practice independently converges
on a similar **5-10 minute "primary" catchment** with fast-diminishing marginal value beyond that, and
namesake urban-planning norms (15-minute city, 20-minute neighbourhood) independently converge on the same
15-20 minute order of magnitude for "acceptable everyday friction," albeit for walk/cycle modes, not
driving — so should be read as directionally corroborating, not numerically transferable.

**Platforms bound broadcast/push-style community reach by audience size (households, residents, members)
far more often — and far more deliberately — than by distance or time; distance/time in the well-designed
examples (Nextdoor, Buy Nothing, Freecycle-at-founding) is only the *mechanism* used to reach a target
audience size, not the target itself.** Distance-only bounding (Gumtree, Facebook Marketplace, Trash
Nothing/OfferUp) appears mainly in pure user-initiated *search* systems where an oversized radius only
costs the searcher, not a whole community's attention — not a good precedent for Freegle's *push*
notification model, where an oversized reach costs everyone else's inbox.

**Neither of these two facts alone tells Freegle what N* (target audience size) or T_max (drive-time
ceiling) should be** — no external source publishes a number calibrated to "free, low-value, one-off item
collection" specifically, which remains a genuine evidence gap best filled by Freegle's own historical data
and/or the already-scoped reach experiment, not by external benchmarking. What external evidence robustly
supports is the **shape** of the answer: (a) drive-*time* is the right unit to hold as the outer bound,
because time (not distance) is empirically the thing that stays roughly constant across urban/rural in
real travel behaviour — validating Freegle's existing choice of drive-time as the reach primitive; (b) the
*current* 30-45 minute ceiling looks generous against the ~17-20 minute "typical discretionary local trip"
anchor from official DfT data, which is at least directionally consistent with the moderator complaints
that reach feels too large; and (c) the fix precedented by the platforms that do this well is not a smaller
fixed time (which would just move the same asymmetry problem to a different point on the curve) but an
**audience-size-governed expansion** — which is exactly the shape of the extent-governor MVP already
drafted, with external evidence here contributing mainly (i) confidence that time-based bounding is
directionally right, (ii) a rough order-of-magnitude sanity check that today's ceiling sits high relative
to normal discretionary-trip behaviour, and (iii) real-world precedent that algorithmic
audience-size-to-geography expansion (not manual/local distance-setting) is how mature platforms solve
this same problem without reproducing the "everyone sets it randomly" failure mode the product owner
already correctly rejected.
