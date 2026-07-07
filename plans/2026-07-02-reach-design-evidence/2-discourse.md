# Discourse Topic 9808 ("Rippling Out") — Complaint Signal Extraction

**Source**: https://discourse.ilovefreegle.org/t/9808 — 414 posts, 2026-06-21 to 2026-07-02 (12 days),
30 distinct human participants (+ Edward_Hibbert as thread owner/developer, 120 posts; Neil as Board
Chair, 8 posts). Fetched in full via Discourse API (`User-Api-Key` header only — see auth note at end).

This is signal extraction, not sentiment: every concrete claim, distance, and proposal is pulled out
below with post numbers for citation. The thread is a general "rippling out" rollout-feedback thread
(covers auto-join, auto-approve, UI bugs, accessibility, moderator-control anxieties, etc.) — **most
of the 414 posts are NOT about reach/distance**. This document filters to the reach-specific subset
plus enough context to judge what would satisfy each complainant.

---

## 1. Who is complaining (population count)

30 distinct named participants posted in the thread. Of these, participants who raised **reach/distance**
complaints specifically (as opposed to auto-join UX, accessibility, moderation-control, or general
change-aversion complaints):

| Complainant | Posts in thread | Reach-specific complaints | Role/group |
|---|---|---|---|
| Jax (Jackie) | 55 | Yes — repeated, detailed (London/Brent-Harrow-Ealing) | Mod, Brent/Harrow |
| Derek | 34 | Yes — repeated, with distance screenshots | Mod, Fife/Perth (Scotland) |
| Jos | 35 | Yes — Swindon vs London/Islington/Croydon comparison | Mod, Fife/Livingston/Dumfries |
| Neville_Reid | 28 | Yes — Chilterns/32-groups, Hull/Castleford, Harlow | Mod (multiple London/Yorks groups) |
| Group-Mod-J (Jools) | 13 | Yes — Hull/Scunthorpe/Castleford "over the water" | Mod, Hull/Bridlington/Scarborough |
| Sheila | 13 | Yes (via forwarded member complaints) | Mod (unnamed groups) |
| Angelika | 14 | Partial (mostly auto-join/clutter, not raw distance) | Mod, Tendring |
| Louise | 6 | Yes — rural North Lancashire | Mod, Kendal/Carnforth/Lancaster |
| Michael | 8 | Yes — Wellingborough/St Neots vs Bedford | Mod, Milton Keynes area |
| May_Stirling_Freegle | 3 | Yes — remote northern Stirling under-reach | Mod, Stirling |
| Mike_Jury | 3 | Yes — Thurrock/Farnborough | Mod |
| Melissa | 11 | Yes (as a *member*, no car) | Mod, Eastbourne (dual role) |
| Diz | 8 | No (broadly positive) | Mod, Gloucestershire |
| Jacky | 10 | No (rural, "always been absurd to me too") | Mod, Norfolk |
| Vee | 2 | No — objects to auto-join/consent, not distance per se | Mod |

**Count**: roughly **10-12 of 30 distinct posters** (about a third) raised genuine reach/distance
complaints with concrete detail. A further 3-4 raised *forwarded member* complaints (Sheila, Angelika,
Derek relaying named members like "Tony Almond", "Doug Freecycle", "Robin Langley", member IDs
41876853, 41649498, etc.) — so the complaint surfaces both as direct-mod opinion and as
mod-as-conduit-for-member. The rest of the 30 either didn't engage with reach specifically, were
positive, or complained about orthogonal things (auto-join consent, accessibility, TrashNothing
crossposting, UI bugs, moderation-control philosophy).

Freegle has roughly 500 groups nationally; this is a vocal, self-selected Discourse-reading subset,
explicitly **not** representative (Post #395, Neil: "we need to improve our internal communications...
only about 20% of Freegle volunteers are members of the WhatsApp groups" — implying an even smaller
fraction actively read/post on Discourse).

---

## 2. (a) "Reach is genuinely too large" — concrete examples

These are complaints with a specific place-pair, distance, or travel-time attached, i.e. falsifiable
claims about the mechanism rather than pure sentiment.

### Distance/place-pair table

