# Community Reuse Outreach — Methodology & Implementation Plan

**Author:** Edward (with Claude)
**Date:** 2026-04-22
**Status:** Methodology piloted on Mind in Brighton & Hove clearance (March/April 2026). Technical design is forward-looking.
**Related:**
- Reusefully debrief (2026-04-22) — Action 3 "AI-generated local-organisation shortlist ... cold; compare with actual repliers" is what this document operationalises.
- `plans/active/freegle-helper-concierge.md` — Freegle Aimee FSM (what happens *after* an org replies).

---

## Part 1 — Narrative: what was done and why

### The problem

When an organisation donates a large batch of items (Mind Brighton: ~90 rows of furniture), the task is to find takers who will actually show up and use the items in situ, rather than a warehouse operator who will take the lot and on-sell. Freegle's usual model — post, wait for replies — produces inbox chaos at this volume, and skews toward individuals. What we want is *proactive, targeted outreach to specific organisations*, before or alongside the public posts.

Previously, when running these clearances, we've essentially done that sourcing by hand and by memory. The question this work answers is: **can we build a repeatable method that a facilitator (or eventually Freegle Aimee) runs for every clearance?**

### The two-tier approach

The first insight is that "organisations who might want this" splits cleanly into two populations with very different economics:

**Tier 1 — known-interested.** Furniture Reuse Network members, Men's Sheds, scrapstores, tool libraries, starter-pack charities for people leaving homelessness or refuges, Emmaus-style communities, hospice-warehouse operations. These organisations *exist to take donated goods*. They will respond. Expected conversion: 40–60%. The population is small and finite — ten to twenty in any given town. You contact them all, warmly, with the full list.

**Tier 2 — speculative.** Charities, community groups, and small firms who would *plausibly* benefit but have no evidenced history of taking bulk reuse. Community centres needing more stacking chairs. Refugee drop-ins needing waiting-room furniture. Scout groups needing storage. The population is large (hundreds), the hit rate is low (2–10%), but at scale it's worth doing because it finds takers who wouldn't self-identify on a generic post. Each organisation gets a single email offering one specific cluster, never the whole list.

This split changes the pipeline: Tier 1 is exhaustive, Tier 2 is filtered. Tier 1 goes first, and whatever they take comes off the list before Tier 2 runs, to avoid the "promised to two people" failure the Mind debrief flagged.

### Why we can't use the obvious data sources

The Charity Commission register and Companies House are tempting — structured, comprehensive, authoritative. They are also close to useless for this purpose, because:

- **Convenience addresses.** A national charity registered at an accountant's office in Hove doesn't operate in Hove. The address is where post is sent, nothing more.
- **Dormant entities.** Many CICs and small charities exist on paper and nowhere else. Their last news update was 2018. Emailing them is spam against a graveyard.
- **Mission vs. activity.** A charity's object clause tells you what they *could* do, not what they're *actually doing this month*.

So we replaced registered-address-as-evidence with **activity evidence** as a hard gate. For an organisation to make the shortlist:

1. There must exist a specific dated URL within the last 12 months showing they're doing something in Brighton & Hove — a news post, a social post, an event listing, a recent job ad, a dated website update.
2. They must have a public email on their website or social profiles (not a contact form, not a phone number only).
3. They must be a real operating presence in the target geography, not just a registered address.

Registers are then used in *reverse* — once a candidate is found via activity signals, the register confirms it's a real legal entity.

### What we offer each organisation

Before the outreach can work, the item list needs structure. We clustered Mind's 90 rows into eight functional groups — office setup, hall seating, waiting/lounge, events tables, storage, kitchen, bulk stationery, fixtures. Each cluster maps to a characteristic organisation type (hall seating → churches/halls/scouts; kitchen fridge → food banks). This clustering is what lets us match and, importantly, what lets us send a cold Tier 2 email containing one cluster rather than 90 rows — which would be a commitment-repellent.

### What was actually executed in this session

Twelve research agents ran in parallel, each focused on one organisational category in Brighton & Hove:

