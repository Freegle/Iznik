import { describe, it, expect } from 'vitest'
import { filterNonsense, jobLocation } from '~/composables/useJobText'

// The job feed is not clean, and every place a job appears (the tiles in the ad slots, the mosaic
// tile, and the card on the email job page) shows its text through these two functions, so a job
// reads the same wherever a member sees it.
describe('useJobText', () => {
  describe('filterNonsense', () => {
    it('turns an escaped newline into a line break', () => {
      expect(filterNonsense('Job\\nTitle')).toBe('Job\nTitle')
    })

    it('turns a <br> into a line break', () => {
      expect(filterNonsense('Job<br>Title')).toBe('Job\nTitle')
    })

    it('puts right a pound sign that arrived as two characters', () => {
      expect(filterNonsense('Â£50k Salary')).toBe('£50k Salary')
    })

    it('trims the ends', () => {
      expect(filterNonsense('  Care Assistant \n')).toBe('Care Assistant')
    })

    it('removes accents', () => {
      expect(filterNonsense('Café Assistant')).toBe('Cafe Assistant')
      expect(filterNonsense('Zoë Müller')).toBe('Zoe Muller')
    })

    it('leaves plain text alone', () => {
      expect(filterNonsense('Driver - Newtown, Powys (£12.50/hr)')).toBe(
        'Driver - Newtown, Powys (£12.50/hr)'
      )
    })

    it('does all of it together', () => {
      expect(filterNonsense(' Café<br>Â£9 \\nPart time ')).toBe(
        'Cafe\n£9 \nPart time'
      )
    })
  })

  describe('jobLocation', () => {
    it('drops the comma and space the feed puts in front of a place', () => {
      expect(jobLocation(', Newtown')).toBe('Newtown')
      expect(jobLocation(', Newtown, Powys')).toBe('Newtown, Powys')
    })

    it('keeps a place that does not start that way', () => {
      expect(jobLocation('Newtown, Powys')).toBe('Newtown, Powys')
      expect(jobLocation('London')).toBe('London')
    })

    it('only drops a comma that has a space after it', () => {
      expect(jobLocation(',Newtown')).toBe(',Newtown')
    })

    it('does not touch a comma that is not first', () => {
      expect(jobLocation('Leeds, ')).toBe('Leeds, ')
    })

    it('gives an empty string when there is no place', () => {
      expect(jobLocation(undefined)).toBe('')
      expect(jobLocation(null)).toBe('')
      expect(jobLocation('')).toBe('')
    })

    it('does not run the title clean-up over a place', () => {
      expect(jobLocation('Llanelli Â£')).toBe('Llanelli Â£')
    })
  })
})