| # | Post | Poster | From → To | Claimed distance/time | Claim type |
|---|---|---|---|---|---|
| 1 | #42 | Jacky | Norwich/Lowestoft/Cromer → (home, unnamed) | 17-28 miles, replies within 8h | Reported as **success**, not complaint (context for calibration) |
| 2 | #204 | Derek | 30 miles vs 8 miles | shown before closer ones | Ordering bug, not reach size |
| 3 | #205 | Jax | London, "large amount" | ripples "almost immediately" | Speed complaint, not distance |
| 4 | #207 | Jos | Harrow → "pretty much all of London" | half the slider ≈ too far | Reach-too-large |
| 5 | #209 | Jos | Croydon → S. Kensington & Chelsea (crosses the river) | half slider | Reach-too-large, cultural/psychological barrier (river) |
| 6 | #215/272 | Derek | KY7 (Glenrothes) → Cameron Toll/Blackhall, Edinburgh | shown as 16-18 miles (crow-flies), actual ~30-45 miles / ~1h by road | **Crow-flies vs road-distance display bug**, not reach-radius per se |
| 7 | #224 | Jos | Kensington & Chelsea ← Brent (W), Islington (E), Croydon (S) | "just under 40 groups in London" reachable | Reach-too-large (density) |
| 8 | #229/246/255/279/281 | Jax | Brent → auto-joined to 27, then 32 groups, incl. Hemel Hempstead | 40 miles, "hour on good day, 2 on bad" | Reach-too-large + auto-join volume |
| 9 | #234 | Neville_Reid | crosspost reaching 25-32 communities "within minutes" | — | Speed + breadth complaint |
| 10 | #244 | Jax | Watford member: "20 miles away, don't even want to see 10" | 20mi rural-OK vs "like 100" in London | Explicit urban/rural asymmetry articulation |
| 11 | #248/250 | Jos, Neville_Reid | **Swindon vs London(Islington)**: halfway on reach slider = ~30 min travel Swindon (judged "pretty perfect"), vs ~50+ min London/Islington (judged too far); Swindon ~1,000 members vs London ~27,000 members at that slider position | 30 min "perfect" vs 50 min "too far" at same slider % | **Core structural complaint** — same slider position, wildly different real minutes and audience size |
| 12 | #250 | Neville_Reid | NW London offer → East London to **the Chilterns** | "an hour's drive each way," 32 groups | **The canonical complaint** cited in project brief |
| 13 | #255 | Jax | Brent → Hemel Hempstead shown as "near me" first | 40 miles, 1-2h | Reach-too-large + ranking bug (shown before genuinely-near posts) |
| 14 | #260/262 | Derek | Fife → Queensferry | member "would never travel to" | Reach-too-large |
| 15 | #272 | Derek | Glenrothes (KY7) → Blackhall, Edinburgh | shown 16mi, actual ~30mi one-way | Distance-display bug feeding perceived reach-too-large |
| 16 | #277 | Derek | Fife, "some, like me, live right in the middle...not interested in what is offered 30+ miles away. 60 miles is a long way to travel for a stick of rhubarb" | 30-60 miles | Reach-too-large (rural but "middle of group", not boundary) |
| 17 | #321/340 | Mike_Jury | (Medway?) → Thurrock | 35 miles | Reach-too-large |
| 18 | #341/346 | Mike_Jury | → Farnborough | 75 miles claimed, resolved as Farnborough-Kent confusion (~20mi from Thurrock) | Turned out to be a **place-name ambiguity bug**, not real reach |
| 19 | #343 | Group-Mod-J | Ryedale/Scarborough/Grimsby/Bridlington/Hull "getting people joined up from all over" | unspecified | Reach-too-large, general |
| 20 | #356 | Jos (relaying member) | North Bristol member: "shown ads 40 mins to an hour's drive away... only 6 within 5 miles out of 99" | 40-60 min | Reach-too-large, dense-ish city |
| 21 | #362 | Melissa | KT5 (Surbiton) → Pyrford GU22 (14mi), Reigate RH2 (15mi) | 14-15 miles, "without a car, entirely inaccessible" | Reach + **no-car accessibility** confound |
| 22 | #365/368 | Derek (relaying Bishop Auckland member) | → Penrith / Newcastle / Northumberland | unspecified, "half the country" | Reach-too-large |
| 23 | #370 | Michael (relaying member Robin) | West London → Oxford, Slough, "random areas of North and West London" | unspecified, "hundreds and hundreds" | Reach-too-large |
| 24 | #373 | Jos | Portobello → Livingston, "narrow corridor around the M8" | unspecified | **Motorway-corridor artifact** — isochrone stretches along fast roads, member sees only the far endpoint not the narrow path |
| 25 | #389 | Group-Mod-J | Castleford → Hull (via Howden, "just inside Hull's area") | 29 min by car (per Edward, #390) | Disputed — mod says "blatantly out of area," Edward says 29 min is legitimately within reach |
| 26 | #391 | Neville_Reid | Harlow (CM20) → Tower Hamlets | 42-70 min depending on traffic (M11/A12), rippled after **3 hours** not 1 | Reach-too-large + **isochrone volatility** (same route showed 70min then 47min within the same session) |
| 27 | #402 | Vee | unspecified, "rippled out at 21:22, 3 minutes after I approved it" to 11 groups | — | Speed complaint |

