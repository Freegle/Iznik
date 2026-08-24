import { describe, it, expect } from 'vitest'
import {
  icon,
  variant,
  copyStdMsgs,
} from '../../../modtools/composables/useStdMsgs.js'

describe('useStdMsgs', () => {
  describe('icon', () => {
    it.each([
      ['Approve', 'check'],
      ['Reject', 'times'],
      ['Leave', 'envelope'],
      ['Leave Approved Message', 'envelope'],
      ['Leave Approved Member', 'envelope'],
      ['Delete', 'trash-alt'],
      ['Delete Approved Message', 'trash-alt'],
      ['Delete Approved Member', 'trash-alt'],
      ['Edit', 'pen'],
      ['Hold Message', 'pause'],
      ['Something Else', 'check'],
      [undefined, 'check'],
    ])('returns %s icon "%s" for action %j', (action, expected) => {
      expect(icon({ action })).toBe(expected)
    })
  })

  describe('variant', () => {
    it.each([
      ['Approve', 'primary'],
      ['Reject', 'warning'],
      ['Leave', 'primary'],
      ['Leave Approved Message', 'primary'],
      ['Leave Approved Member', 'primary'],
      ['Delete', 'danger'],
      ['Delete Approved Message', 'danger'],
      ['Delete Approved Member', 'danger'],
      ['Edit', 'primary'],
      ['Hold Message', 'primary'],
      ['Something Else', 'white'],
      [undefined, 'white'],
    ])('returns variant "%s" for action %j', (action, expected) => {
      expect(variant({ action })).toBe(expected)
    })
  })

  describe('copyStdMsgs', () => {
    it('returns the stdmsgs array unchanged when there is no messageorder', () => {
      const stdmsgs = [{ id: 1 }, { id: 2 }]

      const result = copyStdMsgs({ stdmsgs })

      expect(result).toBe(stdmsgs)
    })

    it('returns the stdmsgs array unchanged when messageorder is an empty string', () => {
      const stdmsgs = [{ id: 1 }, { id: 2 }]

      const result = copyStdMsgs({ stdmsgs, messageorder: '' })

      expect(result).toBe(stdmsgs)
    })

    it('sorts stdmsgs according to the message order', () => {
      const stdmsgs = [{ id: 1 }, { id: 2 }, { id: 3 }]

      const result = copyStdMsgs({
        stdmsgs,
        messageorder: JSON.stringify([3, 1, 2]),
      })

      expect(result.map((s) => s.id)).toEqual([3, 1, 2])
    })

    it('matches ids as numbers even when stored as strings', () => {
      const stdmsgs = [{ id: '1' }, { id: '2' }]

      const result = copyStdMsgs({
        stdmsgs,
        messageorder: JSON.stringify(['2', '1']),
      })

      expect(result.map((s) => s.id)).toEqual(['2', '1'])
    })

    it('appends stdmsgs not listed in the order at the end', () => {
      const stdmsgs = [{ id: 1 }, { id: 2 }, { id: 3 }]

      const result = copyStdMsgs({
        stdmsgs,
        messageorder: JSON.stringify([2]),
      })

      expect(result.map((s) => s.id)).toEqual([2, 1, 3])
    })

    it('ignores duplicate ids in the order so a message is not copied twice', () => {
      const stdmsgs = [{ id: 1 }, { id: 2 }]

      const result = copyStdMsgs({
        stdmsgs,
        messageorder: JSON.stringify([1, 1, 2]),
      })

      expect(result.map((s) => s.id)).toEqual([1, 2])
    })

    it('ignores order entries that do not match any stdmsg', () => {
      const stdmsgs = [{ id: 1 }, { id: 2 }]

      const result = copyStdMsgs({
        stdmsgs,
        messageorder: JSON.stringify([99, 1, 2]),
      })

      expect(result.map((s) => s.id)).toEqual([1, 2])
    })

    it('treats a JSON "[]" messageorder as truthy but empty, appending all stdmsgs in original order', () => {
      // An empty array is still truthy in JS, so this exercises the do/while
      // loop's first (empty-shift) iteration rather than skipping to the
      // no-order branch.
      const stdmsgs = [{ id: 1 }, { id: 2 }]

      const result = copyStdMsgs({ stdmsgs, messageorder: '[]' })

      expect(result.map((s) => s.id)).toEqual([1, 2])
    })

    it('returns an empty array when stdmsgs is empty, order or not', () => {
      expect(copyStdMsgs({ stdmsgs: [] })).toEqual([])
      expect(
        copyStdMsgs({ stdmsgs: [], messageorder: JSON.stringify([1, 2]) })
      ).toEqual([])
    })
  })
})
