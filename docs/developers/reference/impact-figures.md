---
last_reviewed: 2026-11-02
covers:
  - iznik-nuxt3/composables/useReuseBenefit.js
  - iznik-nuxt3/components/StatsImpact.vue
  - iznik-nuxt3/pages/stats/[[groupname]].vue
  - iznik-nuxt3/pages/stats/authority/[[id]].vue
---

# Impact figures on the stats pages

The public stats page (`/stats`, or `/stats/<community>`) and the council pages
(`/stats/authority/<id>`) show three headline figures: tonnes reused, the benefit in
pounds, and tonnes of CO2 saved. The maths is in `composables/useReuseBenefit.js`; the
figures are drawn by `components/StatsImpact.vue`.

The stats page totals cover the 12 complete months before the current one, or the financial
year asked for with `?financialYear=FY2024`, from the first day to the last.

## Tonnes and CO2

Tonnes come from the `Weight` dashboard series, which estimates the weight of each item
given away. CO2 is 0.51 tonnes per tonne reused, from WRAP. It is a physical quantity, so
it is not adjusted for inflation.

## Benefit

The benefit is the sum of two parts, both in current prices:

1. **The benefit of reuse**: £711 a tonne in 2011 prices, from WRAP's Benefits of Reuse
   tool, uprated by CPI from 2011 (`getBenefitPerTonne`).
2. **Fly-tipping clear-up costs avoided**: about £1 for each item given away, at 2025
   prices (`calculateFlyTipSaving`). It is counted on gifts, the `Outcomes` series, which
   counts offers taken and wanteds received. A footnote under the figure says how much of
   the benefit this part is.

### Where the fly-tipping figure comes from

A Defra statistician analysed Freegle's 2019 data for us in 2020, using a method statement
and an R script (`Freegle_fly_tipping.R`) kept with Freegle's records. It made three
assumptions:

- **Share who fly-tip: 1.95%.** A 2018 Keep Britain Tidy survey of 1,000 Londoners found 26,
  21, 16 and 15 people had fly-tipped a TV, an oven, a mattress or a sofa in the previous two
  years. The mean is 19.5 in 1,000. The method statement prints the range as "17.55% to
  21.45%"; that is a slip for 1.755% to 2.145%, and the script uses 1.95%.
- **People who fly-tip would have fly-tipped what they gave away.** The script picked that
  share of the people who offered items in 2019 at random and counted their items.
- **Each fly-tip costs £29 to clear.** That is Defra's standard cost for both "other single
  item" and "car boot load or less" (ENV24 fly-tipping statistics, notes tab). Defra based it
  on council data from 2003 to 2006, so we uprate it from the average CPI of those four
  years.

On the 289,944 items offered in 2019, the analysis gave £167,910 for one run and a mean of
£162,697 over 10,000 runs. At exactly 1.95% the same data gives £163,963. The unit test
`reproduces the 2019 result in its own prices` checks this.

Two choices here differ from the 2019 analysis:

- We uprate the £29 for inflation. The analysis did not, so its result is in 2003-06 prices.
- We count gifts, not offers. An offer nobody took was not kept out of a fly-tip by Freegle.

## Inflation

`CPI_DATA` holds the ONS CPI annual averages (series D7BT, 2015=100) from 2003 to 2025.
Any later year is priced at the latest year we hold. Add each new year when ONS publishes
it in January.

The Go item impact endpoint reads the series from the `cpi_annual_data` config row, which
`data:update-cpi` refreshes monthly. The batch's council spreadsheet uses the copy in
`iznik-batch/app/Support/ReuseBenefit.php`, and the Go code falls back to the copy in
`iznik-server-go/item/reusebenefit.go`. Both copies run from 2011 to 2024 and leave out
fly-tipping.
