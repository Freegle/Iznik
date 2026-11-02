/**
 * Reuse benefit calculation with inflation adjustment.
 *
 * The base benefit of reuse value (£711 per tonne) comes from the WRAP
 * "Benefits of Reuse" tool, originally published in 2011.
 * https://www.wrap.ngo/resources/tool/benefits-of-reuse-tool
 *
 * To express this value in current prices, we up-rate by UK CPI inflation.
 * CPI data source: ONS Consumer Price Index (2015=100)
 * https://www.ons.gov.uk/economy/inflationandpriceindices/timeseries/d7bt/mm23
 *
 * The CO2 impact (0.51 tCO2eq per tonne) is not adjusted for inflation as it
 * represents physical quantities, not monetary values.
 *
 * The benefit shown on the stats pages also includes the fly-tipping clear-up
 * costs that councils avoid, uprated the same way. See the fly-tipping section
 * below and docs/developers/reference/impact-figures.md.
 */

/* UK CPI Annual Averages (2015=100)
 * Source: ONS MM23 dataset, series D7BT
 * Last updated: November 2026 (2025 is the latest full year)
 *
 * The series starts in 2003 because the fly-tipping clearance costs are in
 * 2003-06 prices. The WRAP value is in 2011 prices, so the earlier years do
 * not move it.
 *
 * To update: Visit the ONS URL above, download data, and add the new year's value.
 */
const CPI_DATA = {
  2003: 75.5,
  2004: 76.5,
  2005: 78.1,
  2006: 79.9,
  2007: 81.8,
  2008: 84.7,
  2009: 86.6,
  2010: 89.4,
  2011: 93.4,
  2012: 96.1,
  2013: 98.5,
  2014: 100.0,
  2015: 100.0,
  2016: 100.7,
  2017: 103.4,
  2018: 105.9,
  2019: 107.8,
  2020: 108.7,
  2021: 111.6,
  2022: 121.7,
  2023: 130.5,
  2024: 133.9,
  2025: 138.4,
}

/* Base year for the WRAP benefits of reuse value. */
const BASE_YEAR = 2011

/* Base benefit of reuse value in GBP per tonne (2011 prices). */
const BASE_BENEFIT_PER_TONNE = 711

/* CO2 impact per tonne (tCO2eq) - not inflation adjusted. */
const CO2_PER_TONNE = 0.51

/* Fly-tipping clear-up costs avoided.
 *
 * From a 2020 analysis of Freegle's 2019 data (method statement and R script
 * Freegle_fly_tipping.R). It assumed that the share of people who fly-tip
 * would otherwise have fly-tipped the items they gave away, and costed each
 * one at a council's standard clearance cost.
 *
 * FLYTIP_SHARE: a Keep Britain Tidy survey of 1,000 Londoners (2018) found
 * 26, 21, 16 and 15 people had fly-tipped a TV, oven, mattress or sofa in the
 * last two years. The mean, 19.5 in 1,000, is 1.95%.
 *
 * FLYTIP_COST: Defra's standard clearance cost for "other single item" and
 * for "car boot load or less", both £29 (ENV24 fly-tipping statistics, notes
 * tab). Defra based these on council data from 2003 to 2006, so they are
 * uprated from the average price level of those years.
 *
 * Over the 289,944 items offered in 2019 this gives about £164,000 in 2003-06
 * prices, against the analysis' own £162,697 mean (range £112,444 to
 * £234,571).
 */
const FLYTIP_SHARE = 0.0195
const FLYTIP_COST = 29
const FLYTIP_COST_YEARS = [2003, 2004, 2005, 2006]

/**
 * Get the CPI value for a given year.
 * Returns the latest available year if the requested year is in the future.
 * Returns the earliest available year if the requested year is before 2003.
 */
function getCPI(year) {
  const years = Object.keys(CPI_DATA)
    .map(Number)
    .sort((a, b) => a - b)
  const minYear = years[0]
  const maxYear = years[years.length - 1]

  if (year < minYear) {
    return CPI_DATA[minYear]
  }
  if (year > maxYear) {
    return CPI_DATA[maxYear]
  }
  return CPI_DATA[year]
}

