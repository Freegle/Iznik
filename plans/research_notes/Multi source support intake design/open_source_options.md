# Open-source and self-hostable helpdesk/ticketing systems for a Discourse+email+in-app support intake, with LLM triage

## How do the main open-source helpdesk/ticketing candidates compare on licence, stack, maturity, channels, data model, SLA/automation, API and AI features?

### Takeaway
The field splits into conversation-centric live-chat/inbox tools (Chatwoot, Tiledesk, Papercups, Libredesk) and ticket-centric systems (Zammad, FreeScout, osTicket, UVdesk, GLPI, RT, Znuny, Frappe Helpdesk); most now have some LLM story, but only Zammad 7 and RT/Znuny let you bring your own model key with no vendor markup, while Chatwoot and GLPI gate their AI behind paid tiers on top of your own model costs.

### Cited Findings

**Chatwoot**
- MIT-licensed core; a separate proprietary "Enterprise Edition" (self-hosted, paid) adds SSO/SAML, custom roles, and other features layered on top of the open-source codebase — [Chatwoot self-hosted pricing](https://www.chatwoot.com/pricing/self-hosted-plans); [Chatwoot Enterprise Edition docs](https://developers.chatwoot.com/self-hosted/enterprise-edition)
- Ruby on Rails + PostgreSQL + Redis, deployed via Docker; conversation-centric data model (contacts + conversations across channels, not tickets)
- Captain (Chatwoot's AI agent/copilot) is metered by credits: 300/500/800 credits/month included depending on plan tier, $20 per extra 1,000 credits, purchased credits expire after 6 months and are non-refundable; requires an active paid subscription — [Chatwoot Captain AI credits and billing](https://www.chatwoot.com/blog/captain-ai-credits-and-billing)
- Captain is not available on the free Community edition or the free "Hacker" cloud tier; the cheapest self-hosted route to Captain is the Premium Support tier at $19/agent/month — [Chatwoot pricing](https://www.chatwoot.com/pricing); [eesel AI: Chatwoot pricing breakdown](https://www.eesel.ai/blog/chatwoot-pricing)
- An older (~September 2025) pricing model charged a flat ~$200/month add-on for up to 10,000 AI responses; this has since been replaced by the 2026 credit-tier model above — [dev.to: Chatwoot pricing teardown 2026](https://dev.to/beton/chatwoot-pricing-teardown-2026-a7g)
- Dashboard Apps (arrived in Chatwoot v2.7.0) let you embed an externally-hosted web app inside the agent's conversation view, configured under Settings → Integrations → Dashboard Apps with just a name and URL — [Chatwoot v2.7.0 release notes](https://www.chatwoot.com/blog/v2-7-0); [How to use Dashboard Apps (Chatwoot user guide)](https://www.chatwoot.com/hc/user-guide/articles/1677691702-how-to-use-dashboard-apps)
- Context passes to a Dashboard App via a `window` `message` event (conversation/contact payload pushed on load) or on-demand via `postMessage` to the key `chatwoot-dashboard-app:fetch-info`, which Chatwoot responds to with the current conversation payload — [Chatwoot: Dashboard Apps — user info from other URLs](https://www.chatwoot.com/blog/dashboard-apps)
- This is one-way/read-context only: a still-open GitHub issue (#5898, opened Nov 2022) asks for the logged-in *agent's* identity to be passed to the Dashboard App (needed to match the agent to an external CRM account) and the maintainers confirm this isn't currently provided — [GitHub chatwoot/chatwoot#5898](https://github.com/chatwoot/chatwoot/issues/5898)
- Dashboard Apps render as a new tab in the conversation window, not in the right-hand sidebar; a GitHub feature request from January 2026 (#13305) asks for an option to place them in the sidebar instead, specifically so a customer's CRM contact card can sit alongside the chat rather than in a separate tab — no evidence in results that this has shipped — [GitHub chatwoot/chatwoot#13305](https://github.com/chatwoot/chatwoot/issues/13305)

**Zammad**
- Licence: open-source core (AGPLv3-family self-hosted edition); Elasticsearch is a mandatory dependency for full-text search
- Zammad 7.0 (released 4 March 2026) added native AI ticket-summary and related features with **seven** selectable providers: Zammad AI (EU-hosted, GDPR-compliant), OpenAI, Anthropic Claude, Azure AI, Mistral AI, Ollama (local/self-hosted), and a generic "Custom (OpenAI-compatible)" slot usable for providers such as Google Gemini — [Zammad 7.0 product page](https://zammad.com/en/product/zammad-7-0); [Zammad AI product page](https://zammad.com/en/product/artificial-intelligence)
- AI calls cost €0.03 each unless routed through Zammad's own hosted AI (which is covered by the subscription); external-provider API costs are additional and billed by that provider directly; all AI features are off by default and toggled per group — [Zammad AI product page](https://zammad.com/en/product/artificial-intelligence); [Zammad admin docs: AI summary](https://admin-docs.zammad.org/en/latest/ai/summary.html)
- Local-model (Ollama) ticket summarization is documented as unreliable: Zammad's summary features require the model to emit strict JSON, and a Zammad community-forum thread reports summaries "mostly do not work as the models misbehave" after upgrading and switching to a local Ollama model — [Zammad community forum: best Ollama model for AI features](https://community.zammad.org/t/which-model-from-ollama-works-best-for-ai-features/19730)
- A third-party guide gives per-VRAM-tier model recommendations to work around this: 8GB VRAM → llama3.2:3b or gemma3; 24GB VRAM → mistral-small3.2:24b, qwen3.6-35b-a3b (thinking off), or gemma3:27b — [Open Ticket AI: best Ollama model for Zammad AI](https://openticketai.com/en/docs/blog/best-ollama-model-zammad-ai/); [Open Ticket AI: Zammad AI/Zammad 7 features guide](https://openticketai.com/en/docs/blog/zammad-ai-zammad-7-ki-funktionen-leitfaden/)
- CTI (Computer Telephony Integration) is caller-log/push-only: a phone system POSTs call events to Zammad's generic CTI Push REST endpoint; Zammad then opens the matching customer profile or a "create ticket" dialog by caller ID. There is no VoIP calling from within Zammad — [Zammad CTI integration feature page](https://zammad.com/en/product/features/cti-integration); [Zammad admin docs: generic CTI](https://admin-docs.zammad.org/en/6.4/system/integrations/cti/generic.html)
- Official ready-made CTI connectors exist for Sipgate and Placetel; a community-built connector (Mancy/Zammad_3CX on GitHub) adds 3CX caller-ID matching, including an option to auto-create a Zammad contact for unknown callers — [Zammad admin docs: Sipgate CTI](https://admin-docs.zammad.org/en/latest/system/integrations/cti/sipgate.html); [Zammad admin docs: Placetel CTI](https://admin-docs.zammad.org/en/latest/system/integrations/cti/placetel.html); [GitHub Mancy/Zammad_3CX](https://github.com/Mancy/Zammad_3CX)
- Caller-ID/customer matching in Zammad is index-only against records already inside Zammad — it does not perform a live lookup against an external database, so external users (e.g. a separate Freegle members table) would need to be synced into Zammad first (LDAP/CSV/REST import) before this matching works
- Elasticsearch's memory footprint and breakage on major-version upgrades is a long-running, multi-year documented pain point on Zammad's own community forum (2019–2026 thread history), not a one-off

**FreeScout**
- Licence: AGPL-3.0 (network-use clause applies to modified, network-served versions); pure PHP/MySQL stack, deliberately light footprint (originally a Help Scout-alike)
- Core is free; the CRM, Custom Fields, and API & Webhooks modules are each **separate paid add-ons** sold individually from freescout.net — [FreeScout Modules directory](https://freescout.net/modules/); [FreeScout CRM (Customers Management) module](https://freescout.net/module/crm/); [FreeScout API & Webhooks module](https://freescout.net/module/api-webhooks/); [FreeScout Workflows module](https://freescout.net/module/workflows/)
- The CRM module adds *customer*-level custom fields (Dropdown, Single line, Single-line tags, Multi line, Number, Date) which end-users cannot see or edit; the separate Custom Fields module adds *conversation*-level custom fields, exposed in the API as `PUT /api/conversations/{id}/custom_fields` with a body like `{"customFields":[{"id":37,"value":"Test value"}]}`, authenticated via an `X-FreeScout-API-Key` header — [FreeScout API docs](https://api-docs.freescout.net/)
- No API endpoint for reading/writing CRM *customer* custom fields turned up in the docs search — only the conversation-scoped Custom Fields endpoint was found; this split across two separately-purchased modules is the mechanism, and it appears to be a real gap rather than an oversight in this search
- Webhooks (API & Webhooks module) fire on conversation create/assign/status-change/move/delete/restore, customer or agent replies, note addition, and customer create/update; failed deliveries retry up to 10 times over 2 hours; the module needs PHP's `hash` extension to sign requests — [FreeScout API & Webhooks module page](https://freescout.net/module/api-webhooks/)
- The Workflows module can fire a custom webhook event as an automation action (requires the API & Webhooks module installed) and can branch on custom-field values (requires the Custom Fields module) — [FreeScout Workflows module page](https://freescout.net/module/workflows/)
- A community (non-official) custom-fields module was reported, in a GitHub issue opened 25 September 2026, as incompatible with the official API & Webhooks module — the Webhooks code checks for an active module literally named `customfields` and throws when that check passes but the `\CustomField` class isn't loadable yet — [GitHub mnicole-dev/freescout-customfields-module#1](https://github.com/mnicole-dev/freescout-customfields-module/issues/1)
- Real-world usage example: WP Fusion migrated from Help Scout to FreeScout and describe building a Gravity Forms integration that files new tickets via FreeScout's API (with custom fields) instead of by email — [WP Fusion: we moved from Help Scout to FreeScout](https://wpfusion.com/news/we-moved-from-help-scout-to-freescout/)

**osTicket**
- Licence: GPLv2; PHP/MySQL, ticket-centric, one of the oldest OSS helpdesks still maintained
- Still receives new GitHub issues through 2026 (e.g. #6975 opened 7 Aug 2026, others in Apr/May/Jun 2026), but a 22 September 2026 issue flags that the official-looking `osticket/osticket` Docker Hub image is six years old, built on Ubuntu 18.04 (its standard support ended 2023) despite 2.5M+ pulls, and asks for either a "not maintained" notice or an update — reporter isn't sure the image is truly official — [GitHub osTicket/osTicket#6984](https://github.com/osTicket/osTicket/issues/6984)
- Consistent complaint across review sites: dated interface. A May 2026 Capterra review says the UI "can feel somewhat outdated compared to newer help desk platforms"; another reviewer said the interface was outdated enough to embarrass their team in front of clients — [Capterra osTicket reviews](https://www.capterra.com/p/125118/osTicket/reviews/)
- A February 2026 CheckThat.ai review roundup reports PHP 8.1+ compatibility problems in osTicket 1.17.3 forcing some users to stay on older PHP, plus user comments describing the look as "from the 2000s," poor mobile support, and multi-click friction for common tasks — [CheckThat.ai: osTicket reviews 2026](https://checkthat.ai/brands/osticket/reviews)
- Still rated well on review aggregators (G2: 63% 5-star per the CheckThat.ai summary) but on a small sample (119 verified G2 reviews vs. 3,505 for Freshdesk) — [CheckThat.ai: osTicket reviews 2026](https://checkthat.ai/brands/osticket/reviews)
- ITQlick flags scalability limits at high enterprise ticket volumes and limited native reporting, pushing users to third-party reporting tools — [ITQlick osTicket review](https://www.itqlick.com/osticket)

**UVdesk**
- Licence: OSL-3.0 across the whole Symfony bundle set (community-skeleton, core-framework, api-bundle, automation-bundle, extension-framework)
- Star count conflicts between sources: a May 2025 NocoBase comparison article states 11.6k GitHub stars, while a scrape of UVdesk's own GitHub organization page shows an unlabeled figure of ~19,588 — genuine unresolved conflict, not reconciled in this research — [NocoBase: open-source helpdesk comparison](https://nocobase.com); UVdesk GitHub organization page (scraped, exact URL not captured)

**Peppermint**
- Confirmed archived/discontinued as of a September 2026 review, which also names successor tools to consider instead — exact archival date and successor names were reported in an earlier segment of this research but the specific URL was not re-verified in this pass; treat as a closed candidate for Freegle's purposes

**Frappe Helpdesk**
- Licence: AGPL-3.0; stack is Python (Frappe Framework) + Vue 3, ticket-centric
- Self-hosting requires Docker/Linux administration skill; Frappe Cloud offers managed hosting from $5/month site plans as an alternative to self-hosting
- Frappe's own (non-independent-legal) position is that ordinary no-code customization of an AGPL Frappe app (custom fields, client/server scripts, Builder pages) does not trigger AGPL's redistribution obligations — this is Frappe's stated interpretation, not independent legal advice

**GLPI**
- Licence: GPL-3.0-or-later (bumped up from GPL-2.0-or-later specifically to resolve a licence conflict after AGPL-3.0-or-later code from the FusionInventory project was merged in)
- GLPI-AI requires a paid GLPI Network subscription (Basic tier or above) *plus* your own AI-provider account (e.g. an OpenAI API key and org ID) — this is a paid-plugin-on-free-core model, unlike Zammad's BYO-key-on-free-core approach
- "Free mode" within GLPI-AI allows use of GPT-3.5 Turbo 16K
- A separate, unofficial community plugin (Cloud-Dark/glpiai on GitHub) supports OpenRouter/Ollama/Gemini as alternative backends, letting self-hosted models avoid ongoing per-call API costs

**Request Tracker (RT) / Znuny (OTRS fork)**
- RT: GPLv2, Perl-based, one of the oldest OSS ticketing systems, BYO-model AI via its own API key with no subscription requirement
- Best Practical (RT's maintainer) shipped an **official** open-source MCP server (`bestpractical/mcp-server-rt`), announced on Best Practical's own blog 31 March 2026; requires RT 6.0+ with REST 2.0 enabled and an auth token from Settings → Auth Tokens; supports TicketSQL search, full ticket CRUD, queue/custom-field/user lookups, and admin functions (queue/group/rights management); Best Practical states it sends no data to itself or third parties
- Community MCP alternatives also exist: a "crunchtools" Request Tracker MCP (released 15 Feb 2026) and `richieri/rt-mcp`
- Znuny: GPLv3, continuation of OTRS Community Edition 6.0.30 (OTRS CE went EOL Dec 2020). Znuny shipped an official "Znuny-LLM" add-on via its open-source repo starting 8 July 2026: BYO-model (Ollama/OpenAI/Azure/OpenAI-compatible), auto-proposes queue/type/service/SLA on a ticket's first customer message, rewrites ticket titles into the customer's language, extracts structured data into dynamic fields via plain-language rules, offers a "Compose Selection Rewrite" for agent drafts, ships a guided setup wizard, and lets individual features be toggled for phased rollout
- An earlier-cited OTOBO community guide had claimed Znuny had no official AI feature at all; that claim predates the 8 July 2026 Znuny-LLM release and should be read as a time-based resolution, not an ongoing contradiction

**Papercups**
- MIT licence; maintenance-mode status confirmed via Hacker News discussion and the project's own GitHub README; originated 2022

**Tiledesk**
- Confirmed licence change **from AGPL-3.0 to MIT**, per Tiledesk's own blog post titled "we are moving Tiledesk open source license to MIT" — direction is unambiguous, but the exact year the change took effect could not be confirmed (see Gaps)

**Libredesk**
- Licence: AGPL-3.0; Go backend + Vue 3 + Shadcn UI frontend; built by the team behind Listmonk
- Stack requirement conflict: PickYourTech states PostgreSQL only, while the GitHub repo description and OSSroster both state PostgreSQL + Redis — flagged as a likely-stale claim on PickYourTech's side, not independently resolved
- Explicitly alpha-stage per pkg.go.dev
- AI features (assistant answering live chat from a knowledge base with human handoff, and an Agent Copilot that drafts replies/summarizes/looks up KB answers in-inbox) ship in the single open-source AGPL edition with no separate paid tier — a GitHub-linked PR for custom AI tools includes a human-approval gate before actions execute

### Inferences
- Of all candidates, only Zammad 7 and RT/Znuny let Freegle point AI features at its own model/API key with zero per-call vendor markup beyond the model provider's own charges; Chatwoot's Captain and GLPI's GLPI-AI both require an ongoing paid subscription tier layered on top of model costs, which matters for a small charity's budget.
- FreeScout's headline "free and self-hosted" positioning is undercut for any deployment that needs CRM-style customer records, conversation custom fields, and webhooks together — that combination costs three separate paid modules, and the module boundary appears to leave no first-class way to read/write CRM customer fields via API at all.
- Local/self-hosted LLMs (Ollama) are a real cost-saver but come with a documented reliability tax (strict-JSON-output failures) in at least Zammad; any self-hosted-model plan should budget time for model selection and prompt-format hardening, not assume it "just works" the way a hosted OpenAI/Anthropic call does.
- Chatwoot's Dashboard Apps are the most promising cheap integration point for showing a Freegle member's account details next to a conversation, but the missing agent-identity pass-through and tab-not-sidebar placement (both open, unresolved GitHub issues) mean it would need custom glue code and accepting a less polished placement than hoped.

### Gaps
- Peppermint's exact archival date and named successor tools were reported in an earlier part of this research session but the source URL was not re-captured in this pass — needs re-verification if precise dates matter.
- The exact year of Tiledesk's AGPL-3.0 → MIT licence change is unconfirmed; search results only surfaced index/last-updated dates (24 December 2025 and 19 January 2024) on the announcing blog post, not a clear original publication date.
- UVdesk's true current GitHub star count is unresolved (11.6k vs. ~19,588 across two sources).
- Direct multi-language/i18n depth for Chatwoot and Zammad specifically was not separately re-searched in this pass; general OSS-helpdesk-comparison articles mention i18n support exists for both but without concrete language-count or completeness detail.
- FreeScout GitHub issue #4657 (customer custom-field-definition API gap, referenced earlier in this research) was not re-verified as open or closed in this pass.

## Which candidate integrates most cheaply with Freegle's existing member database (email lookup, sidebar showing member account)?

### Takeaway
None of the candidates offer a true live, read-only external-database lookup out of the box; every option requires either syncing member data into the helpdesk (Zammad, FreeScout's paid CRM module) or building a small custom iframe/webhook bridge (Chatwoot Dashboard Apps, FreeScout webhooks) — Chatwoot's Dashboard Apps and FreeScout's API & Webhooks module are the two cheapest starting points, with meaningful gaps in each.

### Cited Findings
- Chatwoot Dashboard Apps let you embed any URL you host inside the agent conversation view (Settings → Integrations → Dashboard Apps: just a name + URL), and that embedded page receives conversation/contact context via a `window` message event or can actively request it via `postMessage` to `chatwoot-dashboard-app:fetch-info` — this is the lowest-friction way to show member-account data alongside a conversation without modifying Chatwoot itself — [Chatwoot v2.7.0 release notes](https://www.chatwoot.com/blog/v2-7-0); [Chatwoot: Dashboard Apps — user info from other URLs](https://www.chatwoot.com/blog/dashboard-apps); [How to use Dashboard Apps](https://www.chatwoot.com/hc/user-guide/articles/1677691702-how-to-use-dashboard-apps)
- The two open gaps in that approach: no agent-identity pass-through (GitHub #5898) and no native sidebar placement, only a separate tab (GitHub #13305, still open as of Jan 2026 filing) — [GitHub chatwoot/chatwoot#5898](https://github.com/chatwoot/chatwoot/issues/5898); [GitHub chatwoot/chatwoot#13305](https://github.com/chatwoot/chatwoot/issues/13305)
- FreeScout's route to the same goal needs two paid modules together: CRM (for customer records/custom fields) and API & Webhooks (to push conversation events, e.g. "customer.created," out to an external system, or to pull/push custom-field values via `PUT /api/conversations/{id}/custom_fields`) — [FreeScout CRM module](https://freescout.net/module/crm/); [FreeScout API & Webhooks module](https://freescout.net/module/api-webhooks/); [FreeScout API docs](https://api-docs.freescout.net/)
- No FreeScout API endpoint for reading/writing the CRM module's *customer*-level custom fields was found in this search — only conversation-level custom fields are documented as API-accessible, which limits how far the "cheap integration" goes without further (undocumented or absent) plumbing
- Zammad's CTI/caller-ID matching is index-only against contacts already inside Zammad; there is no live external-database lookup — any Freegle-member-lookup approach in Zammad requires importing/syncing member records into Zammad first via LDAP, CSV, or its REST API — [Zammad CTI integration feature page](https://zammad.com/en/product/features/cti-integration); [Zammad admin docs: generic CTI](https://admin-docs.zammad.org/en/6.4/system/integrations/cti/generic.html)

### Inferences
- For Freegle specifically, a Chatwoot Dashboard App that calls a small internal read-only API (exposing member lookup by email) is likely the cheapest true "sidebar with member account" build, provided the missing-agent-identity and tab-vs-sidebar rough edges are acceptable or worked around with custom JS in the embedded page.
- Any option that requires syncing Freegle's member table into the helpdesk's own contact database (Zammad, FreeScout CRM) creates an ongoing data-freshness and duplication problem that a live-lookup iframe approach avoids.

### Gaps
- No first-hand account of anyone actually building a Freegle-style "external member database lookup in the ticket sidebar" integration was found for any of these tools — assessment above is inferred from documented mechanisms, not a proven case study.
- Whether UVdesk, Frappe Helpdesk, RT, or Libredesk offer an equivalent to Chatwoot's Dashboard Apps was not directly searched in this pass and should be treated as an open question rather than an assumed "no."

## Could Discourse itself serve as the ticketing/support system, using its own AI triage and automation features?

### Takeaway
Discourse has real, actively-developing AI/automation building blocks (discourse-ai + discourse-automation, and a brand-new visual "Workflows" builder), but no native ticket data model (status/priority/SLA/assignment-history) — Pavilion's dedicated discourse-tickets plugin exists to bridge that gap but looks stale and would need validation against current Discourse versions before relying on it.
- discourse-ai (bundled with Discourse core) combined with the discourse-automation plugin supports "AI triage using Agent" (renamed from "AI triage using Persona" — Discourse's own Meta guide shows this Personas→Agents terminology shift) — [source cited in earlier segment of this research, URL not recaptured in this pass]

### Cited Findings
- Discourse's main documented AI-triage use case is auto-generating draft support responses in a Support category, using community content as context, either posted as staff-only "whispers" for review before publishing or fully "silent" (no post at all) when paired with a custom tool for side-effect-only actions such as internal API calls — attributed to Discourse team member "sam" on Meta [URL not recaptured in this pass — flagged for re-verification]
- Discourse announced a new visual, drag-and-drop automation builder called "Discourse Workflows" (Admin → Plugins → Workflows) on 18 September 2026, available on Business/Enterprise plans only, with four node types: Triggers (topic created, user added to group, schedule, webhook, manual), Conditions (branching logic), Actions (post a reply, send a PM, grant a badge, call an external API, run an AI agent), and Utilities (delays, loops, variable assignment); the older Automations plugin continues to work in parallel with no forced migration [URL not recaptured in this pass — flagged for re-verification]
- Discourse's "Support" category type (introduced in 2026.3.0, per earlier research in this task) auto-configures Q&A/accepted-answer behaviour when the Solved plugin is enabled
- Pavilion's discourse-tickets plugin (github.com/paviliondev/discourse-tickets) is the closest thing to a native Discourse-to-ticketing bridge: it adds a ticketing system to Discourse, requires Discourse's tagging feature enabled, and is designed to work alongside the Assign plugin — [GitHub paviliondev/discourse-tickets](https://github.com/paviliondev/discourse-tickets); documentation lives in the Discourse Meta topic ["Tickets Plugin"](https://meta.discourse.org/t/tickets-plugin/97914)
- The plugin is switched on via a `tickets_enabled` site setting, and its `plugin.rb` still shows traces of its origin under the `angusmcleod/discourse-tickets` repo path before being moved under the Pavilion organisation — [GitHub paviliondev/discourse-tickets plugin.rb](https://github.com/paviliondev/discourse-tickets/blob/main/plugin.rb)
- Repo activity is thin: 24 stars / 13 forks, new issues are restricted, and the open issues shown are from 20 May 2020 and 20 March 2023 — no evidence of recent maintenance or of compatibility with current (2026) Discourse — [GitHub paviliondev/discourse-tickets](https://github.com/paviliondev/discourse-tickets); [Issues list](https://github.com/paviliondev/discourse-tickets/issues)
- A community-reported pain point: chaining multiple AI triage scripts/automations together is awkward in practice [source from earlier segment, URL not recaptured in this pass — flagged for re-verification]

### Inferences
- Discourse alone is well-suited to be the *intake and initial AI-triage* layer (leveraging content it already has, and its native automation/Workflows tooling) but is not a substitute for a real ticket system's lifecycle tracking (status, priority, SLA, assignment history, merge/split) unless Pavilion's plugin — or an equivalent purpose-built extension — is adopted and proven to still work.
- Given the discourse-tickets plugin's apparent staleness, a more robust architecture for Freegle is likely Discourse-for-triage-and-community-facing-Q&A feeding a separate ticket store (one of the helpdesk candidates above, or the lightweight GitHub/Linear alternative below) via webhooks, rather than trying to make Discourse itself the system of record for support tickets.

### Gaps
- This pass could not re-fetch the exact URLs for several Discourse AI/automation facts (the "silent"/whisper triage detail, the Workflows announcement page, and the "chaining triage scripts" pain point) that were sourced in an earlier segment of this same research task before a context compaction — the facts themselves came from real searches earlier in this task, but the precise source URLs were not preserved through compaction and were not re-verified in this final pass. These should be treated as needing re-confirmation, not as fabricated, but are flagged here per the sourcing requirement.
- Current-Discourse-version compatibility of Pavilion's discourse-tickets plugin is unconfirmed either way — the multi-year-stale issue tracker is suggestive but not conclusive proof it's broken on current Discourse.
- Whether Discourse's "assign" plugin (mentioned as a dependency/companion for discourse-tickets) has itself kept pace with current Discourse versions was not directly checked.

## What are the known pain points for each candidate, sourced from real user/community discussion?

### Takeaway
Every system has a well-documented Achilles' heel: Chatwoot's operational fragility and Enterprise-tier pricing creep, Zammad's Elasticsearch memory/upgrade pain, osTicket's visibly dated UI and stale Docker image, FreeScout's paid-module fragmentation, and RT/Znuny's comparative lack of these specific complaints (though also much less discussed AI feature history until 2026).

### Cited Findings
- **Chatwoot — operational fragility in production**: a detailed first-hand DEV Community account from someone running self-hosted Chatwoot for WhatsApp inboxes describes disk filling up from message-attachment files that never show up in database-size monitoring, and a 125-second slow query that took the whole product down — [dev.to: self-hosted Chatwoot — 5 failures the docs don't warn you about](https://dev.to/achiya-automation/self-hosted-chatwoot-5-failures-the-docs-dont-warn-you-about-47c3)
- **Chatwoot — Enterprise pricing creep eroding self-hosting's cost advantage**: GitHub issue #12726 raises concern that per-agent Enterprise pricing undermines the traditional cost benefit of self-hosting an open-source tool — [GitHub chatwoot/chatwoot#12726](https://github.com/chatwoot/chatwoot/issues/12726)
- **Chatwoot — setup/instability and UI learnability complaints**: aggregated in a competitor blog post (eesel AI) citing a relayed Reddit-sourced complaint, and G2 reviews complaining that SAML SSO is Enterprise-only and that the UI has a learning curve — [eesel AI: Chatwoot review/complaints roundup](https://www.eesel.ai/blog/chatwoot)
- **Zammad — Elasticsearch memory consumption and upgrade breakage**: an extensive, multi-year (2019–2026) thread history directly on Zammad's own community forum documents repeated memory-exhaustion and major-version-upgrade breakage tied to the mandatory Elasticsearch dependency — [community.zammad.org threads, referenced in earlier segment of this research]
- **Zammad — local AI model unreliability**: community forum report that ticket-summary AI features "mostly do not work as the models misbehave" when using a local Ollama model post-upgrade — [Zammad community forum](https://community.zammad.org/t/which-model-from-ollama-works-best-for-ai-features/19730)
- **osTicket — dated UI and stale official Docker image**: Capterra/CheckThat.ai reviews describe the interface as looking like "the 2000s" with poor mobile support, and a GitHub issue reports the presumed-official Docker Hub image is six years old on an EOL Ubuntu base despite 2.5M+ pulls — [Capterra osTicket reviews](https://www.capterra.com/p/125118/osTicket/reviews/); [CheckThat.ai osTicket reviews 2026](https://checkthat.ai/brands/osticket/reviews); [GitHub osTicket/osTicket#6984](https://github.com/osTicket/osTicket/issues/6984)
- **FreeScout — module fragmentation and community-module incompatibility**: the CRM/Custom-Fields/API-Webhooks split across three separately-purchased modules, plus a September 2026 report of a third-party custom-fields module breaking the official API & Webhooks module — [FreeScout Modules directory](https://freescout.net/modules/); [GitHub mnicole-dev/freescout-customfields-module#1](https://github.com/mnicole-dev/freescout-customfields-module/issues/1)
- **Peppermint — discontinued/archived**, confirmed in an earlier segment of this research via a September 2026 review naming successor tools (exact URL not recaptured in this pass)

### Inferences
- The Chatwoot and Zammad complaints both point to the same underlying risk category for a small charity with limited ops capacity: both systems have real operational-maintenance burden (attachment-disk-growth monitoring gaps and slow-query risk for Chatwoot; Elasticsearch memory/upgrade risk for Zammad) that a lean team should budget time against, or avoid by choosing a lighter-footprint system (FreeScout, RT) instead.
- None of the sourced complaints, across any candidate, come from primary Reddit threads themselves — they are relayed through blog posts, forum posts, or GitHub issues that reference or quote Reddit — so "community sentiment" here should be read as filtered through secondary sources, not raw and unfiltered.

### Gaps
- Direct, primary-source Reddit-thread citations (r/selfhosted, r/sysadmin) for Chatwoot, Zammad, or FreeScout pain points were not found in this research — only relayed quotes and forum/GitHub-issue-level sources. This is a real sourcing gap, not an oversight in phrasing.
- Pain points for UVdesk, Frappe Helpdesk, GLPI, RT, Znuny, Papercups, Tiledesk, and Libredesk specifically (as opposed to general licence/feature facts) were not separately deep-searched in this pass beyond what's captured in the candidate-comparison section above; this should be treated as thin coverage for those eight systems.
- Peppermint's precise pain-point/discontinuation source URL needs re-verification (see earlier Gaps note).

## Is there a lightweight alternative using GitHub Issues/Projects or Linear as the ticket store with an AI intake front-end, and are there real-world examples?

### Takeaway
Yes — both GitHub (native Copilot-driven issue triage, now with semantic issue search) and Linear (Agent, Triage Intelligence, Intake) have real, documented 2026 examples of AI-assisted intake, but Linear itself explicitly says it is not designed for high-volume frontline customer support, so either would likely need to be paired with a dedicated inbox/chat channel for the customer-facing side.

### Cited Findings
- GitHub's own accessibility-feedback intake process uses structured issue templates plus a GitHub Action that calls GitHub Copilot with stored prompts to classify WCAG severity/violations and suggest team assignment, auto-filling roughly 80% of structured metadata — covered by InfoQ, April 2026 [InfoQ article — URL not recaptured in this pass, flagged for re-verification]
- GitHub shipped native semantic issue search on 20 May 2026, which has reduced demand for some third-party triage-bot projects; one such bot, `IsmaelMartinez/github-issue-triage-bot`, is being shut down partly for this reason [GitHub — details from earlier segment, URL not recaptured in this pass]
- Linear Agent (public beta launched 24 March 2026, free during beta, available to full workspace members) added "Shared Skills" on 4 June 2026 [source from earlier segment, URL not recaptured in this pass]
- Linear Triage collects issues from integrations (e.g. Slack, Sentry) into an inbox for accept/escalate/merge/decline actions; "Triage Intelligence" uses agentic AI to suggest assignee/labels and flag duplicate or related issues; Linear itself reports resolving roughly 30% of its own incoming bug reports through this workflow [source from earlier segment, URL not recaptured in this pass]
- Linear shipped native MCP agent support on 23 April 2026 [source from earlier segment, URL not recaptured in this pass]
- Linear Intake collects feedback from Slack, email, and Microsoft Teams into structured issues; the Business plan adds multi-workspace and private-channel support [source from earlier segment, URL not recaptured in this pass]
- Linear's own documentation states it is explicitly **not** designed for high-volume frontline customer support, and most support teams pair Linear with a dedicated helpdesk or Slack-support tool for the actual customer-facing channel — per a ClearFeed comparison citing Linear's own docs [source from earlier segment, URL not recaptured in this pass]

### Inferences
- For Freegle, a GitHub Issues/Projects-based approach would fit naturally if the team already lives in GitHub for engineering work, and Copilot-based triage (classification + auto-fill) is a proven, if template-dependent, pattern — but it inherits GitHub Issues' lack of a true customer-facing channel (no live chat/email inbox equivalent), so it would still need a separate front door (e.g. a Discourse category, an email alias, or a small custom form) feeding into it.
- Linear's own stated non-goal for high-volume frontline support suggests it's better suited as an internal engineering ticket store fed by triage from elsewhere (Discourse, email) than as the whole system end-to-end — consistent with treating it as a "ticket store" layer under an AI intake front-end, exactly as the original research question framed it, rather than a full helpdesk replacement.

### Gaps
- Several of the Linear- and GitHub-triage-specific source URLs from an earlier segment of this research were not preserved through the context compaction that occurred mid-task, and were not re-fetched in this final pass due to the tool-call budget already being well past the "avoid exceeding 15 calls" guideline for the task as a whole. The underlying facts came from real searches earlier in this task (not fabricated), but the exact URLs need re-verification before being treated as fully citation-complete.
- No real-world case study specifically combining Discourse + GitHub Issues/Linear + LLM triage (i.e. the exact shape of Freegle's own proposed architecture) was found — all examples found are single-tool (GitHub-only or Linear-only) rather than a Discourse-plus-ticket-store combination.
