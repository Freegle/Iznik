import { describe, it, expect } from 'vitest'
import {
  constructAddress,
  constructMultiLine,
  constructSingleLine,
} from '~/composables/usePAF'

// Helper: call constructAddress with a partial set of arguments.
// Positional order matches the function signature.
function addr({
  udprn = null,
  postcode = 'AB1 2CD',
  postTown = 'LONDON',
  dependentLocality = null,
  doubleDependentLocality = null,
  thoroughfare = null,
  dependentThoroughfare = null,
  buildingNumber = null,
  buildingName = null,
  subBuildingName = null,
  poBox = null,
  departmentName = null,
  organizationName = null,
  postcodeType = null,
  suOrganizationIndicator = null,
  deliveryPointSuffix = null,
} = {}) {
  return constructAddress(
    udprn,
    postcode,
    postTown,
    dependentLocality,
    doubleDependentLocality,
    thoroughfare,
    dependentThoroughfare,
    buildingNumber,
    buildingName,
    subBuildingName,
    poBox,
    departmentName,
    organizationName,
    postcodeType,
    suOrganizationIndicator,
    deliveryPointSuffix
  )
}

// Helper: build a minimal address object for constructMultiLine / constructSingleLine.
function makeAddress(overrides = {}) {
  return {
    id: 1,
    postcode: 'SW1A 1AA',
    posttown: 'LONDON',
    dependentlocality: null,
    doubledependentlocality: null,
    thoroughfaredescriptor: null,
    dependentthoroughfaredescriptor: null,
    buildingnumber: null,
    buildingname: null,
    subbuildingname: null,
    pobox: null,
    departmentname: null,
    organizationname: null,
    postcodetype: null,
    suorganizationindicator: null,
    deliverypointsuffix: null,
    ...overrides,
  }
}

// The spacing between a building number and the line it prefixes used to be wrong in
// two opposite directions, from one expression:
//
//   nextLinePrefix.charAt(nextLinePrefix.length !== ' ')
//
// `length !== ' '` compares a number to a string, so it is always true, and true
// coerces to 1 — meaning this checked the SECOND character rather than the last.
// Rules 2, 4 and 5 set the prefix to `buildingNumber + ' '`, whose second character
// is usually a digit, so another space was appended: "42  Oxford Street". Rule 7
// sets the prefix with no trailing space, so for a single-digit number charAt(1) was
// '' and no space was added at all: "8King Road".
//
// Both are fixed, and the tests below assert correctly spaced output.

