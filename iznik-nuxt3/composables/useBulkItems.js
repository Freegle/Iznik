// Helpers for the bulk-offer ("clearance") composer: parsing a pasted/uploaded
// spreadsheet of items into the structured shape the API expects.

export const BULK_CONDITIONS = [
  { value: 'New', text: 'New' },
  { value: 'LikeNew', text: 'Like new' },
  { value: 'Good', text: 'Good' },
  { value: 'Used', text: 'Used' },
  { value: 'Poor', text: 'Poor' },
  { value: 'Unknown', text: 'Not sure' },
]

const VALID_CONDITIONS = BULK_CONDITIONS.map((c) => c.value)

// Map a free-text condition (from a spreadsheet) onto our enum, tolerantly.
export function normaliseCondition(raw) {
  if (!raw) return 'Unknown'
  const v = String(raw)
    .trim()
    .toLowerCase()
    .replace(/[\s_-]+/g, '')
  const map = {
    new: 'New',
    likenew: 'LikeNew',
    asnew: 'LikeNew',
    excellent: 'LikeNew',
    good: 'Good',
    used: 'Used',
    fair: 'Used',
    poor: 'Poor',
    forparts: 'Poor',
    unknown: 'Unknown',
    notsure: 'Unknown',
  }
  if (map[v]) return map[v]
  // Exact (case-sensitive) enum value passthrough.
  const exact = VALID_CONDITIONS.find((c) => c.toLowerCase() === v)
  return exact || 'Unknown'
}

// Minimal RFC-4180-ish splitter: handles quoted fields, escaped quotes ("")
// and the delimiter inside quotes. Auto-detects the delimiter so it works for
// both a downloaded/edited CSV (comma) and cells copied straight out of a
// spreadsheet (tab) — the latter is how the xlsx template is meant to be used.
export function splitCsv(text) {
  const t = text || ''
  const firstLine = t.split(/\r?\n/, 1)[0] || ''
  const delim = firstLine.includes('\t') ? '\t' : ','

  const rows = []
  let row = []
  let field = ''
  let inQuotes = false

  for (let i = 0; i < t.length; i++) {
    const ch = t[i]
    if (inQuotes) {
      if (ch === '"') {
        if (t[i + 1] === '"') {
          field += '"'
          i++
        } else {
          inQuotes = false
        }
      } else {
        field += ch
      }
    } else if (ch === '"') {
      inQuotes = true
    } else if (ch === delim) {
      row.push(field)
      field = ''
    } else if (ch === '\n' || ch === '\r') {
      // Handle \r\n as one break.
      if (ch === '\r' && text[i + 1] === '\n') i++
      row.push(field)
      field = ''
      rows.push(row)
      row = []
    } else {
      field += ch
    }
  }
  // Trailing field/row (file without final newline).
  if (field !== '' || row.length) {
    row.push(field)
    rows.push(row)
  }
  // Drop fully-empty rows.
  return rows.filter((r) => r.some((c) => c.trim() !== ''))
}

// Parse a spreadsheet of items. Recognises a header row with any of the columns
// name/item, quantity/qty/count, condition, dimensions/size/measurements,
// description/notes. If no recognisable header is found, assumes the columns are
// name, quantity, condition, dimensions, description in order.
export function parseItemsCsv(text) {
  return parseItemsRows(splitCsv(text || ''))
}

// Parse already-split rows (an array of arrays of cells) into structured items.
// Cells may be non-strings (e.g. numbers from an .xlsx reader); they're coerced
// to strings. Same header/column detection as parseItemsCsv.
export function parseItemsRows(rawRows) {
  const rows = (rawRows || []).map((r) =>
    (r || []).map((c) => (c == null ? '' : String(c)))
  )
  if (!rows.length) return []

  const headerCells = rows[0].map((c) => c.trim().toLowerCase())
  const known = {
    name: ['name', 'item', 'item name', 'title'],
    quantity: ['quantity', 'qty', 'count', 'number', 'amount'],
    condition: ['condition', 'state'],
    dimensions: ['dimensions', 'size', 'measurements', 'measurement'],
    photourl: [
      'photo',
      'photos',
      'image',
      'imageurl',
      'image url',
      'photo url',
      'photourl',
      'url',
      'link',
    ],
    description: ['description', 'notes', 'detail', 'details'],
  }

  const findCol = (aliases) => headerCells.findIndex((h) => aliases.includes(h))

  const hasHeader = Object.values(known).some(
    (aliases) => findCol(aliases) !== -1
  )

  let cols
  let dataRows
  if (hasHeader) {
    cols = {
      name: findCol(known.name),
      quantity: findCol(known.quantity),
      condition: findCol(known.condition),
      dimensions: findCol(known.dimensions),
      photourl: findCol(known.photourl),
      description: findCol(known.description),
    }
    dataRows = rows.slice(1)
  } else {
    cols = {
      name: 0,
      quantity: 1,
      condition: 2,
      dimensions: 3,
      photourl: 4,
      description: 5,
    }
    dataRows = rows
  }

  const at = (row, idx) => (idx >= 0 && idx < row.length ? row[idx].trim() : '')

  const items = []
  for (const row of dataRows) {
    const name = at(row, cols.name)
    if (!name) continue
    // Skip comment lines (e.g. the guidance row in the downloadable template).
    if (name.startsWith('#')) continue
    const qtyRaw = at(row, cols.quantity)
    let quantity = parseInt(qtyRaw, 10)
    if (!Number.isFinite(quantity) || quantity < 1) quantity = 1
    const photourl = at(row, cols.photourl)
    items.push({
      name,
      quantity,
      condition: normaliseCondition(at(row, cols.condition)),
      dimensions: at(row, cols.dimensions) || null,
      photourl: /^https?:\/\//i.test(photourl) ? photourl : null,
      description: at(row, cols.description) || null,
      attachments: [],
    })
  }
  return items
}

// A blank item row for the editor.
export function blankBulkItem() {
  return {
    name: '',
    quantity: 1,
    condition: 'Unknown',
    dimensions: null,
    photourl: null,
    description: null,
    attachments: [],
  }
}
