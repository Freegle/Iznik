# Candidate: make ChitChat useful, and tell local from national

Status: draft
Last reviewed: 2026-10-29

## Goal

ChitChat becomes the place a member goes to hear from and talk to the people around them, and
stops being a feed where a national argument about Freegle sits next to "does anyone know a
good plumber". Two parts: a review of how other platforms give members a voice locally, taken
seriously rather than copied; and a way of telling whether a post is of local interest or a
national discussion, so each goes to the people it is for. Brief from Edward, 2026-10-29. It
has to work with no community boards: locality is where the poster and the repliers are, not
which group a post was filed under.

## Why it qualifies

- Token-heavy because: a structured review of a dozen platforms with evidence of what worked and for whom; a measurement of ChitChat as it is (who posts, who replies, from how far, what dies unanswered); a local-or-national classifier built, backtested on the archive and shadow-run; the feed, the composer and the notifications changed to use it; and experiments through the UX loop to show whether any of it made the feed more useful.
- Unattended because: the review reads public material, the measurement reads our data, the classifier is backtested offline, and the product change ships behind the experiments framework; the decisions below are the only human steps.
- Worth it because: ChitChat is the one member-to-member space Freegle has beyond a chat about an item, it is where "local" would be felt if it were felt anywhere, and today its distance model is a radius computed to find ten posts, which in a quiet area reaches 128 km and puts a town's chatter in front of people who will never meet.

## What exists today

- `iznik-server-go/newsfeed/`: the feed, create, duplicate detection against the poster's own posts, and convert-to-post (`convertinfo.go`, `duplicate.go`); `GetNearbyDistance` (`newsfeed.go` line 121) computes the Nearby radius by over-fetching until it has ten candidates, bounded between a floor and 128 km, which is a density rule, not an interest rule. There is a Nearby and an Anywhere view and nothing between.
- Members' guide: ChitChat is "a community discussion feed, separate from OFFER and WANTED posts; chat about local goings-on, ask for recommendations" (`docs/members/your-account.md` line 144). That is the intent, in writing.
- Moderation of ChitChat exists (hide, report, convert to a post when someone offers an item in the feed).
- The UX telemetry covers give, ask, reply, join and settings; ChitChat has no funnel and no rollup, so nobody knows today how it is used (`docs/developers/reference/ux-telemetry.md` does not mention it).
- `plans/active/2026-09-05-chat-ai-intervention-candidates.md` is about item chats, not ChitChat; it is linked for the intervention pattern only.
- Unknown: posts a day, replies per post, median distance between poster and repliers, share of posts that are about Freegle itself or national matters, share that die with no reply. The measurement is task 1.

## The review, scoped (task 2 does it properly; this is the list to start from)

Platforms that give people a local voice, each with a different answer to "what is local" and
"who decides what I see", to be read for evidence, not copied:

- Front Porch Forum (Vermont): one daily digest per neighbourhood, every post human-moderated, no algorithm, strong on recommendations and lost-and-found, famously civil; the evidence is in its retention and in independent studies of it.
- Nextdoor: address-verified neighbourhoods with a radius the member can widen, topic channels, and a well-documented problem with hostility and surveillance posts; what the radius control did and did not fix.
- Reddit's local subreddits and Facebook groups: scale and discovery by place name, moderation by volunteers per group, which is the model we are moving away from, so what they show about volunteer moderator load matters.
- Mastodon and Bluesky local timelines and feeds: locality by instance or by feed generator, opt-in, no verification.
- Civic participation platforms (Decidim, Consul, Pol.is): structured voice, proposals and opinion mapping rather than a feed; relevant to the "national discussion" half, where the question is how members talk about Freegle itself.
- Freegle's own history: the group ChitChat of the mailing-list era, and what moderators say about ChitChat now on Discourse.

What the review has to answer: which of these made people more likely to help a neighbour,
which made them leave, what the locality rule was in each, and what moderation it needed.

## Local or national: the classifier

A post is local when it is about a place or for the people near one; national when it is about
Freegle, the country, or anything whose audience does not depend on where you live. Signals, in
the order they are cheap:

