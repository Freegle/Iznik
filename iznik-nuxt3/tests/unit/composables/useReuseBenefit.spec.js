import { describe, it, expect } from 'vitest'
import {
  BASE_YEAR,
  BASE_BENEFIT_PER_TONNE,
  CO2_PER_TONNE,
  getCPI,
  getInflationMultiplier,
  getBenefitPerTonne,
  calculateBenefit,
  calculateCO2,
  getLatestCPIYear,
  FLYTIP_SHARE,
  FLYTIP_COST,
  FLYTIP_COST_YEARS,
  getFlyTipCost,
  getFlyTipSavingPerItem,
  calculateFlyTipSaving,
  describeFlyTipSaving,
  useReuseBenefit,
} from '~/composables/useReuseBenefit'

describe('useReuseBenefit', () => {
  describe('constants', () => {
    it('exposes WRAP base values', () => {
      expect(BASE_YEAR).toBe(2011)
      expect(BASE_BENEFIT_PER_TONNE).toBe(711)
      expect(CO2_PER_TONNE).toBe(0.51)
    })
  })

  describe('getCPI', () => {
    it('returns the CPI value for a known year', () => {
      expect(getCPI(2015)).toBe(100.0)
      expect(getCPI(2011)).toBe(93.4)
      expect(getCPI(2022)).toBe(121.7)
    })

    it('holds the years the fly-tipping costs were measured in', () => {
      // The Defra clearance costs are from 2003-2006, so the series has to
      // reach back that far to uprate them.
      expect(getCPI(2003)).toBe(75.5)
      expect(getCPI(2004)).toBe(76.5)
      expect(getCPI(2005)).toBe(78.1)
      expect(getCPI(2006)).toBe(79.9)
    })

    it('clamps to the earliest year (2003) for earlier requests', () => {
      expect(getCPI(2000)).toBe(getCPI(2003))
      expect(getCPI(1980)).toBe(75.5)
    })

    it('clamps to latest year for future requests', () => {
      const latestYear = getLatestCPIYear()
      const latestCPI = getCPI(latestYear)
      expect(getCPI(latestYear + 1)).toBe(latestCPI)
      expect(getCPI(3000)).toBe(latestCPI)
    })
  })

  describe('getLatestCPIYear', () => {
    it('returns the max known year', () => {
      expect(getLatestCPIYear()).toBe(2025)
      expect(getCPI(2025)).toBe(138.4)
    })
  })

  describe('getInflationMultiplier', () => {
    it('returns 1 for the base year', () => {
      expect(getInflationMultiplier(BASE_YEAR)).toBe(1)
    })

    it('compounds up through the CPI series', () => {
      const m2015 = getInflationMultiplier(2015)
      const m2024 = getInflationMultiplier(2024)
      expect(m2015).toBeCloseTo(100.0 / 93.4, 6)
      expect(m2024).toBeCloseTo(133.9 / 93.4, 6)
      expect(m2024).toBeGreaterThan(m2015)
    })

    it('defaults to the current year when no argument given', () => {
      const currentYear = new Date().getFullYear()
      const latestYear = getLatestCPIYear()
      const expectedYear = currentYear > latestYear ? latestYear : currentYear
      const expectedCPI = getCPI(expectedYear)
      const multiplier = getInflationMultiplier()
      expect(multiplier).toBeCloseTo(expectedCPI / 93.4, 6)
    })
  })

  describe('getBenefitPerTonne', () => {
    it('rounds to an integer', () => {
      const value = getBenefitPerTonne(2024)
      expect(Number.isInteger(value)).toBe(true)
    })

    it('equals the base at the base year', () => {
      expect(getBenefitPerTonne(2011)).toBe(711)
    })

    it('scales with inflation — 2024 value is higher than 2015', () => {
      expect(getBenefitPerTonne(2024)).toBeGreaterThan(getBenefitPerTonne(2015))
    })

    it('uses the latest year value for future years', () => {
      expect(getBenefitPerTonne(3000)).toBe(
        getBenefitPerTonne(getLatestCPIYear())
      )
    })

    it('is not moved by the CPI years before the WRAP base year', () => {
      expect(getBenefitPerTonne(2025)).toBe(Math.round((711 * 138.4) / 93.4))
    })
  })

  describe('calculateBenefit', () => {
    it('multiplies weight by inflation-adjusted benefit', () => {
      const perTonne2024 = getBenefitPerTonne(2024)
      expect(calculateBenefit(1, 2024)).toBe(perTonne2024)
      expect(calculateBenefit(2.5, 2024)).toBeCloseTo(perTonne2024 * 2.5, 6)
    })

    it('returns 0 for zero weight', () => {
      expect(calculateBenefit(0, 2024)).toBe(0)
    })

    it('uses current year when targetYear is null', () => {
      expect(calculateBenefit(1)).toBe(getBenefitPerTonne())
    })
  })

  describe('calculateCO2', () => {
    it('uses fixed physical ratio, not inflation-adjusted', () => {
      expect(calculateCO2(1)).toBe(0.51)
      expect(calculateCO2(10)).toBeCloseTo(5.1, 6)
      expect(calculateCO2(0)).toBe(0)
    })
  })

  describe('fly-tipping saving', () => {
    it('uses the figures from the 2020 analysis of Freegle data', () => {
      // Keep Britain Tidy survey of 1,000 Londoners: 26, 21, 16 and 15 people
      // had fly-tipped a TV, oven, mattress or sofa. The mean, 19.5 in 1,000,
      // is the share of people who fly-tip.
      expect(FLYTIP_SHARE).toBe(0.0195)
      // Defra's standard clearance cost for "other single item" and "car boot
      // load or less", both £29, from council data for 2003 to 2006.
      expect(FLYTIP_COST).toBe(29)
      expect(FLYTIP_COST_YEARS).toEqual([2003, 2004, 2005, 2006])
    })

    it('reproduces the 2019 result in its own prices', () => {
      // The analysis ran over the 289,944 items offered in 2019 and reported a
      // mean of £162,697 (range £112,444 to £234,571) from random samples.
      const saving = FLYTIP_SHARE * FLYTIP_COST * 289944
      expect(saving).toBeGreaterThan(112444)
      expect(saving).toBeLessThan(234571)
      expect(Math.round(saving)).toBe(163963)
    })

    it('uprates the clearance cost from the 2003-06 average price level', () => {
      const base = (75.5 + 76.5 + 78.1 + 79.9) / 4
      expect(getFlyTipCost(2025)).toBeCloseTo((29 * 138.4) / base, 6)
      expect(getFlyTipCost(2004)).toBeCloseTo((29 * 76.5) / base, 6)
      expect(Math.round(getFlyTipCost(2025))).toBe(52)
    })

    it('defaults to the latest prices we have', () => {
      expect(getFlyTipCost()).toBe(getFlyTipCost(getLatestCPIYear()))
      expect(getFlyTipCost(3000)).toBe(getFlyTipCost(getLatestCPIYear()))
    })

    it('saves about a pound per item given away at 2025 prices', () => {
      expect(getFlyTipSavingPerItem(2025)).toBeCloseTo(
        0.0195 * getFlyTipCost(2025),
        9
      )
      expect(getFlyTipSavingPerItem(2025)).toBeCloseTo(1.01, 2)
    })

    it('scales with the number of items', () => {
      const per = getFlyTipSavingPerItem(2025)
      expect(calculateFlyTipSaving(1000, 2025)).toBeCloseTo(1000 * per, 6)
      expect(calculateFlyTipSaving(0, 2025)).toBe(0)
    })

    it('treats a missing count as no items', () => {
      expect(calculateFlyTipSaving(null)).toBe(0)
      expect(calculateFlyTipSaving(undefined)).toBe(0)
      expect(calculateFlyTipSaving(NaN)).toBe(0)
    })

    it('describes the saving in a short note', () => {
      const note = describeFlyTipSaving(8034.4)
      expect(note).toContain('£8,034')
      expect(note).toContain('fly-tipping')
      expect(note).toContain(
        'based on a 2020 study of Freegle data by DEFRA volunteers'
      )
    })
  })

  describe('useReuseBenefit composable', () => {
    it('exposes all helpers and constants', () => {
      const api = useReuseBenefit()
      expect(api.BASE_YEAR).toBe(2011)
      expect(api.BASE_BENEFIT_PER_TONNE).toBe(711)
      expect(api.CO2_PER_TONNE).toBe(0.51)
      expect(typeof api.getCPI).toBe('function')
      expect(typeof api.getInflationMultiplier).toBe('function')
      expect(typeof api.getBenefitPerTonne).toBe('function')
      expect(typeof api.calculateBenefit).toBe('function')
      expect(typeof api.calculateCO2).toBe('function')
      expect(typeof api.getLatestCPIYear).toBe('function')
      expect(typeof api.calculateFlyTipSaving).toBe('function')
      expect(typeof api.describeFlyTipSaving).toBe('function')
    })

    it('helpers returned from composable behave identically to direct exports', () => {
      const api = useReuseBenefit()
      expect(api.calculateBenefit(1, 2024)).toBe(calculateBenefit(1, 2024))
      expect(api.calculateCO2(2)).toBe(calculateCO2(2))
      expect(api.getCPI(2020)).toBe(getCPI(2020))
    })
  })
})
