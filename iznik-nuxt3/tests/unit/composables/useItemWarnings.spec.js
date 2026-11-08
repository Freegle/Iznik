import { describe, it, expect } from 'vitest'
import {
  ITEM_WARNINGS,
  findItemWarning,
  itemWarningMessage,
} from '~/composables/useItemWarnings'

describe('useItemWarnings', () => {
  describe('findItemWarning', () => {
    it.each([
      ['sofa', 'Upholstered household items'],
      ['mattress', 'Upholstered household items'],
      ['Mattresses', 'Upholstered household items'],
      ['footstool', 'Upholstered household items'],
      ['helmet', 'Motorcycle and cycle helmets'],
      ['car seat', 'Car seats'],
      ['Isofix base', 'Car seats'],
      ['booster seat', 'Car seats'],
      ['infant carrier', 'Car seats'],
      ['knife', 'Knives'],
      ['contact lens solution', 'Sealed and in date'],
      ['all in one contact lens fluid', 'Sealed and in date'],
      ['sunscreen', 'Sealed and in date'],
      ['eye makeup', 'Sealed and in date'],
      ['mascara', 'Sealed and in date'],
      ['Giving away a lamp', 'Free'],
      // Keywords with capitals in the list match too.
      ['Himalayan balsam', 'Banned Plants'],
      ['Japanese hop', 'Banned Plants'],
    ])('gives "%s" the note "%s"', (item, expectedType) => {
      expect(findItemWarning(item)?.type).toContain(expectedType)
    })

    it.each([
      'books',
      '',
      null,
      // "free" is a word on its own, not part of another.
      'Fridge freezer',
      'Garden seat',
    ])('gives "%s" no note', (item) => {
      expect(findItemWarning(item)).toBeNull()
    })

    it('shows the more specific note when two match', () => {
      expect(findItemWarning('Cot mattress').type).toBe('Cot Mattress')
    })

    it('shows a safety note rather than the note about saying free', () => {
      expect(findItemWarning('Free car seat').type).toBe('Car seats')
    })
  })

  // The same subjects as TestIsChildCarSeat in iznik-server-go/chat/replyconfirm_pure_test.go, which
  // decides whether a replier is asked about a child car seat. The poster's note and the replier's
  // question must agree.
  describe('car seats, kept in step with the Go API', () => {
    it.each([
      'OFFER: Child car seat (SW1)',
      'OFFER: Maxi-Cosi carseat and base',
      'OFFER: Booster Seat, age 4+',
      'OFFER: ISOFIX base only',
      'OFFER: Infant carrier',
      'OFFER: Child car safety seat',
      'OFFER: Children’s car seats (BS35)',
      'OFFER: Booster seats for car (G74)',
      'OFFER: Cybex Aton B2 car seat & ISO fix (HP3)',
      'OFFER: Car seat and seat cover',
      'OFFER: Car seat with rain cover',
    ])('"%s" is a child car seat', (subject) => {
      expect(findItemWarning(subject)?.type).toBe('Car seats')
    })

    it.each([
      'OFFER: Garden seat',
      'OFFER: Car roof box',
      "OFFER: Child's bike",
      'OFFER: Child carrier bag (BN1)',
      'WANTED: Child carrier backpack (S41)',
      'OFFER: Two car seat covers (SE26)',
      'OFFER: Dog-Hammock Type Car Seat Cover (CT2)',
      'OFFER: Joie black baby car seat cover (HP2)',
      'WANTED: Dog car seat and baby play gym (AL3)',
    ])('"%s" is not a child car seat', (subject) => {
      expect(findItemWarning(subject)?.type).not.toBe('Car seats')
    })
  })

  describe('itemWarningMessage', () => {
    const carSeats = ITEM_WARNINGS.find((w) => w.type === 'Car seats')
    const helmets = ITEM_WARNINGS.find((w) => w.type.includes('helmets'))

    it('tells an offerer of a car seat what to say, and an asker what to ask', () => {
      expect(itemWarningMessage(carSeats, 'Offer')).toContain(
        'Please say the make and model'
      )
      expect(itemWarningMessage(carSeats, 'Offer')).toContain(
        'childcarseats.org.uk'
      )
      expect(itemWarningMessage(carSeats, 'Wanted')).toContain(
        'Ask for the make, model and age'
      )
    })

    it('uses the one message for both when a note has no asker wording', () => {
      expect(itemWarningMessage(helmets, 'Wanted')).toContain(
        'involved in a crash'
      )
    })

    it('is null with no note', () => {
      expect(itemWarningMessage(null, 'Offer')).toBeNull()
    })
  })
})