1. **Named places** in the text, resolved against the locations table (a street, a town, a postcode district): a strong local signal.
2. **Topic**: recommendations, lost and found, events, roadworks, weather, local news are local; Freegle policy, the app, national news, politics, charity-wide appeals are national. A small model call, with the reason kept.
3. **Who replies and from how far**: once a post has replies, the median distance of repliers from the poster is the truth the first two signals predict; it is also the training signal for the backtest.
4. **The poster's say**: the composer suggests "Near you" or "Freegle-wide" from signals 1 and 2 and lets the poster change it; a changed suggestion is logged and feeds the model.

What it drives: a local post goes to the people within a travel-time area of the poster (the
same isochrone machinery as browse and the ModTools work list), not to a radius inflated until
ten posts are found; a national post goes to a Freegle-wide view that every member can open and
nobody is shown by default; notifications follow the same split; a Freegle-wide post about
Freegle itself can be pointed at the volunteers' channel where that is where it belongs.

## Decisions settled

- **Locality is a travel-time area around the poster, not a density radius.** The ten-post rule exists to fill a screen; it should be replaced by "near you, however few" with "further away" as an explicit step, so a quiet area sees its own few posts first.
- **Two views, one default.** Near you is the default; Freegle-wide is one tap away and never silently mixed in.
- **The poster decides, the model suggests.** No post is reclassified against the poster's choice; a wrong suggestion is a measured error.
- **Measured through the experiments loop.** Each change ships as an experiment with a stated outcome (replies per post, time to first reply, posts that die, members returning to ChitChat) and is kept or dropped on the evidence, as the UX framework requires.
- **No community boards and no per-community settings.** Compatible with the no-communities experiment by construction.

## Open questions for Edward

- What is ChitChat for, in one sentence, so the review and the measures have a target? Recommended: "where members help and hear from the people near them", with Freegle-wide discussion a secondary view.
- Default travel-time band for "near you"? Recommended: the same default as the ModTools work list, 30 minutes driving, with the member able to widen.
- Does a national post about Freegle itself get redirected to Discourse, or stay in Freegle-wide? Recommended: stay, with a one-line note and link where a volunteer answer exists; members are not volunteers.
- Which outcome decides keep or drop for the first experiment? Recommended: share of posts with a reply within 24 hours, from someone within the band.

## External prerequisites

- [ ] Nothing outside the repo; the review reads public material.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Measure ChitChat as it is: posts, replies, distances, topics, deaths, by area density; a telemetry funnel and rollup for it | - | A | iznik-batch, iznik-server-go/ux, docs |
| 2 | The platform review: evidence per platform on locality rule, moderation load, and what made people help or leave; a written report | - | B | docs |
| 3 | Classifier: place resolution, topic model with reasons, reply-distance truth, backtest on the archive, shadow run; the composer suggestion and the log of changes | 1 | C | iznik-server-go/newsfeed, iznik-batch |
| 4 | Feed and notifications on the travel-time area: Near you by isochrone, Freegle-wide view, notification split; behind an experiment switch | 3 | D | iznik-server-go/newsfeed, iznik-nuxt3, app |
| 5 | Experiments: the first experiment on the outcome agreed, then the next from the review's findings; member guide updated | 4, 2 | A | ux experiments, docs/members |

## Acceptance

- The measurement report answers the unknowns above with numbers, and a ChitChat funnel appears in the UX telemetry.
- The review report says, per platform, what the locality rule was, what it needed in moderation, and what the evidence says it did to helping and to leaving, with sources.
- The classifier's suggestion agrees with reply distance on the backtest at a stated rate, and the composer shows the suggestion with a one-tap change.
- With the experiment on, a member in a quiet area sees their own area's posts first, however few, and Freegle-wide is one tap away; notifications follow.
- The first experiment reports its outcome through the framework and is kept or dropped on it.

## Review burden

- PRs: 6 to 8
- Each UI PR carries: screenshots or a walkthrough video of the composer suggestion and the two views, on a phone
- Each migration PR carries: raw production SQL in the description (classification and suggestion-change columns on newsfeed)
- Docs page(s) to add or update, with `covers:`: a developer reference page for ChitChat (there is none), the members' guide, the telemetry page

## Out of scope

- Item chats and the chat intervention candidates.
- Moderation tooling for ChitChat beyond what the split needs.
- Any per-community setting or board.

## Related plans

- `plans/token-burn/modtools-by-role.md` - shares the travel-time area machinery.
- `plans/active/2026-09-05-chat-ai-intervention-candidates.md` - the intervention pattern, for item chats.
- `plans/token-burn/discourse-reply-bot.md` - where a Freegle-wide post about Freegle may be answered from.