1. Homelessness, housing, resettlement, refuges, care leavers
2. Refugee, asylum, migrant support
3. Community centres and halls
4. Faith communities running community programmes
5. Food banks, pantries, community kitchens, cafes
6. Mental health peer support and day centres
7. Disability, sensory, learning disability, neurodivergence
8. Youth organisations (scouts, guides, cadets, youth clubs)
9. Older-people services
10. Furniture reuse, Men's Sheds, repair (Tier 1)
11. Schools, nurseries, PTAs
12. Small charities, CICs, maker spaces

Each agent received the same brief: the item clusters, the hard gates, the requirement to use Google Search and the organisation's own site/social for activity evidence (not register-as-evidence), and the instruction to include both the shortlist and the *rejected* candidates with reasons — so the audit trail is visible and the false-negative analysis (Section 2.7 below) can run.

Each agent wrote results to a markdown file in `/tmp/mind-brighton-shortlist/`. An aggregator builds a single `cold-shortlist.xlsx` with two sheets:

- **Included** — organisations passing all gates. Columns: Category, Tier, Name, Website, Email, Postcode/Area, Activity evidence (URL + date), Item cluster fit, Rationale, Agent confidence (low/med/high), Priority score.
- **Rejected** — organisations considered and dropped, with reason. Used for audit and for the false-negative analysis when we validate against the March repliers.

### How to use the output

1. **Review and prioritise.** The xlsx is a candidate list, not a send list. A human reviews and marks "send / skip" per row. This is deliberately manual — the whole session is stakeable on one bad email going out, so there's a human gate.
2. **Tier 1 first.** Contact all Tier 1 candidates. Let them take what they want. Update the "still available" inventory.
3. **Tier 2 next.** One email per organisation, offering only the strongest-fit cluster of what remains. No follow-up unless they reply. No mail-merge wording — each email names specific items the organisation can actually use.
4. **Validate against the March repliers.** The purpose of running this *now*, three weeks after the Mind clearance ran manually, is to compare: who did the method find cold that also replied in March? Who did it miss? Who did it find that no-one thought of? The false-negative list tells us which data sources we need to add before the method goes live on the next clearance.
5. **Feed back into Tier 1.** Organisations who respond well become Tier-1 members for future clearances. Tier 1 grows over time.

### What the method does not do

- It does not send outreach. That's a human decision per row, every time, until we have strong evidence the shortlist is reliably clean.
- It does not judge Defra's waste test (reuse intent, same purpose, minor repair). That's a human call on the reply, not on the candidate.
- It does not replace a public Freegle post. It complements it.
- It does not contact individuals, only organisations.

---

## Part 2 — Technical design: implementing this at scale

### 2.1 Goals

**Primary:** given (a) an item list and (b) a target geography, produce a scored shortlist of candidate recipient organisations with published emails and activity evidence, plus an audit trail of rejected candidates.

**Secondary:**
- Learn across clearances — Tier-1 membership accumulates based on response history.
- Validate precision/recall against the outcome ledger after each clearance.
- Integrate with Freegle Aimee so a cold-shortlisted org that replies becomes a tracked chat with the same FSM as a public-post reply.

**Non-goals:**
- Automated sending.
- Replacing public posts.
- Operating outside the UK.
- Matching to individuals.

### 2.2 Data model

```
Clearance
  id, donor, geography (polygon or postcode set), collection_window,
  item_clusters[] → (cluster_id, label, items[], photos[])
  status

Organisation
  id, name, website, legal_type, legal_id (charity_no/CIC_no if known),
  operational_postcode, operational_area_polygon,
  tier (1|2), tier_1_source[] (reuse_network|mens_sheds|freegle_history|operator_network|response_history),
  emails[] (address, role, confidence, last_verified),
  activity_signals[] (url, date, channel, one_line_summary, collected_at),
  activity_last_seen (max date across signals),
  missions[] (tag from controlled vocabulary: homelessness, refugee, mental_health, etc.),
  item_cluster_affinities[] (cluster_type → weight 0..1),
  rejected_reason? (if on reject list)

OutreachRound
  id, clearance_id, organisation_id, tier, cluster_offered,
  draft_subject, draft_body, photos_used[],
  human_decision (pending|send|skip), human_decision_by, human_decision_at,
  sent_at?, sent_via (email|freegle_chat),
  first_reply_at?, outcome (took_items|declined|no_response|signposted),
  items_taken[] (item_ids from clearance),
  collection_confirmed_at?,
  notes

ValidationRun
  id, clearance_id, run_at,
  shortlist_size, actual_repliers[],
  true_positives[], false_positives[], false_negatives[], novel_tp[],
  source_gaps[] (data sources that would have caught false negatives),
  precision, recall
```

