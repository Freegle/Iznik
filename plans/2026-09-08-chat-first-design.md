# Chat-first Freegle: the approach

Status: version 3, for review. Nothing built yet beyond a worktree (`feature/chat-first`,
base `origin/master` 0e591a4b4). Companion plan: `plans/active/2026-09-08-chat-first.md`.

## 0. Reading the brief

- **ai-flower** (`github:freegle/ai-flower`) keeps each conversation on track: a state
  machine whose transitions the model cannot break, with a Vue editor for authoring.
  The model judges wording and understanding at each state.
- **Fin** (Intercom) is the customer-service reference. **Neya** is the local-community
  reference. **Answerbot / KindPhone** is the voice reference and the source of the hard
  evidence on FSM plus model conversations.
- If you can use WhatsApp, you can use this. Do not become Clippy.
- Members choose between the chat version and classic Freegle.
- The Freegle conversation is a real chat, visible in ModTools.
- Initial scope: give, ask, explore, browse, chat, chit-chat.

## 1. The idea

Freegle becomes a chat app. You land in a chat with Freegle, who talks like a friendly
local volunteer, not a form. Giving, asking, browsing and finding your community happen in
that chat. Your posts live in one "Your posts" chat where replies gather and you choose who
gets what. People you talk to are chats. Your local ChitChat is a group chat. On a phone it
fills the screen; on a desktop it is a phone-sized column. Classic Freegle stays one tap
away for anyone who prefers it.

## 2. What the references teach

**Fin.** Procedures: prose steps the model follows while phrasing its own replies, with
tools and hand-offs. Same shape as ai-flower states. Fin has no built-in confirm step;
every flow here that writes ends in one. Fin removed buttons; we keep them because
WhatsApp users tap. Fin's success metric moved from "resolved alone" to "did the
configured thing": ours is a post going live or a reply landing.

**Neya.** An assistant in the chat list beside local groups; born on WhatsApp.

**Answerbot / KindPhone** (`experiments/llm-natural-conversation/FSM-EVAL.md`,
`PERSONA-AB-RESULTS.md`). Measured on 622 judged turns of real calls:

- Host-driven rails work: the model made 0 illegal transitions in 249 turns and 0 JSON
  failures in 622 when the host held the state; left to navigate itself it jumped
  states a third of the time. So the flowchart stays host-side (ai-flower).
