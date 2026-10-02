# What counts as EEE: the three candidate definitions, and which we use

**Date**: 2026-08-25
**Question**: the EEE classifier assumes an item is only EEE if its primary function would not
work without electricity. Is that the legitimate distinction?
**Answer**: no. It is one reading among three, it is not the one the guidance actually applies to
named products, and for anything the guidance does not name there is no definitive government
position at all.
**Decision**: use the Material Focus line. Anything with a plug, battery or cable.

Worked examples that prompted this: a baby bouncer (primary function is bouncing a baby) with a
built-in music player, and a fish tank (primary function is holding fish) with a built-in pump.

---

## Summary

| | Position A: primary function | Position B: fundamental feature vs novelty | Position C: Material Focus |
|---|---|---|---|
| Test | electricity must power the item's basic function | electricity must deliver a substantive function, novelty does not count | anything with a plug, battery or cable |
| Fish tank with pump | NOT EEE | EEE | EEE |
| Baby bouncer with music | NOT EEE | undetermined | EEE |
| Gas cooker | NOT EEE | NOT EEE | EEE |
| In the legislation? | no | no | no |
| In the guidance as a stated rule? | yes, as one sentence | no, synthesised from example lists | no |
| Agrees with named products? | **no, contradicts fish tanks and spa baths** | yes | mostly, contradicts gas cookers |
| Currently implemented | yes | no | partly, in the delivered dataset |

---

## The legal position, as far as it goes

