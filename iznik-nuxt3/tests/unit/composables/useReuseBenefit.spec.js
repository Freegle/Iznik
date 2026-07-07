import { describe, it, expect, beforeAll, afterAll, vi } from 'vitest'
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

    it('clamps to earliest year for pre-2011 requests', () => {
      expect(getCPI(2000)).toBe(getCPI(2011))
      expect(getCPI(1980)).toBe(93.4)
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
      expect(getLatestCPIYear()).toBe(2024)
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

    it('uses 2024 value for future years', () => {
      expect(getBenefitPerTonne(3000)).toBe(getBenefitPerTonne(2024))
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
    })

    it('helpers returned from composable behave identically to direct exports', () => {
      const api = useReuseBenefit()
      expect(api.calculateBenefit(1, 2024)).toBe(calculateBenefit(1, 2024))
      expect(api.calculateCO2(2)).toBe(calculateCO2(2))
      expect(api.getCPI(2020)).toBe(getCPI(2020))
    })
  })
})
