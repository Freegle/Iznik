import { describe, it, expect } from 'vitest'
import { ref, nextTick } from 'vue'
import {
  detectPersonalInfo,
  groupRestrictsPersonalInfo,
  usePersonalInfoWarning,
} from '~/composables/usePersonalInfoWarning'

describe('detectPersonalInfo', () => {
  it.each([
    ['null', null],
    ['undefined', undefined],
    ['empty string', ''],
  ])('returns no phone and no email for %s', (_name, text) => {
    expect(detectPersonalInfo(text)).toEqual({
      hasPhone: false,
      hasEmail: false,
    })
  })

  it.each([
    ['mobile with space', 'Call me on 07700 900123 please'],
    ['mobile no spaces', '07700900123'],
    ['landline with hyphens', 'ring 01632-960-123 anytime'],
    ['landline 10 digits after 0', 'tel 0113 496 0123'],
    ['+44 prefix', 'text +447700900123'],
    ['+44 prefix with space', '+44 7700 900123'],
    ['0044 prefix', '0044 7700 900123'],
    ['0044 prefix no space', '00447700900123'],
    ['at start of text', '07700900123 is my number'],
    ['at end of text', 'my number is 07700900123'],
    ['after punctuation', 'phone:07700900123.'],
    ['on its own line', 'hello\n07700 900 123\nbye'],
  ])('detects a UK phone number: %s', (_name, text) => {
    expect(detectPersonalInfo(text).hasPhone).toBe(true)
  })

  it.each([
    ['plain text', 'Sofa, good condition, collect from Bristol'],
    ['too few digits', 'call 0770 90012'],
    ['no UK prefix', 'order 7700900123'],
    ['non-UK prefix', 'call 1-800-555-0199'],
    ['too many digits (12 after 0)', '012345678901234'],
    ['digit-embedded number', '9907700900123'],
    ['short number', 'I have 3 chairs and 12 plates'],
    ['year and quantity', 'Bought in 2019, 4 available'],
  ])('does not flag a phone number: %s', (_name, text) => {
    expect(detectPersonalInfo(text).hasPhone).toBe(false)
  })

  it.each([
    ['simple gmail', 'mail me at bob@gmail.com'],
    ['dots and plus', 'first.last+tag@example.co.uk'],
    ['uppercase', 'BOB@GMAIL.COM'],
    ['underscore and percent', 'a_b%c@sub.domain.org'],
    ['mixed with ours later', 'bob@gmail.com or x@ilovefreegle.org'],
  ])('detects an external email: %s', (_name, text) => {
    expect(detectPersonalInfo(text).hasEmail).toBe(true)
  })

  it.each([
    ['freegle address', 'reply to bob-123@users.ilovefreegle.org'],
    ['freegle uppercase', 'Bob@ILOVEFREEGLE.ORG'],
    ['trashnothing address', 'bob@user.trashnothing.com'],
    ['yahoogroups address', 'group@yahoogroups.com'],
  ])('does not flag our own email: %s', (_name, text) => {
    expect(detectPersonalInfo(text).hasEmail).toBe(false)
  })

  it.each([
    ['no at sign', 'bob dot gmail dot com'],
    ['at sign without domain', 'meet @ 5pm'],
    ['no TLD', 'bob@localhost'],
    ['single-letter TLD', 'bob@example.c'],
  ])('does not flag a non-email: %s', (_name, text) => {
    expect(detectPersonalInfo(text).hasEmail).toBe(false)
  })

  it('detects both phone and email together', () => {
    expect(detectPersonalInfo('bob@gmail.com or 07700 900123')).toEqual({
      hasPhone: true,
      hasEmail: true,
    })
  })

  it('detects phone only and email only independently', () => {
    expect(detectPersonalInfo('07700 900123')).toEqual({
      hasPhone: true,
      hasEmail: false,
    })
    expect(detectPersonalInfo('bob@gmail.com')).toEqual({
      hasPhone: false,
      hasEmail: true,
    })
  })

  it('only checks the first email match (existing behaviour)', () => {
    // The first address is ours, so a later external one is not reported.
    expect(
      detectPersonalInfo('x@ilovefreegle.org then bob@gmail.com').hasEmail
    ).toBe(false)
  })
})