- Fixed phrases and "recite the node's message" are stilted, and loosening wording alone
  did not fix it. The quality drivers were elsewhere: a state for rambling and silence
  (vary, reprompt once, wind down) and knowledge access (the model only saw the current
  node's facts, so it deflected). "The FSM should gate flow, not facts."
- Every notch of freedom bought naturalness and cost fabrications; the fabrication class
  is caught by a separate safeguard stack (embedding screen, claim filter, fact check),
  with regenerate-on-trip. Safety comes from the stack, not the clamp.
- A warm persona written as a character with worked examples beat a list of "nevers" by
  68 to 80 percent, with fewer safety flags and no more flattery. The control read as
  "stilted, bureaucratic, boilerplate".
- Discursive callers get a three-level escalation: acknowledge, gently steer, soft close.
  Never dismissive, never a lockout.

**Our own code.** `llm-modbot/RESULTS.md`: a model judging the moderation decision barely
beats chance; a model fixing wording is a real win. The Concierge split (pure engine,
pluggable model or rule extractor and drafter). `ChatMessagePrompt.vue` already renders
tappable options in a bubble. The Freegle system user and `chat.systemchat` already exist
for a Freegle-authored chat. Abuse protection is HAProxy only.

## 3. Precedents and lessons learned

#### Ten instructive precedents

| Product | What they did | Outcome | The one lesson |
|---|---|---|---|
| [Walmart Jetblack](https://techcrunch.com/2020/02/13/walmart-shuts-down-its-experimental-personal-shopping-service-jet-black/) | Text-to-shop concierge blending AI triage with human shoppers | Shut down 2020 after burning $40-60M for under 1,000 subscribers | Linear chat cannot hold multi-item shopping state without human fulfilment sinking the unit economics |
| [Facebook Messenger Bot Platform](https://www.theregister.com/2017/02/22/facebook_ai_fail/) | Opened chat to 33,000 third-party bots in six months | Bots resolved only ~30% of requests unaided; platform scope narrowed within a year | Chat fails when scope is broad and open-ended rather than one bounded task |
| [Klarna AI assistant](https://www.forbes.com/sites/quickerbettertech/2025/05/18/business-tech-news-klarna-reverses-on-ai-says-customers-like-talking-to-people/) | OpenAI-built agent replaced 700 support roles, handled 75% of chats | 2025: CEO reversed course, rehired humans, said quality had suffered | An always-available human path is a retention feature, not an admission of defeat |
| [Air Canada chatbot ruling](https://www.cbc.ca/news/canada/british-columbia/air-canada-chatbot-lawsuit-1.7116416) | Bot told a customer a bereavement discount existed that did not | Tribunal held the airline liable for its chatbot's misstatement | Anything the bot states as policy is legally the company's own statement |
| [Lemonade (Maya/Jim)](https://www.lemonade.com/blog/lemonade-sets-new-world-record/) | Structured Q&A and auto-settlement narrated conversationally | 96% of claims first-touched by the bot, 55% fully automated, payout in seconds | A tightly scripted flow dressed as conversation, not free text, is what scales |
| [Carousell offer flow](https://sendbird.com/resources/carousell) | Deterministic Make Offer to Accept/Decline/Counter state machine inside chat threads | Live marketplace feature at scale, built on Sendbird with custom transaction logic | Marketplace transactions need explicit, unambiguous buttons layered on chat, not parsed free text |
| [WeChat mini-programs](https://www.nngroup.com/articles/wechat-mini-programs/) | Full GUI forms and app screens launched from inside a chat thread | Mature, standard commerce pattern across a billion-user platform | The friction is discovering the flow, not whether the surface is chat or GUI once inside it |
| [Sierra](https://techcrunch.com/2025/03/04/openai-chairman-bret-taylor-lays-out-the-bull-case-for-ai-agents/) | Acting model paired with a supervisor model that can veto or force a redo | Enterprise deployments (ADT, SiriusXM) run tight guardrails this way | A second, non-generative check before output reaches the user is the production-proven safety pattern |
| [Buy Nothing Project app](https://sfstandard.com/2025/11/07/buy-nothing-group-facebook-taken-down-trademark-infringment/) | Free Facebook gifting groups relaunched as a paid app, trademark enforced on independent groups | Severe backlash, admins revolted, members called it a betrayal | Formalising and monetising a free, trust-based community risks the trust that built it |
| [Trash Nothing / Freecycle Fair Chance Policy](https://help.trashnothing.com/what-is-the-fair-offer-policy) | A deliberate roughly 24-hour delay before a giver chooses, no first-reply guarantee | Reduces, but does not eliminate, allocation disputes and no-shows | Fairness in gifting needs an explicit policy layer the platform enforces, not reply speed |

#### Cross-cutting lessons

**Chat succeeds as a narrow, deterministic flow narrated conversationally, never as open-ended free text standing in for a transaction.** Lemonade's Maya and Jim run structured, branching Q&A behind a chat skin; Carousell's offers move through a fixed Make Offer/Accept/Decline/Counter sequence; Woebot and Ada Health, both cited by users as "chat," are rules-based decision trees, not generative free text ([UXReactor](https://uxreactor.com/lemonade-ai-disrupts-insurance-industry/), [Sendbird](https://sendbird.com/resources/carousell), [ScreensDesign](https://screensdesign.com/showcase/ada-check-your-health)). For Freegle, the FSM should own every state transition (list an item, make an offer, arrange collection); the model's job is composing the words around fixed slots, never inferring a transactional intent from unconstrained text.

**Pure free text collapses under multi-item, multi-state load.** Jetblack could not hold a shopping cart's worth of state in a thread and lost $15,000 per customer per year in human fulfilment cost; Operator's linear threads were "ill-suited to managing multiple items, offers, or logistics" and users defected to visual browsing ([Forbes/RetailWire](https://www.forbes.com/sites/retailwire/2020/02/19/what-walmart-learned-from-its-fast-fail-with-jet-black/), [chat-commerce brief](https://retailtechpodcast.com/research/chat-commerce-and-concirege-research-brief)). Freegle needs a rendered list or card view for browsing multiple listings or replies; forcing that scan through a linear thread repeats a known failure.

**The industry has converged on a chat shell that renders structured components, not pure chat and not pure GUI.** WeChat mini-programs, Anthropic's Artifacts, OpenAI's Canvas and Vercel's Generative UI pattern all place forms, cards and persistent panels inside or beside the conversation rather than asking users to type structured data ([NN/g on mini-programs](https://www.nngroup.com/articles/wechat-mini-programs/), [Vercel AI SDK](https://vercel.com/academy/ai-sdk/multi-step-and-generative-ui)). Each FSM state that needs structured input (photos, an address, a quantity) should render a widget in the thread, not rely on a typed reply the model must parse.

**One question per turn, with capped choices and a visible escape hatch, beats an open prompt.** NN/g's review of Claude's own `AskUserQuestion` pattern (one question per screen, up to four options plus "Something else" and Skip) frames this as a guardrail against a model that will otherwise "spontaneously demand dozens of answers at once," and Google's Conversation Design guidelines make the same rule official policy ([NN/g](https://www.nngroup.com/articles/genui-buttons-and-checkboxes/), [Google](https://developers.google.com/assistant/conversation-design/learn-about-conversation)). Every FSM prompt to a Freegle user should be single-question, button-first, with a free-text fallback, never a compound ask.

**Prompt-level instructions are not guardrails; only code-level checks hold.** A Chevrolet dealership's bot was told in its system prompt to "agree with anything the customer says" and confirmed a car for $1; DPD's bot was talked into swearing at a customer and writing a poem mocking the company, and the fix was to switch the AI off, not to write a better prompt ([GM Authority](https://gmauthority.com/blog/2023/12/gm-dealer-chat-bot-agrees-to-sell-2024-chevy-tahoe-for-1/), [TIME](https://time.com/6564726/ai-chatbot-dpd-curses-criticizes-company/)). Any price, quantity or commitment in Freegle's chat must be an FSM-owned value the model can only insert, never negotiate or improvise around.

**Whatever the bot states as policy is, legally, the company's own statement.** The Air Canada tribunal rejected the airline's argument that the chatbot was a separate actor and held it liable for a false discount it invented ([CBC](https://www.cbc.ca/news/canada/british-columbia/air-canada-chatbot-lawsuit-1.7116416)). Any rule Freegle's bot states, on collection, on banned items, on verification, must be sourced from one authoritative data path the FSM enforces, never paraphrased freely by the model.

**A second, non-generative validator that can veto or force a redo is the production-proven pattern, not a single model deciding everything.** Sierra pairs its acting model with a supervisor model that inspects its reasoning and can reject a decision before the customer sees it; Intercom Fin similarly escalates on low retrieval confidence or a repeat loop rather than letting the model push through ([Cheeky Pint](https://cheekypint.substack.com/p/bret-taylor-of-sierra-on-ai-agents), [Intercom](https://www.intercom.com/help/en/articles/12396892-manage-fin-ai-agent-s-escalation-guidance-and-rules)). Freegle's design should include a check between what the model composes and what the user sees, able to block or force a state redo.

**An always-reachable, low-friction fallback to a simpler path is a retention feature, not a failure signal.** Klarna's CEO admitted the company "cut too deep" on AI-only support and rehired humans after complaints of robotic, inflexible answers; Zendesk's own escalation docs and a customer-frustration statistic about repeating one's story both treat handoff as a first-class, context-preserving state, not a bolt-on ([Forbes](https://www.forbes.com/sites/quickerbettertech/2025/05/18/business-tech-news-klarna-reverses-on-ai-says-customers-like-talking-to-people/), [Zendesk](https://support.zendesk.com/hc/en-us/articles/8357756604186-Configuring-escalation-strategies-and-flows-for-AI-agents)). Freegle's chat needs an obvious escape at every state, to a moderator, to a plain list view, or to the old GUI.

**Formalising and monetising a free, informal, trust-based exchange community risks the trust that built it.** The Buy Nothing Project's move from free Facebook groups to a paid app, followed by trademark enforcement against thousands of independent groups, produced a documented revolt: members called it a betrayal, and a 116,000-member group was forced to rename ([SF Standard](https://sfstandard.com/2025/11/07/buy-nothing-group-facebook-taken-down-trademark-infringment/), [MetaFilter](https://www.metafilter.com/198550/The-Battle-for-the-Soul-of-Buy-Nothing)). A chat-first rebuild of Freegle must read to members as easier gifting, not as the platform inserting itself, monetising, or adding friction to a relationship they already trust.

**Allocation fairness needs an explicit policy layer, not first-reply-wins by default.** WhatsApp neighbourhood groups routinely end in disputes over who claimed an item first, disadvantaging anyone not watching notifications; Freecycle's own Fair Chance Policy deliberately delays a giver's choice by about a day specifically to counter this ([AOL](https://www.aol.com/articles/neighbourhood-whatsapp-groups-getting-control-050000000.html), [Trash Nothing](https://help.trashnothing.com/what-is-the-fair-offer-policy)). A chat-first Freegle needs a documented, FSM-enforced allocation window rather than defaulting to whichever reply lands first in the thread.

**Discovery of a flow, not the flow's interaction style, is often the real barrier.** NN/g's research on WeChat mini-programs found new users frequently cannot find a mini-program without being handed a link or share, independent of how well designed the program itself is ([NN/g](https://www.nngroup.com/articles/wechat-mini-programs/)). Entry into Freegle's give, ask and community-chat flows needs explicit onboarding and navigation design; arriving in a chat window is not self-explanatory.

**A chat product that cannot demonstrably beat the interface or cost structure it replaces does not survive contact with a real budget.** Google Allo lost to the company's own better-established messaging apps, and Poncho's cartoon-cat weather bot could not monetise despite genuine engagement ([9to5Google](https://9to5google.com/2019/03/12/rip-google-allo-history/), [TechCrunch](https://techcrunch.com/2018/05/29/a-beverage-company-bought-and-shuttered-the-poncho-weather-app/)). The chat-first rebuild needs a measurable claim, lower moderation load, faster listing-to-collection time, higher completion, not conversational framing treated as value on its own.

#### Specific risks for Freegle

- **Allocation disputes will surface immediately.** Moving give/ask into chat threads without an explicit fairness window will reproduce the WhatsApp-group pattern of first-reply-wins disputes; borrow Freecycle's Fair Chance delay and enforce it in the FSM, not as a norm members are expected to self-police.
- **Policy statements the bot makes become Freegle's legal commitments.** Collection windows, banned items, safety and verification rules must come from one FSM-enforced source of truth, never model paraphrase, or Freegle inherits Air Canada's liability exposure.
- **Browsing multiple items or replies in a single linear thread will hit the same wall that killed Jetblack.** Freegle needs a list or card surface for scanning and comparing, inside or alongside the chat, not forced through sequential turns.
- **Reskinning a free, volunteer-run gifting community as an AI-chat product risks a Buy Nothing-style trust backlash**, especially if the change reads as the platform adding friction, gatekeeping, or monetisation to what members experience as a simple favour between neighbours.
- **"Deterministic FSM plus a model composing words" can quietly slide into open-ended free text standing in for real transactions.** Every one of the surviving precedents (Lemonade, Carousell, WeChat) keeps the model out of the transaction-state business entirely; if Freegle's FSM ever lets the model infer an accept, a price or a claim from unconstrained text rather than a button or confirmed slot, it repeats the exact ambiguity that produced Messenger's 70% unresolved-request rate.

Evidence is comparatively thin on Neya (joinneya.com, no user or funding numbers found), on x.ai's specific cause of failure beyond "acquired then discontinued," and on any classifieds platform having shipped and then killed a genuine listing-by-chat flow (current tools only use chat to draft a listing, then hand off to a form) — flagged as not found rather than inferred.

**What this design takes from them.** The state machine owns every transition and every
value that matters (item, quantity, who gets it); the model composes words and never
infers a transaction from free text, which is why typed commands such as "promise it to
Jane" always confirm with a chip. A non-generative check stands between the model and
the member (section 4). Browsing and choosing render as cards and a sheet, never as a
linear thread of turns. Classic Freegle is one tap away at every point. Freegle's own
rule that the giver chooses, not the first to reply, is what the chooser shows and the
ordering score serves; the fair-chance delay is a decision for Edward (decision 16).

## 4. The voice: composed, not templated

Freegle speaks. Every reply is composed by the model, in character, from the facts in
front of it. Templates exist only as the fallback when the model is unavailable.

**Character sheet** (written positively, with worked examples, as the Answerbot A/B
showed works): a local Freegle volunteer who has done this a thousand times. Warm through
pacing and plain words, not endearments or exclamation marks. Talks about people and
community, because that is what Freegle is. Short: usually one or two sentences. Never
"I am unable to". Contractions. British English. Says what happens next.

Worked example, after a post goes live:

> That's with your local freeglers now, all around Edinburgh. When someone's keen you'll
> hear from them right here.

Not: "Posted. People nearby will see it soon." (too terse) and not "Great news! Your
item has been successfully posted!" (Clippy).

**How a turn works.** The model receives: the character sheet; the current state's task;
the legal next states; the facts (the member's name and community, what has been
collected so far, the post or replies in question, Freegle's short fact sheet on how
things work); and the person's message. It returns JSON: what to say, any slot values it
understood, which legal transition to take, and up to three chips. The engine validates
the transition and chips before anything is shown.

**Fabrication check.** Before the reply is shown: every number, name, place, time and
community it mentions must appear in the facts it was given; it may not promise delivery,
a collection time, or that anyone will do anything, unless that is in the facts; it may
not state Freegle rules outside the fact sheet. A trip regenerates once; a second trip
shows the template. Nothing the model says is ever published as a post or sent to
another member; those paths stay as they are.

**Rambling, off-topic, abuse.** Answerbot's escalation, count-based: the first one or two
unclassifiable turns get a warm acknowledgement and stay put; the third or fourth gently
steer back to what Freegle can do; after that a soft close that leaves the buttons up.
Abuse gets one plain line and the same steer. After three abusive turns in an hour the
server stops consulting the model for that identity and the fixed steer line answers
instead. Typing is never disabled.

**Chips.** At most three. Buttons for small enumerable choices; typing for anything open.
Both always work.

**Latency.** Opus composes every turn, so replies take a few seconds. The reply streams
in word by word under a real typing indicator, which is what WhatsApp users expect from a
person. Taps that only advance a step get the next question composed in the same call
where the flow makes it predictable. Measured before launch; a faster model for pure
navigation turns is the fallback if it drags.

## 5. Don't become Clippy

| Failure | Rule |
|---|---|
| Gushing, exclamation marks, emoji, "successfully" | The character sheet, enforced by a style check on every composed reply. |
| Asks what it already knows | Logged in with a location: never ask where. Photo recognised: the name is offered, not asked. Facts already in the transcript are never re-asked. |
| Ignores what you just said | The reply opens on what it understood: "A grey three-seater, lovely. Have you got a photo?" |
| Corrects your words | Your wording is the first, preselected option; a cleaner name is offered alongside, never forced. |
| Confirms every field | One confirm card before anything is posted. "Are you sure?" only for withdrawing, unpromising, and typed commands that promise or allocate. |
| Piles up system bubbles | One composed message per event. |
| Fake typing | The indicator shows only while the model is actually composing. |
| Speaks when nothing happened | Freegle speaks when something real happened or fell due: a reply, a collection time passing, a post gone quiet, a question asked. Never tips, never marketing. |
| Browsing becomes a quiz | Nearby shows cards at once from the known location; otherwise one question. |
| Dead ends, preachy errors | One plain line and a way forward. A failed write offers [Try again]; the answers survive. |
| Deflects you to another room | Typing in Your posts goes to the person you are dealing with; with several, one tap says who. |
| No way out | Back arrow, a small progress line with ✕ during a flow, "Start again", typed "cancel". |
| Locks you out | Never. See section 4. |
| Hides the entry points | Give · Ask · Nearby sit in a persistent row above the composer, like a Telegram reply keyboard. Community and Help are in the menu. |
| Spreadsheet in a bubble | Several numeric inputs open a full-screen sheet, as WhatsApp does for polls. |
| Login wall first | Email is asked at the moment something needs an account. |
| Traps you in the new thing | "Classic Freegle" is one tap away in the menu, and remembered. |

Power path for a returning member with a location: Give → add photo → tap the suggested
name → Post. Four taps.

## 6. The chats that exist

| Row | What it is | WhatsApp analogue | Avatar, name, subtitle |
|---|---|---|---|
| Freegle | The assistant. Pinned first. | Business account | Freegle logo, "Freegle", last line said |
| Your posts | One chat about all your posts: replies arriving, who gets what, what's gone. Pinned second while you have open posts. | Business account (order updates) | Freegle logo with a post badge, "Your posts", last event ("Jane's interested in your sofa") |
| People | User2User chats | Person chat | Their avatar, name, last message |
| Volunteers | User2Mod chats | Business account | Group logo, "<Group> volunteers" |
| ChitChat | Local newsfeed | Group chat | Group icon, "<Town> ChitChat", last message |

Filter row: **All · Unread · People**. A member with many posts is not buried: Your posts
is one row however many posts they have.

**Landing.** Logged out or first visit: the Freegle chat. Returning member: the chat list
with Freegle and Your posts pinned, because every WhatsApp session starts on the list.

**Both chats are real.** The Freegle chat and Your posts are `chat_rooms` between the
member and the Freegle system user (`systemchat`), so ModTools sees exactly what a member
was told. Assistant turns are `chat_messages` whose text is what was said; chips and cards
are kept in a side table keyed by message id, the way `chat_prompts` already works, so old
apps and ModTools render plain text and the shell renders the widgets.

## 7. Your posts: choosing who gets what

One chat, event-driven, anchored to post cards. A post card shows photo, name, status
("3 interested", "Promised to Jane, Tue 7pm", "Taken") and opens the chooser.

**Data.** The member's posts (replies with userid, displayname, date; promises; outcomes;
availablenow), the chat list (each replier's chat, snippet, unread), the user store per
replier (thumbs, location), trysts. The same assembly `MyMessage.vue` does today. Three
small backend additions make it accurate: `messages_promises.count` (how many promised to
that person), `trysts.msgid` (which post a time belongs to, so one person promised two
things does not show the wrong time), and `heldreplies` on the owner's post (replies
waiting for a volunteer, so "no replies yet" is never said over one).

**Events** (each a composed message, anchored to the card):

- A reply: "Jane's interested in your sofa: 'Is it still available? I could collect
  Tuesday.' She's about a mile away." Chips: [Promise to Jane] [Reply] [See all].
- Two or more replies: [Who should have it?] opens the chooser.
- Promised: "That's promised to Jane for Tuesday at 7." [Change time] [Taken] [Unpromise].
- After the time: "Did Jane collect the sofa?" [Yes] [Not yet] [She didn't come].
- Partial: "Jane took 2 of the 4 chairs. 2 still to go." [Who's next?]
- Held reply: "Someone's replied to your lamp; a volunteer is just checking it first."
- Quiet: "No one's asked about the lamp yet. Want to give it another go?" [Repost]
  [Withdraw] [Edit]. Never said while a reply is held.
- Reposted: a divider "Reposted" and the earlier events collapse.
- All gone: "The chairs have all gone to good homes."

**The chooser** is a sheet (full screen), one per post: rows for each replier with their
message, distance, thumbs, when they replied, and reasons ("Replied first", "Nearest",
"Said when they can collect", "via Trash Nothing"). Single item: tap a row to promise.
Quantity above one: each row has a stepper prefilled 1 in reply order up to what is
available, or with the number their message asked for; header "4 available · 3
allocated"; [Confirm] promises each their count and the rest stays available. If a
replier is also interested in another of your posts, their row says so, with [Promise the
lamp too], so one person collecting several things is one conversation, not several.

**Order** is a deterministic score owned here (not the Helper's judgement prompt): reply
within an hour 3, within a day 2, later 1; net thumbs capped at 3; within 3 miles 2,
within 10 miles 1; a message over 40 characters or naming a collection time 1; a
thumbs-down from this offerer before, minus 5. Ties keep reply order. Reasons shown are the
two largest contributors. The offerer taps; nothing is chosen for them.

**Typing in Your posts** goes to the person the last event was about; with several live
conversations, one line: "Send to:" [Jane] [Ali] [Sam]. Commands ("withdraw the lamp",
"promise the sofa to Jane") always confirm with one chip before writing.

**People chats** keep today's Interested cards, so promising and marking taken from within
a conversation with Jane works as now.

## 8. Flows as ai-flower workflows

Each journey is a workflow definition (JSON, editable in ai-flower's Vue editor, which
ModTools can embed later). States are the questions; transitions are validated; read
actions look things up; write actions change things and are only listed on the states
that may take them. Every state's prompt is the task, not the words.

**Give**: START → PHOTO → ITEM → DESCRIPTION → (QUANTITY) → (WHERE) → (EMAIL) → CONFIRM →
POST → DONE, with RAMBLING reachable from every question state and CANCELLED from all.
A typed message that carries several answers lets the model fill several slots and
propose the first state still missing. Skips: WHERE when the member has a location,
EMAIL when logged in, QUANTITY unless the text suggests more than one. Read actions:
`recognise_photo`, `check_item`, `lookup_community`. Write action on POST only:
`create_post`, which is today's `composeStore.submit` path (draft, JoinAndPost, account
creation). The confirm card is CONFIRM's only chip set: [Post] [Change something].

**Ask**: ITEM → DESCRIPTION → (WHERE) → (EMAIL) → CONFIRM → POST. On leaving ITEM,
`find_matches` runs and up to three matching offers appear as cards with [Reply] and one
chip [Carry on asking]; tapping a match resolves the draft with one question.

**Nearby**: SHOW (cards at once) with chips [Offers] [Wanted] [Nearest]; typing searches;
tap a card to expand; [Reply] opens the existing reply pane inside the shell, which
already handles the typed reply, email signup, joining the closest community and the
hold cases.

**Community**: SHOW (nearest communities as cards) → JOIN (email first when logged out).

**Help**: fixed chips linking to existing pages.

Taps drive transitions host-side (`triggerTransition`), so a tap never waits on the model
for the decision, only for the wording. Typed text goes to `processInput`, where the model
proposes one of the legal transitions.

## 9. Engine and storage, inside apiv2

- **Engine**: a Go port of ai-flower's engine core in `iznik-server-go/assistant`: the same
  JSON definition format (so ai-flower's Vue editor edits the flows), the same validated
  transitions, the same prompt shape. Instances live in `assistant_instances` (one per
  open conversation, JSON context and history). No new service, no new container: the
  browser talks to apiv2 as it does today.
- **Model**: Anthropic Go SDK, `ASSISTANT_MODEL` default Opus 5, low effort, streamed.
  `POST /assistant/turn` answers with server-sent events: the reply streams word by word,
  then a final turn record. Taps go to the same endpoint and take no model decision, only
  the wording.
- **Transcript**: every turn is written straight into the member's Freegle chat room
  (`chat_rooms` with the Freegle system user, created on first use the way the first-reply
  code does), as `chat_messages`; chips and cards go to `chat_widgets` keyed by message id,
  the way `chat_prompts` works, so ModTools and old apps render plain text and the shell
  renders the widgets. A logged-out visitor's turns are held on the instance and written
  the moment an account exists.
- **Identity and quotas**: the member id from the JWT when logged in; otherwise a signed
  anonymous token minted by apiv2 (HMAC with `JWT_SECRET`), echoed back by the browser in
  a header, never a client value. 60 model turns an hour per identity and per IP; daily
  caps for anonymous (`ASSISTANT_ANON_DAILY_CAP`, default 500) and signed-in
  (`ASSISTANT_DAILY_CAP`, default 20000). Over quota: template lines, never an error.
  Exhaustion is logged for alerting.
- **Strikes**: three abusive verdicts in an hour downgrade an identity to templates for an
  hour.
- **Facts the model sees**: the member's name, community and location name; the flow's
  collected slots; the post, replies, promises and trysts in question; a short Freegle
  fact sheet. Nothing else. The fabrication check holds it to that.
- **Schema**: `assistant_instances`, `chat_widgets`, `messages_promises.count`,
  `trysts.msgid`. Laravel migrations plus idempotent production SQL.
- **ChitChat screening**: new ChitChat posts run through the same checks as chat messages
  and are held on a hit for the ChitChat moderation team's existing queue, because a
  pinned group chat raises its exposure. Decision 11.

## 10. Shell, screens, choice and the rest of the system

**Chat or classic.** A member's choice, remembered in their settings (or the browser when
logged out): the ⋮ menu has "Classic Freegle"; classic pages carry a quiet "Try the chat
version" link. The default for people who have not chosen is a runtime flag
(`CHAT_FIRST_DEFAULT`: chat, classic, or a percentage by user id), which is also the
kill switch. Nothing classic is removed.

**Shell.** Layout `chat`. Full screen below 768 px; from 768 px a 390 × min(844px, 92vh)
column, centred, rounded, on a quiet background, with Privacy · Terms · About beneath.
`ChatPane` and `ChatFooter` get an `embedded` mode (heights from the column, header from
the shell). Modals stay viewport overlays on desktop, as WhatsApp Web's dialogs do. An
iframe at phone width was the alternative; it renders perfectly but changes every desktop
Playwright test on the chat pages.

**Routes.** `/` Freegle chat or chat list · `/chats` · `/chats/:id` · `/chats/posts`
(Your posts) · `/chitchat`, `/chitchat/:id` (scrolls to the message).

**Landing content.** The logged-out Freegle chat opens with the composed welcome, the
three action chips, and a strip of real recent offers nearby as cards, the same sample
the landing page serves today, so search engines still index listings. The landing bandit
experiment continues on the Give and Ask chips with the same identifiers.

**ChitChat as a group chat.** Posts and replies in one chronological stream as WhatsApp
does; a reply shows a quote of what it answers and tapping the quote scrolls there.
Coloured author names, photos, time, a ❤ reaction count. Long-press: Reply · Report ·
Hide. Composer posts with optional photo. Header subtitle "Within 30 min · tap to change".
Logged out: "Sign in to join the chit-chat."

**Accessibility.** WCAG AA: chips and cards are real buttons with labels, steppers have
keyboard plus and minus, new messages are announced through a polite live region, motion
respects reduced-motion, contrast checked, Lighthouse accessibility audit in the visual
review.

**Legacy URLs.** Emails and push notifications link to these; all keep working.

| URL | Now |
|---|---|
| `/message/:id`, `?reply=1`, `?outcome=…` | Unchanged; the outcome modal and the chooser call the same store methods |
| `/myposts` | Unchanged page; the shell's Your posts is the chat |
| `/chats`, `/chats/:id`, `/chitchat` | Inside the shell when the member is on chat; classic otherwise |
| `/give`, `/ask`, `/browse`, `/explore/**` | Unchanged pages; classic, and deep links |
| `/give/mobile/photos` (app share sheet) | Opens the chat Give flow with the photo attached when the member is on chat |

**Analytics.** Every state, chip and outcome emits a client log action, so the funnel is
measured against classic.

**App build.** Same routes; native camera and share-sheet images feed the Give photo state.

## 11. Decisions

Decided by Edward on version 2: no chat per post (scales badly, splits one person across
places); offer chat and classic; keep silence about pending; Opus; the conversation must
be visible in ModTools; composed wording, not templates; three chips, not five; the
"unbidden" rule is about real events including time passing.

Still to confirm:

| # | Taken in this design | Alternative |
|---|---|---|
| 1 | Your posts is one chat, event-driven, with a per-post chooser sheet | A screen (not a chat) reached from the menu |
| 2 | Returning members land on the chat list; new visitors in the Freegle chat | Always the Freegle chat |
| 3 | CSS column with an embedded mode for the chat components | Iframe at phone width |
| 4 | Legacy pages stay as deep links and as classic | Redirect into the chat |
| 6 | Ask shows up to three matching offers as soon as the item is known | Only after posting, as today |
| 9 | Post-chat unread lives in the room like any chat | Settings watermark |
| 10 | Chooser ordered by the owned score with reasons; never auto-picks | Plain reply order |
| 11 | ChitChat posts and edits run through the worry-word check; a hit goes down the existing report path (out of the feeds, ChitChat volunteers emailed, the words named) | Inherit today's unscreened ChitChat |
| 12 | Landing bandit continues on the chips | Retire it |
| 13 | Schema: `messages_promises.count`, `trysts.msgid`, widget side table | Allocation recorded only at outcome |
| 14 | App share sheet opens the chat Give flow for chat members | App keeps the classic wizard |
| 15 | Assistant runs inside apiv2 (Go port of the ai-flower engine core) | A separate Node service running ai-flower itself |
| 16 | The chooser opens as soon as there are replies; the giver decides when | A fair-chance window (Trash Nothing delays the choice about a day) enforced by the flow |
| 17 | Browse in chat mode is a sheet over the chat: rows scroll inside it, the transcript shows a count and a glimpse, never cards (2026-09-12, replaces the Nearby screen; see `2026-09-12-nearby-sheet-design.md`) | A page of its own that grows with More |

## 12. Testing

- Vitest: shell components; the embedded mode; the widget renderer; post-card and
  chooser (split defaults, score and reasons, held replies, repost divider); voice style
  check; fabrication check; transcript writer.
- Assistant service (vitest): each workflow definition validates; host-driven transitions
  for every chip; typed multi-slot turns fill several slots and land on the right state;
  rambling escalation levels; fallback on model failure; quotas; strikes; fake Anthropic.
- Go: proxy and auth, anonymous identity, quotas, promise count, tryst msgid, held
  replies, transcript endpoint, widget side table.
- Laravel: migrations; ChitChat screening job.
- Playwright: landing (logged out, logged in); give as a new member; ask with a match;
  nearby and reply; find and join a community; ChitChat post, reply, report; Your posts
  chooser, split, promise, taken; rambling escalation; classic toggle both ways; desktop
  column. Homepage spec and `tests/unit/pages/index.spec.js` rewritten; every existing
  chat spec still passes.
- Headless Chrome (GPU off) screenshots at 390×844 and 1440×900 of every screen, plus a
  Lighthouse accessibility audit, before the adversarial code review.
- Voice evaluation: 60 frozen moments from the flows judged blind, composed versus
  template, before launch, in the Answerbot manner.

## 13. Docs

`docs/members/getting-started.md`, `giving.md`, `getting.md` gain the chat journeys and
the classic toggle; `docs/screenshots` regenerated; new
`docs/developers/reference/chat-first.md` covers the workflows, the service, quotas and
strikes, the transcript and widget storage, the chooser score, and the schema additions.
Moderator docs gain one paragraph: the Freegle chat appears in chat review like any chat.
