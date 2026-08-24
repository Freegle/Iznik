import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import {
  detectPersonalInfo,
  groupRestrictsPersonalInfo,
  usePersonalInfoWarning,
} from '~/composables/usePersonalInfoWarning'

describe('detectPersonalInfo', () => {
  it('returns no phone/email for empty/falsy text', () => {
    expect(detectPersonalInfo('')).toEqual({ hasPhone: false, hasEmail: false })
    expect(detectPersonalInfo(null)).toEqual({
      hasPhone: false,
      hasEmail: false,
    })
    expect(detectPersonalInfo(undefined)).toEqual({
      hasPhone: false,
      hasEmail: false,
    })
  })

  it('returns no phone/email for plain text with neither', () => {
    expect(detectPersonalInfo('Free to a good home, one sofa')).toEqual({
      hasPhone: false,
      hasEmail: false,
    })
  })

  it.each([
    ['leading zero UK mobile', 'Call me on 07911123456 please'],
    ['+44 prefix', 'Call me on +447911123456 please'],
    ['+44 with space', 'Call me on +44 7911123456 please'],
    ['0044 prefix', 'Call me on 00447911123456 please'],
    ['spaced digits', 'Call me on 0791 112 3456 please'],
    ['hyphenated digits', 'Call me on 0791-112-3456 please'],
    ['landline 10 digit', 'Call 01611234567 today'],
  ])('detects a UK phone number: %s', (_label, text) => {
    expect(detectPersonalInfo(text).hasPhone).toBe(true)
  })

  it('does not detect a phone number in an ordinary number sequence', () => {
    expect(detectPersonalInfo('It weighs about 123 grams').hasPhone).toBe(false)
  })

  // The pattern must not match part-way into a longer run of digits. This was
  // originally expressed as a lookbehind, which Safari < 16.4 cannot parse at
  // all - so it is now (?:^|\D). These cases pin that equivalence.
  it.each([
    ['long digit run', '1234567890123'],
    ['phone-like run preceded by a digit', '50791112345678'],
    ['digits either side', 'ref 9900791112345600'],
    ['leading zeroes mid-run', 'order 000123456789012'],
  ])('does not detect a phone number mid-digit-run: %s', (_label, text) => {
    expect(detectPersonalInfo(text).hasPhone).toBe(false)
  })

  it('still detects a phone number preceded by non-digit characters', () => {
    expect(detectPersonalInfo('abc0791 112 3456').hasPhone).toBe(true)
  })

  it('detects an external email address as hasEmail true', () => {
    const result = detectPersonalInfo('Reach me at bob@example.com thanks')
    expect(result.hasEmail).toBe(true)
  })

  it('is case-insensitive when matching freegle domains', () => {
    const result = detectPersonalInfo('Email me at Bob@ILoveFreegle.ORG')
    expect(result.hasEmail).toBe(false)
  })

  it.each([
    ['ilovefreegle.org', 'contact bob@ilovefreegle.org now'],
    ['trashnothing', 'contact bob@trashnothing.com now'],
    ['yahoogroups', 'contact bob@yahoogroups.com now'],
  ])('does not flag freegle-owned email domain: %s', (_label, text) => {
    expect(detectPersonalInfo(text).hasEmail).toBe(false)
  })

  it('does not detect an email when there is none', () => {
    expect(detectPersonalInfo('no contact info here').hasEmail).toBe(false)
  })

  it('detects both a phone and an external email in the same text', () => {
    const result = detectPersonalInfo(
      'Call 07911123456 or email bob@example.com'
    )
    expect(result).toEqual({ hasPhone: true, hasEmail: true })
  })
})

describe('groupRestrictsPersonalInfo', () => {
  it('returns false when there is no group', () => {
    expect(groupRestrictsPersonalInfo(null)).toBe(false)
    expect(groupRestrictsPersonalInfo(undefined)).toBe(false)
  })

  it('returns false when the group has no rules', () => {
    expect(groupRestrictsPersonalInfo({})).toBe(false)
    expect(groupRestrictsPersonalInfo({ rules: null })).toBe(false)
    expect(groupRestrictsPersonalInfo({ rules: '' })).toBe(false)
  })

  it('returns false when rules is a string that fails to parse as JSON', () => {
    expect(groupRestrictsPersonalInfo({ rules: '{not valid json' })).toBe(false)
  })

  it('parses a JSON string rules value and reads restrictpersonalinfo', () => {
    expect(
      groupRestrictsPersonalInfo({
        rules: JSON.stringify({ restrictpersonalinfo: true }),
      })
    ).toBe(true)
    expect(
      groupRestrictsPersonalInfo({
        rules: JSON.stringify({ restrictpersonalinfo: false }),
      })
    ).toBe(false)
  })

  it('reads restrictpersonalinfo directly when rules is already an object', () => {
    expect(
      groupRestrictsPersonalInfo({ rules: { restrictpersonalinfo: true } })
    ).toBe(true)
  })

  it('returns false when rules is an object without restrictpersonalinfo', () => {
    expect(groupRestrictsPersonalInfo({ rules: { somethingElse: true } })).toBe(
      false
    )
  })
})

describe('usePersonalInfoWarning', () => {
  it('is false when the group does not restrict personal info, regardless of text', () => {
    const group = ref({ rules: { restrictpersonalinfo: false } })
    const text = ref('Call me on 07911123456')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(false)
  })

  it('is false when the group restricts personal info but the text has none', () => {
    const group = ref({ rules: { restrictpersonalinfo: true } })
    const text = ref('Free to a good home')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(false)
  })

  it('is true when the group restricts personal info and the text has a phone number', () => {
    const group = ref({ rules: { restrictpersonalinfo: true } })
    const text = ref('Call me on 07911123456')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(true)
  })

  it('is true when the group restricts personal info and the text has an external email', () => {
    const group = ref({ rules: { restrictpersonalinfo: true } })
    const text = ref('Email bob@example.com')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(true)
  })

  it('is reactive to changes in the text ref', () => {
    const group = ref({ rules: { restrictpersonalinfo: true } })
    const text = ref('nothing to see here')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(false)

    text.value = 'call 07911123456'
    expect(warning.value).toBe(true)
  })

  it('is reactive to changes in the group ref', () => {
    const group = ref(null)
    const text = ref('call 07911123456')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(false)

    group.value = { rules: { restrictpersonalinfo: true } }
    expect(warning.value).toBe(true)
  })
})
