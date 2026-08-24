import { describe, it, expect } from 'vitest'
import {
  splitCsv,
  parseItemsCsv,
  parseItemsRows,
  normaliseCondition,
  blankBulkItem,
  BULK_CONDITIONS,
} from '~/composables/useBulkItems'

describe('useBulkItems', () => {
  describe('splitCsv', () => {
    it('splits simple rows', () => {
      expect(splitCsv('a,b,c\nd,e,f')).toEqual([
        ['a', 'b', 'c'],
        ['d', 'e', 'f'],
      ])
    })

    it('handles quoted fields with commas', () => {
      expect(splitCsv('"Desk, large",4')).toEqual([['Desk, large', '4']])
    })

    it('handles escaped quotes', () => {
      expect(splitCsv('"He said ""hi""",1')).toEqual([['He said "hi"', '1']])
    })

    it('handles CRLF line endings', () => {
      expect(splitCsv('a,b\r\nc,d')).toEqual([
        ['a', 'b'],
        ['c', 'd'],
      ])
    })

    it('drops blank rows', () => {
      expect(splitCsv('a,b\n\n , \nc,d')).toEqual([
        ['a', 'b'],
        ['c', 'd'],
      ])
    })
  })

  describe('normaliseCondition', () => {
    it('maps common spreadsheet values onto the enum', () => {
      expect(normaliseCondition('new')).toBe('New')
      expect(normaliseCondition('Like New')).toBe('LikeNew')
      expect(normaliseCondition('excellent')).toBe('LikeNew')
      expect(normaliseCondition('GOOD')).toBe('Good')
      expect(normaliseCondition('fair')).toBe('Used')
      expect(normaliseCondition('for parts')).toBe('Poor')
    })

    it('defaults to Unknown for blanks and unrecognised values', () => {
      expect(normaliseCondition('')).toBe('Unknown')
      expect(normaliseCondition(null)).toBe('Unknown')
      expect(normaliseCondition('wibble')).toBe('Unknown')
    })

    it('passes through exact enum values', () => {
      for (const c of BULK_CONDITIONS) {
        expect(normaliseCondition(c.value)).toBe(c.value)
      }
    })
  })

  describe('parseItemsCsv', () => {
    it('parses a header + rows', () => {
      const csv =
        'name,quantity,condition,dimensions,description\n' +
        'Office desk,4,Good,120x80cm,Sturdy\n' +
        'Chair,14,Used,,'
      const items = parseItemsCsv(csv)
      expect(items).toHaveLength(2)
      expect(items[0]).toMatchObject({
        name: 'Office desk',
        quantity: 4,
        condition: 'Good',
        dimensions: '120x80cm',
        description: 'Sturdy',
      })
      expect(items[1]).toMatchObject({
        name: 'Chair',
        quantity: 14,
        condition: 'Used',
        dimensions: null,
        description: null,
      })
    })

    it('recognises alternative header names and any column order', () => {
      const csv = 'Qty,Item,Notes\n3,Lamp,Working'
      const items = parseItemsCsv(csv)
      expect(items[0]).toMatchObject({
        name: 'Lamp',
        quantity: 3,
        description: 'Working',
      })
    })

    it('assumes name,qty,condition,... when there is no header', () => {
      const items = parseItemsCsv('Sofa,2,Good\nTable,1,Used')
      expect(items).toHaveLength(2)
      expect(items[0]).toMatchObject({
        name: 'Sofa',
        quantity: 2,
        condition: 'Good',
      })
    })

    it('defaults a missing/invalid quantity to 1', () => {
      const items = parseItemsCsv('name,quantity\nWidget,\nGadget,abc\nThing,0')
      expect(items.map((i) => i.quantity)).toEqual([1, 1, 1])
    })

    it('skips rows with no name', () => {
      const items = parseItemsCsv('name,quantity\n,5\nDesk,2')
      expect(items).toHaveLength(1)
      expect(items[0].name).toBe('Desk')
    })

    it('returns [] for empty input', () => {
      expect(parseItemsCsv('')).toEqual([])
      expect(parseItemsCsv(null)).toEqual([])
    })

    it('every parsed item has an empty attachments array', () => {
      const items = parseItemsCsv('Desk,1')
      expect(items[0].attachments).toEqual([])
    })
  })

  describe('photo URL column', () => {
    it('parses an http(s) photo link from a recognised column', () => {
      const csv =
        'name,quantity,photo\nDesk,2,https://example.com/desk.jpg\nChair,1,'
      const items = parseItemsCsv(csv)
      expect(items[0].photourl).toBe('https://example.com/desk.jpg')
      expect(items[1].photourl).toBeNull()
    })

    it('ignores non-URL values in the photo column', () => {
      const items = parseItemsCsv('name,photo\nDesk,not-a-url')
      expect(items[0].photourl).toBeNull()
    })

    it('recognises alternative photo header names', () => {
      const items = parseItemsCsv('item,image url\nLamp,http://x.test/l.png')
      expect(items[0].photourl).toBe('http://x.test/l.png')
    })
  })

  describe('comment rows', () => {
    it('skips lines whose name starts with #', () => {
      const items = parseItemsCsv(
        'name,quantity\n# a guidance comment,0\nDesk,2'
      )
      expect(items).toHaveLength(1)
      expect(items[0].name).toBe('Desk')
    })
  })

  describe('tab-separated (spreadsheet copy-paste)', () => {
    it('parses tab-delimited rows pasted from a spreadsheet', () => {
      const tsv =
        'name\tquantity\tcondition\tdimensions\tphoto\tdescription\n' +
        'Office desk\t4\tGood\t120x80cm\thttps://example.com/d.jpg\tBeech\n' +
        'Swivel chair\t14\tUsed\t\t\t'
      const items = parseItemsCsv(tsv)
      expect(items).toHaveLength(2)
      expect(items[0]).toMatchObject({
        name: 'Office desk',
        quantity: 4,
        condition: 'Good',
        photourl: 'https://example.com/d.jpg',
      })
      expect(items[1].name).toBe('Swivel chair')
    })
  })

  describe('parseItemsRows (uploaded .xlsx)', () => {
    it('parses rows with non-string cells (numbers from a spreadsheet)', () => {
      const rows = [
        ['name', 'quantity', 'condition', 'dimensions', 'photo', 'description'],
        [
          'Office desk',
          4,
          'Good',
          '120x80cm',
          'https://example.com/d.jpg',
          'Beech',
        ],
        ['Swivel chair', 14, 'Used', null, null, null],
      ]
      const items = parseItemsRows(rows)
      expect(items).toHaveLength(2)
      expect(items[0]).toMatchObject({
        name: 'Office desk',
        quantity: 4,
        condition: 'Good',
        photourl: 'https://example.com/d.jpg',
      })
      expect(items[1]).toMatchObject({ name: 'Swivel chair', quantity: 14 })
    })

    it('returns [] for empty rows', () => {
      expect(parseItemsRows([])).toEqual([])
      expect(parseItemsRows(null)).toEqual([])
    })
  })

  describe('blankBulkItem', () => {
    it('returns a sensible default row', () => {
      expect(blankBulkItem()).toEqual({
        name: '',
        quantity: 1,
        condition: 'Unknown',
        dimensions: null,
        photourl: null,
        description: null,
        attachments: [],
      })
    })
  })
})