### Distinct place-pairs with a stated distance/time (deduplicated, for the "concrete examples" ask)

1. **NW London → Chilterns/East London**: ~1 hour drive each way, post visible on 32 groups (#250) — *the flagship complaint, matches project brief*
2. **Swindon vs London/Islington** at same slider position: 30 min (Swindon, judged fine) vs 50+ min (London, judged too far); 1,000 vs 27,000 members reachable (#248)
3. **Glenrothes (KY7) → Blackhall, Edinburgh**: shown as 16 miles, actually ~30 miles/1h by road (#272) — crow-flies display bug
4. **Glenrothes (KY7) → Cameron Toll, Edinburgh**: shown 18mi, actual ~45 miles/1h drive (#215) — same bug
5. **Croydon → S. Kensington & Chelsea**: crosses the Thames, ~half the reach slider (#209)
6. **Harrow → "pretty much all of London"**: half the slider (#207)
7. **Brent → Hemel Hempstead**: 40 miles, shown as "near me" first (#255)
8. **(Medway area) → Thurrock**: 35 miles (#321)
9. **Harlow (CM20) → Tower Hamlets**: 42-70 min via M11/A12, rippled after 3h not the stated 1h (#391)
10. **North Bristol**: 40-60 min drive-time posts dominating feed, only 6/99 within 5 miles (#356)
11. **Surbiton (KT5) → Pyrford (14mi) / Reigate (15mi)**: reachable but "entirely inaccessible" without a car (#362)
12. **Portobello → Livingston**: M8 corridor artifact (#373)
13. **Castleford → Hull (via Howden)**: 29 min by car — disputed whether "too far" (#389/390)

### Structural pattern in the genuine complaints

The single clearest, most quantified, most repeatable complaint is the **Swindon-vs-London
"same slider position, wildly different real-world reach" problem** (#248, echoed by Neville_Reid
#250 and implicitly by Jos #209/224). This is exactly the problem statement in the project brief
(the ~80x active-member-pool spread at fixed drive-time). Everything else in the "genuinely too
large" bucket is either (a) an instance of that same structural issue in a different London/dense
location, (b) a **crow-flies-vs-road-distance display bug** feeding perceived unfairness (distinct
technical issue, already flagged as fixable by Edward in #217/274/point 217's "may now be possible
to change to road distance"), or (c) a **motorway-corridor artifact** where the isochrone shape
stretches narrowly along a fast road and the member only sees "reaches X" without seeing that it's
a thin sliver, not an area (#373).

---

## 3. (b) "Perception/communication" complaints

Complaints where the underlying mechanism is arguably working as designed, but the *presentation*,
*framing*, or *mod's own lack of situational awareness* is the actual problem.

- **#12 (Edward)**: explicitly frames Fife itself as "a massive group... someone near one edge has
  very little in common with someone near the far opposite" — i.e. mods complaining about their
  group's own span not realizing their own group is *already* large/heterogeneous.
- **#129/130/133 (Edward)**: the single most repeated rebuttal in the whole thread — "Unless you live
  dead in the centre of a circular group, many people in rippled out groups are closer than many
  people in the home group... only 0.14% of active freeglers live somewhere where everyone in their
  home group is quicker to reach than anyone in another group is" (CV37 8GY / North Cotswold example:
  Tetbury 1h vs Evesham 20min, Shipston 15min, Stratford 17min, Worcester 20min, Chipping Norton 20min
  — all *closer* than the far end of the poster's own home group).
- **#155/223/235/396 (Edward)**: repeated "being on the touch-list ≠ being shown to everyone" framing
  — mods conflate "post appears in ModTools as touching my group" with "all my members are bombarded."
  Edward: "So: worry less" (#210); "please can people stop posting complaints about how they think it
  works rather than how I have repeatedly explained that it does" (#235, visibly frustrated).
- **#237 (Neville_Reid)**: this is explicitly identified and *accepted* as a communication/wording
  problem, not a mechanism problem: "For years, volunteers have interpreted 'on my group' –
  correctly until now – as 'shown to everyone in my group'... how about 'May also be shown to some
  members in'?" — Edward agrees (#238) and implements it.
- **#343 (Group-Mod-J / Jools)**: "regardless of what 'the data' says I think rippling is ruining
  things" — explicit statement that the objection is to the *principle* (loss of local control,
  historically-curated boundaries "hoovered up" by out-of-area members) not to a specific measured
  distance.
- **#400 (Sheila)**: "Our suggestions are slapped down with quotes of data, that we can't possibly
  have fully" — trust-in-data breakdown, a communication/authority problem more than a metrics
  problem.
- **#7 (Edward, very early)**: "If it doesn't match your feeling about what it should be, please
  consider that it's probably got a more rigorous sound basis than that feeling" — sets an
  adversarial, data-vs-gut-feel tone from post #7 that recurs (and visibly costs goodwill) throughout;
  several later posters (Sylvain #69, Lorraine #67, Angelika #102) react specifically to being told
  their concerns don't matter versus being consulted.
- **#391 (Neville_Reid)**: self-corrects mid-post ("I think I was misunderstanding what '40 minutes
  ago' meant") — a case of a mod initially misreading the UI, then admitting a chunk of their own
  complaint was a misunderstanding, not a real problem. Still lands on "that kind of giving is not
  FreeGLE, it's only FreeG" as a principle objection once the number is corrected to 3h not 1h.
- **#61 (Beth)**: explicit self-aware counter-example — Norfolk coastal village member routinely
  travels an hour to Norwich/Cambridge/Ely, "for me it would never be an extra trip." Shows the
  "genuinely too far" judgment is itself locally-contingent and some mods already understand this.

**Verdict on this bucket**: real and substantial. A meaningful fraction of the volume in the thread
is mods not having internalized that (a) "touches group" ≠ "shown to all members," (b) their own
"home" group is itself heterogeneous, and (c) the mechanism already down-weights/de-prioritizes
distant posts even when they're technically "reachable." Any design for the tuning parameter needs
an accompanying **legible explanation artifact** (see requirements section) independent of getting
the number right, because some of this heat is orthogonal to the number.

---

## 4. (c) In-thread proposals from mods

What moderators themselves proposed, in the order raised:

1. **#289 (Derek)**, echoed **#309 (Derek)** "I proposed a slider for members to use so they could
   set their own limit for the ripple," referencing the existing ChitChat feature that lets members
   pick 1/2/5/10/20/50 miles. **This is the per-user distance slider that eventually shipped** (#382,
   "I've made some changes... a new slider. This allows a user to further restrict the posts they
   see"). NOTE: this is a **per-member** slider (self-selected max distance), explicitly different
   from the **per-group** slider the product owner already rejected per the task brief — worth
   flagging that this in-thread ask was already granted in a different form (member-level opt-down)
   by the end of the thread, and the complaint volume visibly drops afterward (#383 "Brilliant",
   #385 "already a happier Freegler," #397 Jax "we've come a long way," #408 "I'm loving the slider").
2. **#211/224 (Jax/Jos)**: "starting at 1 mile and moving outwards more slowly" — i.e. slow down the
   time-based schedule, especially in dense areas (this is a *schedule*, not *ceiling*, proposal).
2b. Related: **#128 (Derek)** — push the first ripple step out from 1h to "4 or 6 hours."
3. **#304 (Neville_Reid)**: per-post "Approve locally" flag/button for mods to mark certain posts
   (school-specific items, high-demand WANTEDs like TVs/laptops) as **not eligible for wide ripple**
   — a per-post override rather than a global parameter change. Edward's response (#306): "a good
   idea, though there are some things to think through" — not rejected outright.
4. **#307/308/311/312 (Neville_Reid, Jax, Jos)**: stop rippling WANTEDs for "popular"/high-demand
   items (TVs, laptops) specifically, on the theory these get satisfied locally or not at all.
   Edward's response (#310): can't reliably classify WANTED-likely-to-be-satisfied; proposes reordering
   (OFFERs first, no consecutive WANTEDs) instead of suppressing ripple.
5. **#373 (Jos)**: truncate/collapse motorway-corridor slivers in the displayed reach shape so members
   don't see "reaches Livingston" without realizing it's a narrow M8 sliver, not an area.
6. **#217/219/274 (Edward, responding to Derek's repeated crow-flies complaint)**: switch the
   *displayed* per-post distance from crow-flies to road-distance — a display-truthfulness fix, not a
   reach-radius change, but directly reduces perceived unfairness (multiple posters, e.g. #204, #215,
   #272, cite the crow-flies number as evidence the system is broken).
7. **#325 (May_Stirling_Freegle)**: use AI/population-based "levelling" — i.e. adjust for local
   population density so remote/rural members aren't structurally under-reached. (This is the flip
   side of the "too large" complaint — a call for asymmetric ceiling by area type, which the project's
   ONS-rural-urban N* stratification concept already addresses in spirit.)
8. **#156/160 (Neville_Reid, Edward)**: use Multiple Index of Deprivation to bias ripple direction
   toward under-connected/deprived areas (already implemented per Edward, #158) — a fairness-of-reach
   proposal, not a ceiling proposal, but relevant as prior art for using external indices to modulate
   reach.
9. **#159/162/163 (Diz, Group-Mod-J, Edward)**: avoid rippling across water/via tolls unless there's
   a real bridge/tunnel — implemented same-day (#163/164/165).
10. **#3/9 (Derek)**: stop TrashNothing members from manually crossposting to multiple groups at
    once, since rippling now supersedes it — a scope-reduction proposal for a related but distinct
    mechanism.
11. **#319 (Angelika)**: auto-join members to groups for *visibility to mods* only, without
    subscribing them to that group's posts/emails — decouples "membership as post-eligibility record"
    from "membership as notification source." Addresses the auto-join clutter complaint, not reach
    per se, but frequently conflated with reach complaints in the thread (many "reach too large"
    complaints are actually "I got auto-joined to too many groups and now get emails from all of
    them" — see #229/246/255/279 Jax, #354/357 Sheila, #386 Vee).
12. **#397 (Jax, summarizing state as of end of thread)**: explicit ask for "a way for moderators to
    restrict certain posts to a more local area, for example, a drop down list, tick box" — i.e. a
    **per-post, mod-settable cap**, distinct from both the per-group slider (rejected) and the
    per-member slider (shipped). Edward's tracked response per Neil's summary (#410): "Yes, this can
    be done" (unspecific on timeline).

**No mod proposed a data-derived/self-calibrating mechanism themselves** — every mod proposal is
either (i) a manual dial (per-member slider, per-post flag, per-group cap) or (ii) a schedule/timing
tweak (slow the ripple down, delay first step). The "principled, data-derived, self-maintaining"
framing in the project brief is **not something any mod asked for or would recognize as satisfying
their complaint on its own** — they want a *dial*, and got a partial one (the per-member slider).
This is an important design constraint: a fully-automatic invisible parameter, however well-derived,
risks re-triggering the "we're not being listened to / not in control" complaint (#343, #400, #392)
unless it comes with a visible, explainable "why this number" artifact per area.

---

## 5. (d) What defenders/positive reports say works

Useful as calibration for "what does a good outcome look like" and to weight against the complaint
volume:

- **#17, #34, #74, #84, #107 (Jacky)**: general thanks/support, not reach-specific.
- **#42 (Jacky)**: wristwatch offer, 3 replies within 8h from Lowestoft(17mi)/Cromer(28mi)/Norwich(22mi);
  collector cycled from Norwich. Rural example where wider reach clearly helped.
- **#61 (Beth)**: rural Norfolk, routinely travels 1h+ to Norwich/Cambridge/Ely; "for me it would
  never be an extra trip."
- **#122 (Michael)**: Wellingborough reply on MK-area coffee jars post turned out to be "a few doors
  down" — the *shown* distance/group was misleading but the *actual* match was hyperlocal; used as
  evidence the ordering/relevance logic, not just the ceiling, is doing real work.
- **#199 (Diz)**: friend's chairs offer, stuck for weeks locally, rippled to a neighbouring group,
  got a reply "she's really pleased."
  #257/#256 (Edward): agreement that a specific false-positive "suspicious" flag has been fixed.
- **#254 (Jacky)**: "It's always been absurd to me too... our members are used to seeing posts from
  lots of different places... our way of working hasn't demonstrably created more freegling so I am
  hoping that rippling does."
- **#331 (Edward)**: cites member-churn data — daily departures did **not** increase post-rollout
  (graph shown, no raw numbers given in text) as evidence against "mass exodus" narrative.
- **#347 (Jos)**: "I'm very much for local but ultimately if no one local wants something it may be
  better for someone a bit further away to get a chance... Freegle is 16 years old, we can't not
  change."
- **#382-385, 397, 406, 408 (Edward, Melissa, Jax, Lorraine)**: once the **per-member distance
  slider** shipped (2026-07-01, i.e. day 11 of a 12-day-observed thread), sentiment measurably
  improves — "Brilliant - thank you" (#383), "already a happier Freegler on ChitChat" (#385), "we've
  come a long way" (#397), Lorraine's detailed positive post #406 ("together with the slider I have
  got a much busier list of items but they are closer to home"), "I'm loving the slider" (#408).
- **#411 (Diz)**: Dursley → North Bristol summerhouse furniture match, explicitly positive outcome
  from a "far" ripple.
- **#412 (Jos)**: "Thus proving you can't please everyone" — realistic closing tone.

**What "works" for defenders, distilled**: rural/low-density posters and posters whose actual
collector distance was modest even when the *displayed* group/reach looked wide. The chief lever
that visibly reduced complaint volume in-thread was not a reach-radius change but the **per-member
opt-down slider** — i.e. giving individuals a way to see they have agency over what they're shown,
even though the underlying reach-emission mechanism didn't change.

---

## 6. Quantification summary

- **414 posts**, 30 distinct human posters (+ Edward 120, Neil 8) over 12 days.
- **~10-12 distinct posters** (a third of the human population) raised genuine reach/distance
  complaints with concrete detail; **~13 distinct place-pairs** cited with a number attached.
- Of those 13, **the majority cluster in Greater London** (7 of 13: Chilterns/NW-London, Harrow,
  Croydon/K&C, Brent/Hemel Hempstead, Watford, Kensington multi-group, Portobello M8) — consistent
  with the brief's "mostly dense/urban areas" framing. The remainder are Scotland (Fife/Edinburgh, x2),
  Bristol, Thurrock/Medway, Harlow/Tower Hamlets, Hull/Castleford.
- At least **2 of the 13 cited "complaints" turned out to be bugs/artifacts on investigation**, not
  reach-radius problems: the Farnborough-Kent/Farnborough-Hampshire name confusion (#341→#346), and
  the crow-flies-vs-road-distance display (affects perception of essentially every example that cites
  a "shown distance").
- **2 experienced moderators explicitly resigned mid-thread** citing rippling (Sylvain #69, and one
  other referenced anonymously in #83) and **2 more (Sheila #400, Group-Mod-J #343) stated they were
  seriously considering quitting** by the end — these are opinion-leader losses, disproportionate to
  the raw complainant count, and Neil (Board Chair) intervened personally multiple times (#57, #349,
  #375, #380, #394, #395, #409, #410) to retain them.
- **1,525 offers/day systemwide** (per project brief) vs. **~10-12 distinct vocal reach-complainants**
  over 12 days — even generously assuming each complainant represents 50-100 silently-annoyed members,
  this is consistent with the brief's framing that reach dissatisfaction is a **real but numerically
  small, geographically concentrated (dense/urban), high-influence (moderator-level) problem** rather
  than a mass-member revolt (Edward's own churn-graph evidence in #331 supports this).
- The volume of complaint **dropped visibly after the per-member slider shipped** on day 11 — this is
  the single most effective visible intervention in the thread, even though it's a different lever
  (opt-down floor on what an individual sees) from the reach-ceiling parameter the design task is
  about.

---

## 7. Requirements the design must meet to land with this audience

Distilled from the above, not just "get the number right":

1. **Must be explainable per-area, on demand, in plain language** — not just "trust the stats"
   (#7's framing visibly backfired repeatedly; #237's wording fix worked). A mod or member asking
   "why does my post reach 32 groups?" needs an answer better than "the data says so." The existing
   ModTools "Who can see this?"/Rippling Explorer map is the right *substrate* for this but needs a
   plain-English "why this far, why not further" annotation, ideally referencing the same N*/audience
   logic the design will use.

2. **Must visibly explain the density/audience-size asymmetry** — the Swindon-vs-London complaint
   (#248/250) is the single clearest articulation of the actual problem the design is meant to solve;
   any credible design should be able to show, on the same map/UI, "reaching N active members here vs
   M there" so mods can see *why* equal drive-time isn't equal audience, rather than having to infer
   it from screenshots of the reach slider as Jos did manually.

3. **Must not re-centralize the "we have no control" grievance** — a fully automatic, invisible
   parameter (how ever well-calibrated) risks the same "not being listened to" reaction (#343, #392,
   #400) that plagued the rollout, unless paired with a visible **per-area published number** ("your
   area's typical burst size / effective ceiling is X minutes because Y") so mods can see the
   mechanism is systematic and locally-responsive, not arbitrary or opaque.

4. **Must distinguish itself clearly from the already-shipped per-member slider** — several
   complainants (#383-408) are now satisfied largely because of the member-level opt-down slider,
   which is a different mechanism from the ceiling/burst-size parameter. The design should make clear
   it is not duplicating that fix, and ideally should note that the member slider **already provides
   a release valve** that reduces the urgency/stakes of getting the systemic ceiling perfectly right
   for every individual (errors are recoverable by the user, not just centrally).

5. **Should account for or explicitly address transport-mode fairness** — several complaints
   (#157 tolls/water, #362 Melissa "without a car, entirely inaccessible", #244 Jax) are really about
   *reachability* mode (car vs public transport vs no car), which drive-time-by-road does not fully
   capture; a "genuinely too large" perception is sometimes actually "too large for me, a non-driver"
   — worth flagging as adjacent-but-distinct from the core ceiling-calibration problem, already
   partially addressed by the deprivation-index adjustment (#158) and toll/water avoidance (#158-165).

6. **Should collapse/flag motorway-corridor artifacts in the display**, independent of the ceiling
   value itself (#373) — a design that changes *how far* without also fixing *how the far reach is
   drawn/communicated* will leave this specific complaint unaddressed regardless of the calibration
   quality.

7. **Should fix (or at minimum decouple from) the crow-flies-distance display bug** (#204, #215, #217,
   #272, #274) — several "reach is too large" complaints are partly fueled by a *separate*, already
   half-acknowledged bug (displayed per-post distance is crow-flies, not road-distance), which
   inflates the sense that the system is getting geography wrong even when the underlying ripple
   mechanism is using road/drive-time correctly. This should be fixed regardless of what the new
   design does, or the new design will inherit blame for an unrelated defect.

8. **Should offer a mod-facing, not just member-facing, lever** — Jax's end-of-thread ask (#397,
   "a way for moderators to restrict certain posts to a more local area... drop down list, tick box")
   and Neville_Reid's per-post "Approve locally" idea (#304) indicate mods want *some* agency over
   individual posts even if a global per-group slider is off the table. A principled per-area ceiling
   doesn't preclude a narrow, per-post, mod-triggered override for edge cases (school-specific items,
   high-demand WANTEDs) — consider whether the design should explicitly allow (or explicitly and
   visibly reject, with reasoning) that kind of override, rather than leaving it unaddressed.

---

## Appendix: Discourse API auth note (for reproducibility)

The documented header combo (`User-Api-Key` + `User-Api-Client-Id`) returns HTTP 500 on this
Discourse instance/key. The working combo is **`User-Api-Key` header only** (confirmed against
`/session/current.json`, `/t/9808.json`, and `/t/9808/posts.json?post_ids[]=...`). This matches
prior finding in memory file `reference_discourse_api_key.md`. Full topic (414 posts) was fetched
in 20 chunks of ~20 `post_ids[]` each via `/t/9808/posts.json`.