describe('groupRestrictsPersonalInfo', () => {
  it.each([
    ['null group', null],
    ['undefined group', undefined],
    ['no rules', {}],
    ['null rules', { rules: null }],
    ['empty string rules', { rules: '' }],
    ['invalid JSON string', { rules: '{not json' }],
    ['object without flag', { rules: { other: 1 } }],
    ['object with falsy flag', { rules: { restrictpersonalinfo: 0 } }],
    ['object with false flag', { rules: { restrictpersonalinfo: false } }],
    ['JSON string without flag', { rules: '{"other":1}' }],
    ['JSON string with falsy flag', { rules: '{"restrictpersonalinfo":0}' }],
  ])('is false for %s', (_name, group) => {
    expect(groupRestrictsPersonalInfo(group)).toBe(false)
  })

  it.each([
    ['object with true', { rules: { restrictpersonalinfo: true } }],
    ['object with 1', { rules: { restrictpersonalinfo: 1 } }],
    ['JSON string with 1', { rules: '{"restrictpersonalinfo":1}' }],
    ['JSON string with true', { rules: '{"restrictpersonalinfo":true}' }],
  ])('is true for %s', (_name, group) => {
    expect(groupRestrictsPersonalInfo(group)).toBe(true)
  })

  it('returns a strict boolean', () => {
    expect(
      groupRestrictsPersonalInfo({ rules: { restrictpersonalinfo: 'yes' } })
    ).toBe(true)
  })

  // TODO: latent bug — a rules string of 'null' parses to null and the property access throws.
  it.skip('is false when rules is the JSON string "null"', () => {
    expect(groupRestrictsPersonalInfo({ rules: 'null' })).toBe(false)
  })

  it('currently throws when rules is the JSON string "null" (documents the latent bug)', () => {
    expect(() => groupRestrictsPersonalInfo({ rules: 'null' })).toThrow(
      TypeError
    )
  })
})

describe('usePersonalInfoWarning', () => {
  const restricted = { rules: { restrictpersonalinfo: true } }

  it.each([
    ['restricted + phone', restricted, 'ring 07700 900123', true],
    ['restricted + email', restricted, 'bob@gmail.com', true],
    ['restricted + both', restricted, 'bob@gmail.com 07700 900123', true],
    ['restricted + clean text', restricted, 'Nice sofa', false],
    ['restricted + empty text', restricted, '', false],
    ['restricted + null text', restricted, null, false],
    ['restricted + our email', restricted, 'x@ilovefreegle.org', false],
    ['unrestricted + phone', { rules: {} }, '07700 900123', false],
    ['unrestricted + email', { rules: {} }, 'bob@gmail.com', false],
    ['no group + phone', null, '07700 900123', false],
    [
      'string rules restricted + phone',
      { rules: '{"restrictpersonalinfo":1}' },
      '07700 900123',
      true,
    ],
    ['bad rules + phone', { rules: '{bad' }, '07700 900123', false],
  ])('%s', (_name, group, text, expected) => {
    const warning = usePersonalInfoWarning(ref(group), ref(text))
    expect(warning.value).toBe(expected)
  })

  it('reacts to text changes', async () => {
    const group = ref(restricted)
    const text = ref('hello')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(false)

    text.value = 'call 07700 900123'
    await nextTick()
    expect(warning.value).toBe(true)

    text.value = 'bob@gmail.com'
    await nextTick()
    expect(warning.value).toBe(true)

    text.value = 'all gone'
    await nextTick()
    expect(warning.value).toBe(false)
  })

  it('reacts to group changes', async () => {
    const group = ref(null)
    const text = ref('07700 900123')
    const warning = usePersonalInfoWarning(group, text)
    expect(warning.value).toBe(false)

    group.value = restricted
    await nextTick()
    expect(warning.value).toBe(true)

    group.value = { rules: { restrictpersonalinfo: false } }
    await nextTick()
    expect(warning.value).toBe(false)
  })
})
