# Category-search agent brief (fan out 12, one per category)

Fill the `{{...}}` slots and dispatch one agent per category (Workflow tool or parallel Agent calls). Each agent has web search/fetch. Each writes its results to `{{outdir}}/NN-<category>.md`.

---

You are researching **one category** of community organisation that could take donated items in a specific area, for a bulk reuse clearance. Be rigorous: a wrong inclusion can cause a real misfired email, so only include organisations you can evidence.

**Target geography:** {{geography, e.g. "Brighton & Hove" / a postcode set / an LA}}
**Your category:** {{one of the 12, e.g. "Food banks, pantries, community kitchens, cafes"}}
**Items available, by cluster:** {{the donor's item clusters and a one-line of each, e.g. "Kitchen: large fridge (excellent). Hall seating: 40 stacking chairs. Storage: 6 lockable cabinets."}}

## What to do
1. Discover candidate organisations in your category operating in the geography. Use web search with seeded queries (`"{{category phrase}}" {{place}}`, `"{{place}}" {{synonym}} drop-in`, etc.), plus any local CVS / Community-Works / council community directory as a CANDIDATE source only.
2. For each candidate, apply the **hard gates** - include ONLY if all three hold:
   - **Activity:** a specific dated URL within the last 12 months showing operations in the geography (own site news/post, dated social post, local news, dated event/job ad). Record the URL, the date, and a one-line description.
   - **Email:** a published email on the website or social "about" - not a contact form, not phone-only. Record it; note if it's a dedicated role vs a generic info@/office@.
   - **Reality:** genuine local operating presence, not a convenience/registered address for an entity based elsewhere.
   - Registers (Charity Commission/Companies House/GIAS) are NOT activity evidence - use only to confirm the entity is real once otherwise found.
3. Match each included org to the item cluster(s) that best fit its mission, and write one sentence on WHY it fits (what they'd actually use it for).
4. Assign **confidence** (high/med/low) in your inclusion, based on how strong and recent the evidence is.

## Output (write to {{outdir}}/NN-{{category}}.md)
Two tables.

**Included** - columns: Name | Website | Email | Postcode/Area | Activity evidence (URL + date + one-line) | Item-cluster fit | Why they fit | Confidence (high/med/low)

**Rejected** - columns: Name | Why rejected (e.g. "no dated activity in 12 months", "contact form only", "registered in {{place}} but operates in London", "dormant - last post 2019"). This audit trail matters: it powers the false-negative analysis after the clearance.

## Rules
- Do NOT include individuals, only organisations.
- Do NOT draft or send any email - this is research only; a human decides who to contact.
- Prefer fewer, well-evidenced inclusions over a long thin list. If you cannot find activity evidence, REJECT with the reason - do not guess.
- Aim to surface organisations a generic public post would miss; that is the whole point of Tier-2 discovery.