describe('usePAF', () => {
  describe('constructAddress', () => {
    describe('empty / no building fields', () => {
      it('returns empty array when no fields provided', () => {
        expect(addr()).toEqual([])
      })

      it('returns just the thoroughfare when only that is set', () => {
        expect(addr({ thoroughfare: 'High Street' })).toEqual(['High Street'])
      })
    })

    describe('buildingNumber === 0 normalisation', () => {
      it('treats buildingNumber 0 as absent (no prefix on thoroughfare)', () => {
        const lines = addr({ buildingNumber: 0, thoroughfare: 'High Street' })
        // 0 is normalised to null before Rule 2 runs, so no number prefix
        expect(lines).toEqual(['High Street'])
      })
    })

    describe('Rule 2 — building number only', () => {
      it.each([
        {
          buildingNumber: 42,
          thoroughfare: 'Oxford Street',
          expected: ['42 Oxford Street'],
        },
        {
          buildingNumber: '10',
          thoroughfare: 'Downing Street',
          expected: ['10 Downing Street'],
        },
        {
          buildingNumber: 1,
          thoroughfare: 'Park Lane',
          expected: ['1 Park Lane'],
        },
      ])(
        'prepends $buildingNumber to $thoroughfare with a single space',
        ({ buildingNumber, thoroughfare, expected }) => {
          expect(addr({ buildingNumber, thoroughfare })).toEqual(expected)
        }
      )

      it('never emits a double space between number and thoroughfare', () => {
        expect(
          addr({ buildingNumber: 42, thoroughfare: 'Oxford Street' })[0]
        ).not.toMatch(/\s{2}/)
      })

      it('produces a standalone number line, with no trailing space, when there is nothing to prefix', () => {
        expect(addr({ buildingNumber: 7 })).toEqual(['7'])
      })
    })

    describe('Rule 3 — building name only (no sub-building, no number)', () => {
      it('pushes ordinary name as its own line before thoroughfare', () => {
        expect(
          addr({
            buildingName: 'The White House',
            thoroughfare: 'Baker Street',
          })
        ).toEqual(['The White House', 'Baker Street'])
      })

      it.each([
        { buildingName: '1to4', thoroughfare: 'High Street' },
        { buildingName: '100:1', thoroughfare: 'High Street' },
      ])(
        '"$buildingName" (numeric start and end) is treated as inline prefix — single line',
        ({ buildingName, thoroughfare }) => {
          const lines = addr({ buildingName, thoroughfare })
          expect(lines).toHaveLength(1)
          expect(lines[0]).toMatch(
            new RegExp(
              `^${buildingName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`
            )
          )
        }
      )

      it('"4B" is NOT treated as a numeric prefix because the regex requires 3+ chars — produces two lines', () => {
        // /^[0-9].*[0-9][a-zA-Z]$/ requires at least 3 characters, so "4B" (2 chars) doesn't match.
        const lines = addr({ buildingName: '4B', thoroughfare: 'High Street' })
        expect(lines).toEqual(['4B', 'High Street'])
      })

      it('single-digit name is treated as numeric prefix with a trailing space', () => {
        // Single-digit building name matches buildingName.length === 1 — only if isNaN is false.
        // '5' — isNaN('5') is false. So no comma added, but it IS set as nextLinePrefix.
        const lines = addr({ buildingName: '5', thoroughfare: 'Station Road' })
        expect(lines).toHaveLength(1)
        expect(lines[0]).toMatch(/^5/)
      })

      it('single non-numeric char name is treated as prefix with a trailing comma', () => {
        // buildingName.length === 1 AND isNaN('A') === true → comma is appended
        const lines = addr({ buildingName: 'A', thoroughfare: 'Station Road' })
        expect(lines).toHaveLength(1)
        expect(lines[0]).toMatch(/^A,/)
      })
    })

    describe('building name auto-split (name ends with alpha-suffixed number or range)', () => {
      it('"Willowbrook 12" is NOT split because pure digit suffixes are not split', () => {
        // The split regex /\s[0-9]+[a-zA-Z]+$/ requires trailing letters; "12" has none.
        // /\s[0-9]+-[0-9]+$/ also does not match. So "Willowbrook 12" stays as one name.
        const lines = addr({
          buildingName: 'Willowbrook 12',
          thoroughfare: 'Station Road',
        })
        expect(lines).toEqual(['Willowbrook 12', 'Station Road'])
      })

      it('"Ash House 3-5" is split on the range " 3-5"', () => {
        // " 3-5" matches /\s[0-9]+-[0-9]+$/ — split yields buildingName="Ash House", buildingNumber="3-5".
        // Rule 4 then applies: push buildingName, nextLinePrefix = "3-5 " → "3-5 High Road".
        const lines = addr({
          buildingName: 'Ash House 3-5',
          thoroughfare: 'High Road',
        })
        expect(lines).toContain('Ash House')
        expect(lines.some((l) => l.startsWith('3-5'))).toBe(true)
      })

      it('"Rose Cottage 2A" is split on the alpha-suffixed number " 2A"', () => {
        // " 2A" matches /\s[0-9]+[a-zA-Z]+$/ — split yields buildingName="Rose Cottage", buildingNumber="2A".
        const lines = addr({
          buildingName: 'Rose Cottage 2A',
          thoroughfare: 'Green Lane',
        })
        expect(lines).toContain('Rose Cottage')
        expect(lines.some((l) => l.startsWith('2A'))).toBe(true)
      })

      // Royal Mail Exception 4: a name that is one of the listed prefixes followed by a
      // number stays whole. The regex was built as `new RegExp('/^(...).../')` with the
      // delimiting slashes inside the pattern string, so it only ever looked for literal
      // "/" characters and this guard never fired.
      it('"Block 1A" is NOT split because "Block" is an ex4 special prefix', () => {
        expect(
          addr({ buildingName: 'Block 1A', thoroughfare: 'Main Street' })
        ).toEqual(['Block 1A', 'Main Street'])
      })

      it.each([
        { buildingName: 'Unit 5-7' },
        { buildingName: 'Suite 2B' },
        { buildingName: 'Stall 4A' },
        { buildingName: 'Maisonette 12C' },
      ])(
        '"$buildingName" is kept whole as an ex4 special prefix',
        ({ buildingName }) => {
          expect(addr({ buildingName, thoroughfare: 'Main Street' })).toEqual([
            buildingName,
            'Main Street',
          ])
        }
      )

      it('still splits a name whose leading word is NOT a special prefix', () => {
        // Guards against the regex being loosened into matching everything.
        expect(
          addr({ buildingName: 'Willow 1A', thoroughfare: 'Main Street' })
        ).toEqual(['Willow', '1A Main Street'])
      })
    })

    describe('Rule 4 — building name + building number', () => {
      it.each([
        {
          buildingName: 'Maple House',
          buildingNumber: 10,
          thoroughfare: 'Elm Street',
          expected: ['Maple House', '10 Elm Street'],
        },
        {
          buildingName: 'Riverside',
          buildingNumber: '25',
          thoroughfare: 'Quay Road',
          expected: ['Riverside', '25 Quay Road'],
        },
      ])(
        'puts $buildingName on own line then $buildingNumber prefixes thoroughfare',
        ({ buildingName, buildingNumber, thoroughfare, expected }) => {
          expect(addr({ buildingName, buildingNumber, thoroughfare })).toEqual(
            expected
          )
        }
      )
    })

    describe('Rule 5 — sub-building name + building number', () => {
      it.each([
        {
          subBuildingName: 'Flat 3',
          buildingNumber: 15,
          thoroughfare: 'Park Road',
          expected: ['Flat 3', '15 Park Road'],
        },
        {
          subBuildingName: 'Unit 2',
          buildingNumber: 200,
          thoroughfare: 'Business Park',
          expected: ['Unit 2', '200 Business Park'],
        },
      ])(
        'puts $subBuildingName on own line then $buildingNumber prefixes thoroughfare',
        ({ subBuildingName, buildingNumber, thoroughfare, expected }) => {
          expect(
            addr({ subBuildingName, buildingNumber, thoroughfare })
          ).toEqual(expected)
        }
      )
    })

    describe('Rule 6 — sub-building name + building name (no number)', () => {
      it('outputs sub-building name, then building name, then thoroughfare as separate lines', () => {
        expect(
          addr({
            subBuildingName: 'Flat 1',
            buildingName: 'Elm Court',
            thoroughfare: 'West Street',
          })
        ).toEqual(['Flat 1', 'Elm Court', 'West Street'])
      })

      it('"3A" sub-building produces a separate line because it is only 2 chars (regex needs 3+)', () => {
        // /^[0-9].*[0-9][a-zA-Z]$/ requires minimum 3 chars; "3A" has 2, so no exception.
        const lines = addr({
          subBuildingName: '3A',
          buildingName: 'Beech House',
          thoroughfare: 'Acacia Avenue',
        })
        expect(lines).toContain('3A')
        expect(lines).toContain('Beech House')
        expect(lines).toContain('Acacia Avenue')
      })

      it('single non-numeric sub-building char produces "X, building-name" combined line', () => {
        // subBuildingName.length === 1 AND isNaN('B') → comma appended, then used as prefix
        const lines = addr({
          subBuildingName: 'B',
          buildingName: 'Beech House',
          thoroughfare: 'Acacia Avenue',
        })
        expect(lines.find((l) => l.startsWith('B,'))).toBeTruthy()
      })

      it('numeric start-and-end sub-building (e.g. "10to12") is used as prefix to building name', () => {
        // "10to12" matches /^[0-9].*[0-9]$/ → exception applies: subBuildingName used as nextLinePrefix
        const lines = addr({
          subBuildingName: '10to12',
          buildingName: 'Oak House',
          thoroughfare: 'Station Road',
        })
        // nextLinePrefix = '10to12 ', then building name: push('10to12 Oak House') or similar
        expect(
          lines.find((l) => l.includes('10to12') && l.includes('Oak House'))
        ).toBeTruthy()
      })
    })

    describe('Rule 7 — sub-building name + building name + building number', () => {
      it('puts sub-building and building name on separate lines, multi-digit number prefixes thoroughfare', () => {
        // Rule 7: nextLinePrefix = buildingNumber (no trailing space), EH adds 1 space for multi-char.
        // Unlike Rules 2/4/5, the assignment here does NOT pre-include a space, so only 1 space total.
        const lines = addr({
          subBuildingName: 'Flat 2',
          buildingName: 'Windsor Towers',
          buildingNumber: 15,
          thoroughfare: 'King Road',
        })
        expect(lines).toContain('Flat 2')
        expect(lines).toContain('Windsor Towers')
        expect(lines.find((l) => l.startsWith('15 King Road'))).toBeTruthy()
      })

      it('"5A" sub-building (2 chars, no exception) produces three separate lines', () => {
        // "5A" doesn't match any exception (needs 3+ chars), so it is pushed on its own line.
        const lines = addr({
          subBuildingName: '5A',
          buildingName: 'Windsor Towers',
          buildingNumber: 20,
          thoroughfare: 'King Road',
        })
        expect(lines).toContain('5A')
        expect(lines).toContain('Windsor Towers')
        expect(lines.find((l) => l.startsWith('20'))).toBeTruthy()
      })

      it('numeric sub-building name (e.g. "10to4") is used as inline prefix to building name in Rule 7', () => {
        // "10to4" matches /^[0-9].*[0-9]$/ (starts with '1', ends with '4') → exception branch.
        // This covers lines 199-206 (the exception block inside Rule 7).
        // nextLinePrefix = "10to4 " → buildingName appended → push("10to4 Windsor Towers").
        const lines = addr({
          subBuildingName: '10to4',
          buildingName: 'Windsor Towers',
          buildingNumber: 20,
          thoroughfare: 'King Road',
        })
        expect(
          lines.find((l) => l.includes('10to4') && l.includes('Windsor Towers'))
        ).toBeTruthy()
        expect(lines.find((l) => l.startsWith('20'))).toBeTruthy()
      })

      it('single non-numeric sub-building char gets a comma prefix to building name in Rule 7', () => {
        // subBuildingName.length === 1 AND isNaN('A') → comma appended (line 204).
        const lines = addr({
          subBuildingName: 'A',
          buildingName: 'Oak House',
          buildingNumber: 10,
          thoroughfare: 'Station Road',
        })
        // "A, " becomes the prefix → "A, Oak House" combined line, then "10 Station Road"
        expect(
          lines.find((l) => l.startsWith('A,') && l.includes('Oak House'))
        ).toBeTruthy()
        expect(lines.find((l) => l.includes('Station Road'))).toBeTruthy()
      })

      // Rule 7 sets the prefix with no trailing space, which is the arm that used to
      // produce "8King Road" with the number jammed against the street.
      it('single-digit buildingNumber gets a space before thoroughfare', () => {
        expect(
          addr({
            subBuildingName: 'Flat 2',
            buildingName: 'Windsor Towers',
            buildingNumber: 8,
            thoroughfare: 'King Road',
          })
        ).toEqual(['Flat 2', 'Windsor Towers', '8 King Road'])
      })

      it.each([1, 8, 9])(
        'single-digit buildingNumber %i is separated from the thoroughfare',
        (buildingNumber) => {
          const lines = addr({
            subBuildingName: 'Flat 2',
            buildingName: 'Windsor Towers',
            buildingNumber,
            thoroughfare: 'King Road',
          })
          expect(lines[lines.length - 1]).toBe(`${buildingNumber} King Road`)
        }
      )
    })

    describe('Rule C1 — sub-building name only (no building name or number)', () => {
      it('uses sub-building name as prefix for the next thoroughfare line', () => {
        // Rule C1: nextLinePrefix = subBuildingName (no trailing space).
        // EH check adds one space → 'Rear of ' → thoroughfare: 'Rear of Elm Street'.
        const lines = addr({
          subBuildingName: 'Rear of',
          thoroughfare: 'Elm Street',
        })
        expect(lines).toEqual(['Rear of Elm Street'])
      })

      it('sub-building name appears standalone when there is no thoroughfare or locality', () => {
        const lines = addr({ subBuildingName: 'Basement' })
        expect(lines.join('')).toContain('Basement')
      })
    })

    describe('organisation and department names', () => {
      it('prepends organisation name as first address line', () => {
        const lines = addr({
          organizationName: 'Acme Corp',
          buildingNumber: 5,
          thoroughfare: 'High Street',
        })
        expect(lines[0]).toBe('Acme Corp')
        expect(lines.find((l) => l.includes('High Street'))).toBeTruthy()
      })

      it('prepends department name immediately after organisation name', () => {
        const lines = addr({
          organizationName: 'Acme Corp',
          departmentName: 'IT Department',
          buildingNumber: 5,
          thoroughfare: 'High Street',
        })
        expect(lines[0]).toBe('Acme Corp')
        expect(lines[1]).toBe('IT Department')
        expect(lines.find((l) => l.includes('High Street'))).toBeTruthy()
      })

      it('adds department name without an organisation name', () => {
        const lines = addr({
          departmentName: 'Accounts',
          buildingNumber: 3,
          thoroughfare: 'Bank Road',
        })
        expect(lines).toContain('Accounts')
        expect(lines.find((l) => l.includes('Bank Road'))).toBeTruthy()
      })

      it('organisation name only (no building info) appears as sole line', () => {
        const lines = addr({ organizationName: 'Royal Mail' })
        expect(lines).toContain('Royal Mail')
      })
    })

    describe('PO Box', () => {
      it('adds a "PO Box N" entry before the building lines', () => {
        const lines = addr({
          poBox: '123',
          buildingNumber: 5,
          thoroughfare: 'Main Road',
        })
        // Note: EH cleanup strips "PO Box " prefix from addressLines[0], so the PO Box line
        // may only be visible as the bare number after cleanup depending on its position.
        expect(lines.some((l) => l.includes('PO Box 123') || l === '123')).toBe(
          true
        )
      })

      it('strips leading "PO Box " from the first address line via EH cleanup', () => {
        const lines = addr({ poBox: '456' })
        // EH: addressLines[0].replace('PO Box ', '')
        expect(lines[0]).not.toMatch(/^PO Box /)
      })

      it('replaces "PO Box Flat" with "Flat" in the first address line via EH cleanup', () => {
        const lines = addr({ poBox: 'Flat 1' })
        if (lines.length > 0) {
          expect(lines[0]).not.toContain('PO Box Flat')
        }
      })
    })

    describe('dependent thoroughfare', () => {
      it('outputs dependent thoroughfare before the main thoroughfare', () => {
        const lines = addr({
          dependentThoroughfare: 'Back Lane',
          thoroughfare: 'High Street',
        })
        expect(lines).toEqual(['Back Lane', 'High Street'])
      })

      it('building number prefixes the dependent thoroughfare', () => {
        // Rule 2 sets nextLinePrefix = buildingNumber + ' ', EH adds another space.
        const lines = addr({
          buildingNumber: 3,
          dependentThoroughfare: 'Back Lane',
          thoroughfare: 'High Street',
        })
        expect(lines[0]).toMatch(/^3/)
        expect(lines[0]).toContain('Back Lane')
        expect(lines[1]).toBe('High Street')
      })
    })

    describe('localities', () => {
      it('appends double-dependent locality then dependent locality after thoroughfare', () => {
        const lines = addr({
          thoroughfare: 'High Street',
          doubleDependentLocality: 'North Quarter',
          dependentLocality: 'Southwark',
        })
        expect(lines).toContain('North Quarter')
        expect(lines).toContain('Southwark')
        const thoroughfareIdx = lines.indexOf('High Street')
        expect(thoroughfareIdx).toBeLessThan(lines.indexOf('North Quarter'))
      })

      it('outputs dependent locality alone (no double-dependent)', () => {
        const lines = addr({
          thoroughfare: 'Main Street',
          dependentLocality: 'Kensington',
        })
        expect(lines).toEqual(['Main Street', 'Kensington'])
      })

      it('double-dependent locality appears before dependent locality', () => {
        const lines = addr({
          doubleDependentLocality: 'The Lanes',
          dependentLocality: 'Brighton',
        })
        expect(lines.indexOf('The Lanes')).toBeLessThan(
          lines.indexOf('Brighton')
        )
      })
    })

    describe('EH deduplication — embedded number in next line', () => {
      it('removes a redundant first line when the second line begins with it plus a space', () => {
        // Scenario: org name produces a first line, then a thorofare line starts with the same text.
        // lines = ['The Lane', 'The Lane Villas', ...] → first removed.
        const lines = addr({
          organizationName: 'The Lane',
          thoroughfare: 'The Lane Villas',
        })
        // 'The Lane Villas'.includes('The Lane ') → true → first line removed
        expect(lines).not.toContain('The Lane')
        expect(lines).toContain('The Lane Villas')
      })

      it('does NOT remove first line when second line does not start with first plus space', () => {
        const lines = addr({
          organizationName: 'Acme Corp',
          thoroughfare: 'High Street',
        })
        expect(lines).toContain('Acme Corp')
        expect(lines).toContain('High Street')
      })

      it('removes the second line when the third line begins with it plus a space (EH dedup 3)', () => {
        // Trigger the third EH dedup block (lines 290-292):
        // Need lines[2].includes(lines[1] + ' '). Achieved with:
        //   subBuildingName='Flat 1' → lines[0], buildingName='Elm Court' → lines[1],
        //   thoroughfare='Elm Court Road' → lines[2].
        // lines[2] = 'Elm Court Road'.includes('Elm Court ') = true → splice(1,1).
        const lines = addr({
          subBuildingName: 'Flat 1',
          buildingName: 'Elm Court',
          thoroughfare: 'Elm Court Road',
        })
        expect(lines).not.toContain('Elm Court')
        expect(lines).toContain('Flat 1')
        expect(lines).toContain('Elm Court Road')
      })
    })

    describe('multiple fields combined', () => {
      it('full address: org + dept + sub + building name + number + thoroughfare + locality', () => {
        const lines = addr({
          organizationName: 'Big Co Ltd',
          departmentName: 'Finance',
          subBuildingName: 'Flat 4',
          buildingName: 'Tower Block',
          buildingNumber: 20,
          thoroughfare: 'Commerce Way',
          dependentLocality: 'Docklands',
        })
        expect(lines[0]).toBe('Big Co Ltd')
        expect(lines[1]).toBe('Finance')
        expect(lines).toContain('Flat 4')
        expect(lines).toContain('Tower Block')
        expect(lines.some((l) => l.includes('Commerce Way'))).toBe(true)
        expect(lines).toContain('Docklands')
      })
    })
  })

  describe('constructMultiLine', () => {
    it('returns null for null input', () => {
      expect(constructMultiLine(null)).toBeNull()
    })

    it('returns null for undefined input', () => {
      expect(constructMultiLine(undefined)).toBeNull()
    })

    it('appends post town and postcode on the final line', () => {
      const result = constructMultiLine(
        makeAddress({
          buildingnumber: 10,
          thoroughfaredescriptor: 'Downing Street',
        })
      )
      expect(result).toBe('10 Downing Street\nLONDON SW1A 1AA')
    })

    it('separates address lines with newlines', () => {
      const result = constructMultiLine(
        makeAddress({
          buildingnumber: 10,
          thoroughfaredescriptor: 'Downing Street',
        })
      )
      expect(result).toContain('\n')
    })

    it('does not start with ", " even when address lines are empty', () => {
      const result = constructMultiLine(makeAddress())
      expect(result).not.toMatch(/^, /)
    })

    it('includes organisation name when present', () => {
      const result = constructMultiLine(
        makeAddress({
          organizationname: 'HMRC',
          buildingnumber: 100,
          thoroughfaredescriptor: 'Parliament Street',
        })
      )
      expect(result).toContain('HMRC')
    })

    it('full address produces lines in the correct order: building → locality → town+postcode', () => {
      const result = constructMultiLine(
        makeAddress({
          subbuildingname: 'Flat 3',
          buildingnumber: 15,
          thoroughfaredescriptor: 'High Street',
          dependentlocality: 'Kensington',
          posttown: 'LONDON',
          postcode: 'W8 4QU',
        })
      )
      const lines = result.split('\n')
      const highStreetIdx = lines.findIndex((l) => l.includes('High Street'))
      const kensingtonIdx = lines.findIndex((l) => l.includes('Kensington'))
      const townIdx = lines.findIndex((l) => l.includes('LONDON'))
      expect(highStreetIdx).toBeGreaterThanOrEqual(0)
      expect(kensingtonIdx).toBeGreaterThan(highStreetIdx)
      expect(townIdx).toBeGreaterThan(kensingtonIdx)
    })

    it('assembles a PO Box address with posttown and postcode', () => {
      const result = constructMultiLine(
        makeAddress({
          pobox: '123',
          posttown: 'EDINBURGH',
          postcode: 'EH1 1AB',
        })
      )
      expect(result).toContain('EDINBURGH EH1 1AB')
    })
  })

  describe('constructSingleLine', () => {
    it('returns null for null input', () => {
      expect(constructSingleLine(null)).toBeNull()
    })

    it('returns null for undefined input', () => {
      expect(constructSingleLine(undefined)).toBeNull()
    })

    it('replaces all newlines with ", "', () => {
      const result = constructSingleLine(
        makeAddress({
          buildingnumber: 10,
          thoroughfaredescriptor: 'Downing Street',
        })
      )
      expect(result).not.toContain('\n')
      expect(result).toContain(', ')
    })

    it('contains the thoroughfare name in the single-line result', () => {
      const result = constructSingleLine(
        makeAddress({
          buildingnumber: 10,
          thoroughfaredescriptor: 'Downing Street',
        })
      )
      expect(result).toContain('Downing Street')
    })

    it('contains post town and postcode', () => {
      const result = constructSingleLine(
        makeAddress({
          buildingnumber: 10,
          thoroughfaredescriptor: 'Downing Street',
        })
      )
      expect(result).toContain('LONDON')
      expect(result).toContain('SW1A 1AA')
    })

    it('organisation name appears in the single-line result', () => {
      const result = constructSingleLine(
        makeAddress({
          organizationname: 'HMRC',
          buildingnumber: 100,
          thoroughfaredescriptor: 'Parliament Street',
          posttown: 'LONDON',
          postcode: 'SW1A 2BQ',
        })
      )
      expect(result).toContain('HMRC')
      expect(result).toContain('Parliament Street')
      expect(result).toContain('LONDON SW1A 2BQ')
    })
  })
})