/**
 * Calculate the inflation multiplier from the base year to the target year.
 */
function getInflationMultiplier(targetYear = null) {
  const year = targetYear || new Date().getFullYear()
  const baseCPI = getCPI(BASE_YEAR)
  const targetCPI = getCPI(year)
  return targetCPI / baseCPI
}

/**
 * Get the inflation-adjusted benefit per tonne in GBP.
 * By default, adjusts to current year prices.
 */
function getBenefitPerTonne(targetYear = null) {
  const multiplier = getInflationMultiplier(targetYear)
  return Math.round(BASE_BENEFIT_PER_TONNE * multiplier)
}

/**
 * Calculate the total benefit in GBP for a given weight in tonnes.
 * Weight should be in tonnes (not kg).
 */
function calculateBenefit(weightInTonnes, targetYear = null) {
  return weightInTonnes * getBenefitPerTonne(targetYear)
}

/**
 * Calculate the CO2 saved for a given weight in tonnes.
 * Returns tCO2eq. Weight should be in tonnes.
 */
function calculateCO2(weightInTonnes) {
  return weightInTonnes * CO2_PER_TONNE
}

/**
 * Get the latest year for which we have CPI data.
 */
function getLatestCPIYear() {
  const years = Object.keys(CPI_DATA).map(Number)
  return Math.max(...years)
}

/**
 * The cost to a council of clearing one fly-tip, in GBP, uprated from
 * 2003-06 prices. By default, adjusts to current year prices.
 */
function getFlyTipCost(targetYear = null) {
  const year = targetYear || new Date().getFullYear()
  const baseCPI =
    FLYTIP_COST_YEARS.reduce((sum, y) => sum + getCPI(y), 0) /
    FLYTIP_COST_YEARS.length
  return (FLYTIP_COST * getCPI(year)) / baseCPI
}

/**
 * The fly-tipping clear-up cost avoided for each item given away, in GBP.
 */
function getFlyTipSavingPerItem(targetYear = null) {
  return FLYTIP_SHARE * getFlyTipCost(targetYear)
}

/**
 * The fly-tipping clear-up cost avoided for a number of items given away.
 */
function calculateFlyTipSaving(items, targetYear = null) {
  if (!Number.isFinite(items)) {
    return 0
  }
  return items * getFlyTipSavingPerItem(targetYear)
}

/**
 * A short note saying how much of a benefit figure is the fly-tipping saving,
 * and where that comes from. Shown as a footnote beside the figure.
 */
function describeFlyTipSaving(saving) {
  const amount = Math.round(saving || 0).toLocaleString()
  return (
    `Includes £${amount} saved on clearing up fly-tipping, based on a 2020 ` +
    'study of Freegle data by DEFRA volunteers.'
  )
}

export function useReuseBenefit() {
  return {
    BASE_YEAR,
    BASE_BENEFIT_PER_TONNE,
    CO2_PER_TONNE,
    FLYTIP_SHARE,
    FLYTIP_COST,
    FLYTIP_COST_YEARS,
    getCPI,
    getInflationMultiplier,
    getBenefitPerTonne,
    calculateBenefit,
    calculateCO2,
    getLatestCPIYear,
    getFlyTipCost,
    getFlyTipSavingPerItem,
    calculateFlyTipSaving,
    describeFlyTipSaving,
  }
}

/* Also export individual functions for direct import. */
export {
  BASE_YEAR,
  BASE_BENEFIT_PER_TONNE,
  CO2_PER_TONNE,
  FLYTIP_SHARE,
  FLYTIP_COST,
  FLYTIP_COST_YEARS,
  getCPI,
  getInflationMultiplier,
  getBenefitPerTonne,
  calculateBenefit,
  calculateCO2,
  getLatestCPIYear,
  getFlyTipCost,
  getFlyTipSavingPerItem,
  calculateFlyTipSaving,
  describeFlyTipSaving,
}