Organisations are reused across clearances; `OutreachRound` is clearance-scoped.

### 2.3 Pipeline stages

```
                 ┌─────────────────────────┐
                 │ 1. Item clustering      │  item list → clusters
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 2. Tier-1 harvest       │  static directories + response history
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 3. Tier-2 discovery     │  category-parallel search
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 4. Activity verify      │  per-candidate dated URL
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 5. Email extraction     │  from site + social
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 6. Match & score        │  cluster ↔ org, 1..12 score
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 7. Draft outreach       │  per-row email draft
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 8. Human gate           │  send / skip / edit
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 9. Dispatch + track     │  via Freegle Aimee / SMTP
                 └────────────┬────────────┘
                              │
                 ┌────────────▼────────────┐
                 │ 10. Validation run      │  compare to outcomes
                 └─────────────────────────┘
```

### 2.4 Tier-1 sources (exhaustive, structured)

Each source is scraped/queried and cached, with freshness checks weekly-to-monthly:

| Source | Notes |
|---|---|
| Reuse Network member directory | Authoritative for furniture reuse operators. |
| UK Men's Sheds Association map | Contains dormant sheds — must cross-check activity. |
| Library of Things / Share UK / repaircafe.org directories | |
| Freegle internal: previous bulk takers in the area | Query against `messages_outcomes` + `chat_messages` where replier collected ≥ N items in one chat. |
| Reusefully operator network | Private list — supplied by Natalie per clearance. |
| Historical response history (this system) | Any organisation that ever took items in a previous clearance. Promotes to Tier 1 automatically after N successful collections. |

### 2.5 Tier-2 discovery (category-parallel)

One discovery worker per category (homelessness, refugee, community-centre, faith, food, mental-health, disability, youth, older-people, schools, small-charities, makers). Each worker:

1. Runs a set of seeded queries (`"community centre" Brighton`, `"refugee drop-in" Hove`, etc.) against Google Custom Search and Bing Web Search APIs.
2. For Brighton & Hove specifically, queries the Community Works directory and the BHCC community directory — not as evidence sources, but as *candidate sources*. Every candidate still needs activity evidence before it passes the gate.
3. Fetches each candidate's website and at least one social profile (Facebook is the dominant channel for community orgs; also Instagram, X, LinkedIn).
4. Extracts the most recent dated content (`<time>` tags, blog post dates, "latest news" sections, social post dates).
5. Emits candidates with evidence URL + date + channel.

Discovery workers are stateless and cheap. They can be re-run per clearance with an updated geography.

### 2.6 Activity-verification gate

Given a candidate URL set, for each candidate:

- Parse dates from up to three sources (website news page, Facebook page, website footer/metadata).
- Take the maximum date.
- Gate at `now − 12 months`. Configurable per clearance.
- If no parseable date found but the page has content that references a year within the last 24 months in prose, accept with a low-confidence flag.
- Archive every evidence URL to `archive.org` at the moment of checking, so the audit trail survives site takedown.

### 2.7 Email extraction

- Scrape `<a href="mailto:...">` from homepage, contact page, about page, team page, impressum.
- Scrape plain-text email patterns (de-obfuscating `[at]`, `(at)`, images-of-email are beyond scope for now).
- Scrape the organisation's Facebook "About" → email field (public API or scrape).
- **Normalise:** lowercase, strip query fragments, deduplicate.
- **Classify role:** `info@`, `admin@`, `office@` → generic; named inbox → personal. Personal is lower confidence for cold-emailing (PECR + politeness).
- **Reject:** generic contact forms, phone-only listings, `noreply@`, addresses that 550-bounce on a prior run.

### 2.8 Scoring

Four 1–3 axes, total 4–12:

- **Fit.** How strongly does the cluster serve the organisation's mission? 3 = stated service need (e.g. a refugee drop-in needing waiting-room chairs). 2 = general infrastructure fit. 1 = tangential.
- **Capacity.** Can they physically collect and store this scale? 3 = own premises + transport. 2 = premises only. 1 = neither (they'd need help).
- **Recency.** How fresh is the activity signal? 3 = within last month. 2 = within last 6 months. 1 = within last 12 months.
- **Reachability.** Email quality. 3 = dedicated contact role on website. 2 = generic inbox. 1 = Facebook-only contact.

Scores are computed by a scoring model (LLM with a strict rubric and explanation required) but are human-overridable. The model must cite the evidence for each score.

Tier-1 candidates bypass Fit scoring — they're in Tier 1 precisely because fit is assumed.

### 2.9 Outreach drafting

- **Subject:** names 1–3 specific items. Not generic.
- **Body:** 3 sentences: donor context; why this organisation specifically; item cluster with 1–2 photos as attachments or inline; single CTA ("reply with which items you'd use, and a preferred time").
- **Signature:** facilitator, not a bot. Reply-to must land in a human inbox (or Freegle Aimee's mailbox).
- **Photos:** chosen from the clearance's photo pack by cluster — not the whole album.
- **PECR check:** log that we relied on legitimate-interest (LI) with a published-contact-address basis. Do not mail-merge blast. Each email is individually reviewed.

### 2.10 Human gate

Every draft sits in a queue. The operator dashboard shows:

- Row metadata (org, score, evidence URL, cluster, draft).
- Quick actions: Send / Skip / Edit / Defer.
- Bulk actions disabled for sends > 5.
- Rate-limit: no more than N sends per hour per clearance (default 20).

This is deliberately sticky — per the Mind debrief, the failure mode of AI outreach is promising items twice. A human rate-limit is the cheapest defence.

### 2.11 Integration with Freegle Aimee

When an organisation replies to an outreach email, the reply is ingested into a Freegle Aimee chat (new chat thread, same FSM as a public-post reply). The organisation becomes a pseudo-Freegle-user (internal user with `source=cold_outreach`). Subsequent state — promising items, collection confirmation, no-show handling — uses the existing FSM. This is the bridge that stops cold-outreach replies living in a separate ops spreadsheet.

The handover step is:
```
IncomingEmail(In-Reply-To = OutreachRound.id)
  → create/update ChatRoom
  → set Chat.context.cold_outreach = OutreachRound.id
  → mark OutreachRound.first_reply_at
  → Aimee reads Chat with full clearance item list + what was offered
```

### 2.12 Validation loop

After each clearance:

1. Pull final outcomes from Freegle (`messages_outcomes` + `chat_messages`): who actually collected what.
2. Cross-reference against the shortlist:
   - **TP** — on shortlist, collected.
   - **FP** — on shortlist, contacted, declined or didn't respond.
   - **FN** — not on shortlist, collected. Trace: which data source would have surfaced this org? Log as a source gap.
   - **Novel TP** — on shortlist, contacted, collected, and not on any previous clearance. Promote to Tier 1.
3. Compute precision (`TP / (TP + FP)`) and recall (`TP / (TP + FN)`).
4. The source-gap report drives the next iteration of Tier-2 discovery — if three consecutive clearances show FNs that only appear on a specific Facebook group, add that group as a discovery source.

Target trajectory: precision should improve over time; recall should rise as we add missed sources.

### 2.13 Observability

Every pipeline stage emits structured logs with `clearance_id`, `organisation_id`, `stage`, `decision`, `evidence_url`. These feed a dashboard showing:

- Funnel: candidates → verified → emailed → replied → collected.
- Per-category performance.
- Per-source performance (which discovery source produced the most TPs per clearance).
- Rejected-reason distribution.

### 2.14 Privacy, PECR, reputation

- **Legitimate interest only.** Organisations with a published address have an implicit acceptance of unsolicited business contact. Individuals do not — cold outreach to personal-name inboxes is out of scope.
- **Unsubscribe per organisation.** If an org asks to be removed, they go on a suppression list that persists across clearances.
- **Rate limiting.** Cross-clearance — no organisation receives more than one outreach email per 6 months from this system, unless they opted in.
- **Attribution.** Outreach is signed by the facilitator (Reusefully or Freegle operator), not by "AI". Avoids mistrust and matches human responsibility for the content.
- **Archive evidence.** Every shortlist row carries its evidence URL *with an archive.org snapshot* — so if challenged, we can show why the organisation was included.

### 2.15 What's built vs what's next

**Built in this session (as a one-off):**
- Item clustering for Mind list (manual).
- 12 parallel research agents for Brighton & Hove across all categories.
- Output aggregation to xlsx with rejected audit.
- Scoring via agent confidence (proxy only — not the full 1–12 rubric).

**Next steps to productionise:**
1. Harden discovery workers as long-running services, cached and re-runnable.
2. Build the validation loop against Mind clearance outcomes — this is the most valuable near-term experiment.
3. Implement the Freegle Aimee handover path.
4. Build the operator dashboard with the Send / Skip / Edit queue.
5. Add a Tier-1 promotion mechanism based on response history.
6. Extend beyond Brighton & Hove — geography becomes a first-class parameter.
7. Per-item CO₂/weight estimates, per Reusefully debrief Action 2. Not critical for outreach but relevant to the impact report this feeds into.

### 2.16 Open questions

- **Geography boundary.** Brighton & Hove is clean; other LAs (rural districts, large councils) are messier. Should geography be LA-based, postcode-sector-based, or polygon-based?
- **Tier promotion threshold.** How many successful collections does it take to promote an org to Tier 1? Provisional: 2 within 18 months.
- **False-positive cost.** One bad email to a dormant charity is low-cost. One bad email to a corporate comms team that flags it as spam is higher-cost. Should the operator dashboard reflect reputational risk per org type?
- **Multi-clearance simultaneous matching.** If two clearances run in the same week with overlapping candidates, which gets priority? Probably first-come-first-served, but worth thinking through.
- **Cross-posting interaction.** The existing multi-group-posts work changes how public posts spread. Does cold outreach replace cross-posting, supplement it, or is it completely orthogonal? Current assumption: orthogonal — public post goes to all matching Freegle groups; cold outreach hits orgs that aren't on Freegle.

---

## Appendix A — Hard gates, reference card

An organisation enters the shortlist if and only if:

1. **Activity:** one specific dated URL within the last 12 months showing operations in the target geography.
2. **Email:** a published email address (website or social "about"), not a contact form, not phone-only.
3. **Reality:** evidence of genuine operations in the target geography — not a registered address for an entity operating elsewhere.

Sources that can provide activity evidence: Google Search hits, organisation's own website with a dated page/post, Facebook/Instagram/X/LinkedIn dated posts, local news archives (Brighton & Hove News, The Argus, Brighton Independent, Sussex Bylines), local-CVS event listings, job ads with a date.

Sources that **cannot** provide activity evidence (but can confirm legal status once a candidate is otherwise found): Charity Commission register, Companies House, CIC regulator, old directories, GOV.UK "Get Information About Schools".

## Appendix B — File and directory layout used in this session

```
/tmp/mind-brighton-shortlist/
  01-homelessness.md           ← per-category agent output
  02-refugee.md
  ...
  12-small-charities-maker.md
  cold-shortlist.xlsx          ← aggregated deliverable

Agent prompt template lives in this plan document, section 2.5.
```

## Appendix C — Clusters used for the Mind list

| Cluster | Item examples | Typical organisational fit |
|---|---|---|
| Office setup | Ergonomic desks, plain desks, under-desk drawers, cabinets | Small charities/CICs moving or expanding, refugee support offices, disability advice centres |
| Hall seating | Stackable chairs (metal, plastic, wooden) | Churches, community halls, scout huts, schools, sports clubs |
| Waiting / lounge | Armchairs with arms, sofa, low table | Care homes, day centres, drop-ins, GP waiting rooms, refugee drop-ins |
| Events tables | Trestle tables, circle tables, corner table | Markets, fêtes, community meals, scout groups |
| Storage | Lockable cabinets, small cupboard, bookcase | Schools, youth clubs, tool libraries, book swaps |
| Kitchen | Fridge (excellent) | Food banks, community kitchens, pantries, community cafes |
| Bulk stationery | 3,000 window envelopes | Mail-fundraising charities, parish offices, membership orgs |
| Fixtures | Wall clocks, step ladder | Any community building furnishing a space |

Each cluster maps to a characteristic outreach message; no message offers more than one cluster to a Tier-2 recipient.