The statutory definition is identical in the [WEEE Regulations 2013 reg 2](https://www.legislation.gov.uk/uksi/2013/3113/regulation/2)
and [WEEE Directive 2012/19/EU Art 3(1)(a)](https://www.legislation.gov.uk/eudr/2012/19/article/3/data.htm?view=plain):

> equipment which is dependent on electric currents or electromagnetic fields in order to work
> properly and equipment for the generation, transfer and measurement of such currents and fields
> and designed for use with a voltage rating not exceeding 1,000 volts for alternating current and
> 1,500 volts for direct current

Two things follow, and both matter.

**Neither instrument defines "dependent".** The whole argument turns on that one word, and the WEEE
legislation leaves it undefined. That is the root of the ambiguity, not a failure to read the
guidance carefully.

**The "at least one intended function" wording that gets quoted in this argument is not WEEE law.**
It is RoHS: [UK RoHS Regulations 2012 reg 4](https://legislation.gov.uk/uksi/2012/3032/regulation/4/2020-12-31?view=plain)
and EU RoHS 2 Art 3(2) define *dependent* as "needing electric currents or electromagnetic fields to
fulfil at least one intended function". RoHS and WEEE share the identical definition of EEE but only
RoHS defines the operative word, and it defines it broadly. Anyone citing "at least one intended
function" as settling the WEEE question is citing the wrong instrument. It is still informative as to
what the drafters meant by the shared phrase, but it is not binding here.

Since open scope (EU 15 August 2018, UK 1 January 2019) the category filter is gone: all EEE is in
scope unless specifically excluded. Before that, an item could escape by not fitting one of the ten
categories, which is how several older rulings were reached. Those rulings no longer follow from
their stated reasons.

---

## Position A: the primary function test

**What it says.** Electricity must power the item's basic function. Turn the power off and the item
cannot do its main job.

**Where it comes from.** Straight out of the current guidance,
[Electrical and electronic equipment (EEE) covered by the WEEE Regulations](https://www.gov.uk/government/publications/electrical-and-electronic-equipment-eee-covered-by-the-weee-regulations/electrical-and-electronic-equipment-eee-covered-by-the-weee-regulations)
(updated 12 August 2025):

> "'Dependent on electric currents or electromagnetic fields to work properly' means that the
> equipment needs electric currents or electromagnetic fields (not petrol or gas) to fulfil its
> basic function. So when the electric current is off, the equipment cannot fulfil its basic
> function."

> "Where electrical energy is only used for support or control functions, the equipment is not
> covered by the regulations."

### Pros

- It is stated in the guidance in terms, not inferred.
- It is the only one of the three positions that produces the government's own answer on gas
  cookers, petrol lawn mowers and gas stoves, all of which are named as not EEE.
- It is conceptually clean and cheap to implement. It is what the classifier already does.
- It is conservative. It will not inflate a reuse figure.

### Cons

- **The same guidance contradicts it two paragraphs later**: "Where a product has several functions
  and only one needs an electrical current, the product may still be EEE." That sentence cannot be
  reconciled with a pure primary-function test. The 2017 predecessor was blunter still: "the fact
  that one function may not require an electrical current is unlikely to mean the product is out of
  scope."
- **It gets named products wrong.** Fish tanks are named as EEE: "report the whole weight of a tank
  with a light, heater or pump supplied as a single unit". A fish tank holds fish without a pump. So
  do spa baths without jets, loft ladders without electronic controls, roller screens without
  winders, and gym tops without heart-rate monitors. Every one of those is named as EEE and every one
  fails the primary-function test.
- It systematically undercounts, which is the damaging direction for a reuse dataset.

---

## Position B: fundamental feature versus novelty

**What it says.** Electricity making a substantive contribution to what the product is and does
makes it EEE. Decorative or novelty electrics do not.

**Where it comes from.** Both terms are in the guidance, but only ever as labels on examples, never
as a stated rule. The clearest pair, in the same document:

> IN: "clothing where it includes a **fundamental feature** which needs electricity to function,
> such as a gym top with a heart rate monitor, heated walking jacket or a hat with integral speakers"

> OUT: "clothing with **novelty** lights or sound (or both) - they can still work properly as
> clothing without the electrical functions"

Also in scope: fish tanks, spa baths, loft ladders with electronic controls, roller screens with
electronic winders, riser chairs and hospital beds with movement controls, office desks with
speakers or integral smart features, furniture with an integral fridge, gym equipment. Also out:
furniture with a USB charging point, musical greetings cards, novelty items with a single LED, play
tents or toys with fairy lights, taps with decorative lights, water filter jugs with an indicator
display.

The 2017 version gave the reasoning explicitly for spa baths: "Although the main function of these
items is a bath, a large part of the function and feature of the product is based on the added value
provided by the electrical functions."

### Pros

- It is the only one of the three that reproduces the guidance's actual answers on both the in-scope
  and out-of-scope named lists. Nothing else fits both.
- Its two key terms are in the guidance text, not invented.

### Cons

- **It is nowhere stated as a rule.** It is a pattern reverse-engineered from example lists. Applying
  it to unlisted products is inference, and that inference is doing all the work.
- **Neither term is defined.** "Fundamental" and "novelty" are exactly as vague as the word they were
  brought in to explain, and there is no threshold to apply.
- **The guidance's own justification for the novelty exclusions is Position A.** It excludes novelty
  clothing because "they can still work properly as clothing without the electrical functions",
  which is basic-function reasoning. So the document uses the primary-function test to reach the
  exclusions while ignoring it to reach the inclusions. The inconsistency is in the source, not in
  the reading of it.
- Because it needs a human judgement on every unlisted item, it is the hardest of the three to
  implement consistently and the easiest to argue with.

---

## Position C: the Material Focus line

**What it says.**

> "All electricals must be recycled under the WEEE Regulations - anything with a plug, battery or
> cable."

From [Material Focus](https://materialfocus.org.uk/weee-regulations/) and
[Recycle Your Electricals](https://recycleyourelectricals.org.uk/about-material-focus/weee-regulations/).
Their category list includes "toys with electronic components".

### Pros

- **It is the definition the customer for this data uses.** Material Focus is the recipient. Cutting
  the numbers to someone else's definition and handing them the result is the worse error.
- **It is already the definition we shipped.** The delivered dataset was built with this prompt
  (`Freegle/material-focus`, `scripts/annotate_llm.py`):

  > Electrical = runs on mains power, batteries, or is an electrical component/accessory.
  > Include: appliances, electronics, power tools, cables, chargers.
  > Exclude: books, manual tools, clothing, food, furniture, toys (unless battery/electric), plants.
  > Sewing machines: YES (benefit of doubt). Home gym: NO (assume weights, not powered).
  > Light shade: NO. Fairy lights: YES.

  Note "toys (unless battery/electric)" and "Fairy lights: YES". Both of the worked examples are
  already YES under the definition behind the numbers Material Focus has.
- It is unambiguous and needs no per-item judgement, so it is reproducible and auditable.
- It is consistent with the direction of travel. Open scope widened the net, and Material Focus's own
  880 million unused electricals figure explicitly counts cables, hairdryers and Christmas lights.
- It matches the actual purpose. We are measuring reuse flow, not producer placed-on-market
  obligations.

### Cons

- **Material Focus publish no justification for it.** There is no methodology note, no scope annex,
  no definitional document. It is a campaign message, asserted, and it is phrased as though it *were*
  the regulatory requirement ("must be recycled under the WEEE Regulations - anything with a plug,
  battery or cable") when it is a simplification of one. Searched: their WEEE regulations pages, the
  publications index, the e-waste briefing 2025 and the data trends report. None defines scope.
- **It contradicts the government on the one point where the government is definitive.** A gas cooker
  has a plug and electrical components, so the line counts it; the guidance names it as not EEE.
  Same for petrol lawn mowers.
- It is the broadest of the three, so it produces the largest numbers, and a reader who assumes
  strict WEEE producer scope will read those numbers as inflated.

---

## Decision

**Use the Material Focus line, with the named government exceptions hard-coded.**

Reasoning, in order:

1. There is no clear and definitive government position on unlisted products. The guidance's stated
   rule and its worked examples disagree with each other, and the
   [WEEE Forum](https://weee-forum.org/wp-content/uploads/2019/06/open_scope_issue_paper_final-1.pdf)
   confirms this is a live grey area rather than a settled question we simply failed to look up:
   "some products stay in the so called 'grey areas' and may be subject to different interpretations
   by the competent authorities of member states", and "products such as furniture and clothes with
   electronic components may now become EEE, depending on how strict the interpretation is."
2. Where there is no definitive government position, the customer's definition wins. Material Focus
   is the recipient of this data.
3. It is what we already shipped, so adopting it formally makes the delivered numbers and the stated
   method agree, rather than introducing a third variant.
4. Position A is positively wrong on named products, so it cannot stand regardless.
5. Position B is a defensible reading but rests on an inference the sources do not license, and it
   cannot be applied consistently without a per-item human call.

Where the guidance names a product, follow the guidance. That is a short list and it is the only part
of the government material that is actually definitive:

- **Named EEE**: fish tanks with a light, heater or pump; spa and hydrotherapy baths; loft ladders
  with electronic controls; roller screens with electronic winders; riser chairs and hospital beds
  with movement controls; gym equipment; games consoles; furniture with an integral fridge.
- **Named not EEE**: gas cookers whose electrics are only a clock or igniter; petrol lawn mowers and
  gas stoves needing only a spark.

Everything else: plug, battery or cable.

### The two worked examples, decided

- **Fish tank with a built-in pump: EEE.** Named in the guidance, and the Material Focus line agrees.
  No judgement required. The current classifier gets this wrong.
- **Baby bouncer with a built-in music player: EEE.** Not named, so the Material Focus line applies,
  and it has a battery. Also already YES under the shipped prompt's "toys (unless battery/electric)".

### Caveat to record in anything published

Two different definitions are in play and they give materially different totals. The strict producer
placed-on-market scope is narrower than the Material Focus campaign line. Any figure we publish
should say which one it uses. This should be confirmed with Material Focus before the next re-run,
because it changes the headline.

---

## What this means for the classifier

The current rule in `iznik-batch/app/Services/EeeComponentService.php` splits observed components
into `primary_eee` (proves the primary function is electrical) and `supplementary_eee` (incidental),
and only the former sets `is_eee`. That encodes Position A, so it needs changing.

Required:

1. **Fix a live bug.** `filter pump` classifies as `non_electrical`, because the passive
   `/\bfilter\b/` rule in `non_electrical` swallows it. A fish tank with a pump therefore comes out
   as containing no electrical components at all, which is the exact case the guidance names as in
   scope. Independent of which definition we adopt.
2. **Stop treating a non-electrical primary function as decisive.** Under the Material Focus line,
   any electrical component present is enough, so `supplementary_eee` should set `is_eee` true rather
   than deferring, except for the named exceptions.
3. **Hard-code the named exceptions**, driven off the text signal: gas cooker, petrol lawn mower, gas
   stove.
4. **Do not add a novelty category.** That was the Position B inference and it has no government
   basis. It would also contradict the shipped prompt, which counts fairy lights.

Wrong expected answers to correct in `plans/eee-identification.md`:

- `:11` and `:43` define the rule as primary function. Restate as the Material Focus line plus named
  exceptions.
- `:125` rules a wardrobe with an internal light not EEE. Under the adopted line it is EEE.
- `:681` and `:1040` score an exercise bike with a digital console as not EEE. Gym equipment is named
  as EEE, and the line agrees. The model was right and the expected answer was wrong.
- `:114` on petrol chainsaws is correct and stands.

A branch exists at `fix/eee-scope-distinct-function-test` with a partial implementation of Position B.
It contains the `filter pump` fix, which is worth keeping. The rest encodes the rejected position and
should be dropped.

Re-running the classifier over the existing sample is the step that changes published dashboard
numbers, and it needs a spend decision, so it is not done.

---

## Sources

- [Electrical and electronic equipment (EEE) covered by the WEEE Regulations](https://www.gov.uk/government/publications/electrical-and-electronic-equipment-eee-covered-by-the-weee-regulations/electrical-and-electronic-equipment-eee-covered-by-the-weee-regulations), Environment Agency, updated 12 August 2025. The operative UK guidance.
- [Scope of equipment covered by the UK WEEE Regulations](http://www.recolight.co.uk/wp-content/uploads/Scope-of-equipment-covered-by-the-UK-Waste-Electrical-and-Electronic-Equipment-WEEE-Regulations_2017.pdf), October 2017. Superseded, but contains the fuller reasoning on spa baths and multifunctional products.
- [WEEE Regulations 2013 reg 2](https://www.legislation.gov.uk/uksi/2013/3113/regulation/2) and [Directive 2012/19/EU Art 3](https://www.legislation.gov.uk/eudr/2012/19/article/3/data.htm?view=plain). Statutory definition. Neither defines "dependent".
- [RoHS Regulations 2012 reg 4](https://legislation.gov.uk/uksi/2012/3032/regulation/4/2020-12-31?view=plain). Source of the "at least one intended function" wording.
- [WEEE Regulations Government Guidance Notes](https://assets.publishing.service.gov.uk/media/5a7c603ced915d6969f4470e/bis-14-604-weee-regulations-2013-government-guidance-notes.pdf), BIS. Uses "primary function" only to assign categories, never to decide scope.
- [Implications of the open scope of Directive 2012/19/EU](https://weee-forum.org/wp-content/uploads/2019/06/open_scope_issue_paper_final-1.pdf), WEEE Forum, 2018. Confirms the grey area and the member-state divergence.
- [Material Focus, WEEE Regulations](https://materialfocus.org.uk/weee-regulations/) and [Recycle Your Electricals](https://recycleyourelectricals.org.uk/about-material-focus/weee-regulations/). The "plug, battery or cable" line, asserted without justification.
- `Freegle/material-focus`, `scripts/annotate_llm.py`. The definition actually used to build the delivered dataset.
