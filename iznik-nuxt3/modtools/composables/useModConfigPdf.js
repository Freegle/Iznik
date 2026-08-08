import { copyStdMsgs } from '~/composables/useStdMsgs'

/**
 * Turning a ModConfig into a document someone can read away from the screen.
 *
 * Mods review each other's standard messages, hand them over when a group
 * changes hands, and get asked by Freegle HQ what their group actually sends.
 * All of that means reading the whole config at once, which the settings page -
 * an accordion of one-at-a-time sections - is the wrong shape for.
 *
 * Split in two on purpose: buildModConfigDocument() decides WHAT the document
 * says and is pure, so it can be tested without a PDF anywhere near it;
 * renderModConfigPdf() decides what it LOOKS like.
 */

// The sections of the settings page, in the order a mod sees them, with the
// message actions that belong to each. Kept in step with ModSettingsModConfig.
export const MESSAGE_SECTIONS = [
  {
    heading: 'Pending Messages',
    cc: 'ccrejectto',
    addr: 'ccrejectaddr',
    actions: ['Approve', 'Reject', 'Leave', 'Delete', 'Edit', 'Hold Message'],
  },
  {
    heading: 'Approved Messages',
    cc: 'ccfollowupto',
    addr: 'ccfollowupaddr',
    actions: ['Leave Approved Message', 'Delete Approved Message', 'Edit'],
  },
  {
    heading: 'Approved Members',
    cc: 'ccfollmembto',
    addr: 'ccfollmembaddr',
    actions: ['Leave Approved Member', 'Delete Approved Member'],
  },
]

// The wording mods see in the settings UI, so the PDF says the same thing the
// screen does rather than exposing what we happen to store.
const MOD_STATUS = {
  UNCHANGED: 'Unchanged',
  MODERATED: 'Moderated',
  DEFAULT: 'Group Settings',
  PROHIBITED: "Can't Post",
  UNMODERATED: 'Unmoderated',
}

const DELIVERY_STATUS = {
  UNCHANGED: 'Unchanged',
  DIGEST: 'Daily Digest',
  NONE: 'Web Only',
  SINGLE: 'Individual Emails',
  ANNOUNCEMENT: 'Special Notices',
}

function yesNo(value) {
  return parseInt(value) ? 'Yes' : 'No'
}

function blankAsDash(value) {
  const str = value === null || value === undefined ? '' : String(value).trim()
  return str.length ? str : '-'
}

function generalRows(config) {
  return [
    { label: 'Name', value: blankAsDash(config.name) },
    {
      label: 'Leave chat unread?',
      value: parseInt(config.chatread) ? 'Mark as read' : 'Leave as unread',
    },
    {
      label: "'From:' name in messages",
      value:
        config.fromname === 'My name' ? 'Own Name' : '$groupname Moderator',
    },
    { label: 'Colour-code subjects?', value: yesNo(config.coloursubj) },
    { label: 'Subject length warning', value: blankAsDash(config.subjlen) },
    {
      label: 'Regular expression for colour-coding',
      value: blankAsDash(config.subjreg),
    },
    {
      label: '$network substitution string',
      value: blankAsDash(config.network),
    },
    { label: 'Locked against changes?', value: yesNo(config.protected) },
  ]
}

function messageRows(stdmsg) {
  const rows = [
    { label: 'Action', value: blankAsDash(stdmsg.action) },
    {
      label: 'Autosend',
      value: parseInt(stdmsg.autosend)
        ? 'Send immediately'
        : 'Edit before send',
    },
    {
      label: 'How often used',
      value: parseInt(stdmsg.rarelyused) ? 'Rarely' : 'Frequently',
    },
  ]

  // Only worth the ink when the message actually changes something.
  if (stdmsg.newmodstatus && stdmsg.newmodstatus !== 'UNCHANGED') {
    rows.push({
      label: 'Change moderation status',
      value: MOD_STATUS[stdmsg.newmodstatus] || stdmsg.newmodstatus,
    })
  }

  if (stdmsg.newdelstatus && stdmsg.newdelstatus !== 'UNCHANGED') {
    rows.push({
      label: 'Change delivery settings',
      value: DELIVERY_STATUS[stdmsg.newdelstatus] || stdmsg.newdelstatus,
    })
  }

  if (stdmsg.subjpref) {
    rows.push({ label: 'Subject prefix', value: stdmsg.subjpref })
  }

  if (stdmsg.subjsuff) {
    rows.push({ label: 'Subject suffix', value: stdmsg.subjsuff })
  }

  if (stdmsg.insert) {
    rows.push({ label: 'Insert text', value: stdmsg.insert })
  }

  return rows
}

function ccRows(config, section) {
  const to = config[section.cc]
  const rows = [{ label: 'BCC to', value: blankAsDash(to) }]

  if (to === 'Specific') {
    rows.push({
      label: 'Specific address',
      value: blankAsDash(config[section.addr]),
    })
  }

  return rows
}

/**
 * Describe a config as an ordered document: headings, label/value rows and
 * message bodies. No PDF, no formatting decisions - just what it says.
 *
 * @param {object} config A config as returned by the modconfigs API.
 * @param {object} [opts]
 * @param {Date} [opts.exportedAt] Stamped on the front page.
 * @param {string} [opts.exportedBy] Display name of whoever pressed the button.
 * @returns {object} { title, meta, sections }
 */
export function buildModConfigDocument(config, opts = {}) {
  if (!config) {
    throw new Error('No config to export')
  }

  const exportedAt = opts.exportedAt || new Date()

  const meta = [
    {
      label: 'Exported',
      value: exportedAt.toLocaleString('en-GB', {
        dateStyle: 'long',
        timeStyle: 'short',
      }),
    },
  ]

  if (opts.exportedBy) {
    meta.push({ label: 'Exported by', value: opts.exportedBy })
  }

  meta.push({ label: 'Config id', value: String(config.id ?? '-') })

  // Ordered the way the mod ordered their buttons, so the paper matches the
  // screen. copyStdMsgs mutates the order it parses, hence the fresh object.
  const ordered = config.stdmsgs?.length
    ? copyStdMsgs({
        messageorder: config.messageorder,
        stdmsgs: config.stdmsgs,
      })
    : []

  const sections = [
    {
      heading: 'General Settings',
      rows: generalRows(config),
      messages: [],
    },
  ]

  MESSAGE_SECTIONS.forEach((section) => {
    const messages = ordered
      .filter((s) => section.actions.includes(s.action))
      .map((s) => ({
        title: blankAsDash(s.title),
        rows: messageRows(s),
        editText: s.edittext || '',
        body: s.body || '',
      }))

    sections.push({
      heading: section.heading,
      rows: ccRows(config, section),
      messages,
    })
  })

  return {
    title: config.name || 'Standard Messages',
    meta,
    sections,
  }
}

/**
 * A filename someone can find again in their Downloads folder.
 */
export function pdfFilename(config, exportedAt = new Date()) {
  const name = (config?.name || 'modconfig')
    .replace(/[^a-z0-9]+/gi, '-')
    .replace(/^-+|-+$/g, '')
    .toLowerCase()

  const date = exportedAt.toISOString().slice(0, 10)

  return `${name || 'modconfig'}-${date}.pdf`
}

// A4 in points, and the space we leave around the text.
const PAGE = { width: 595.28, height: 841.89 }
const MARGIN = { top: 64, bottom: 56, left: 56, right: 56 }
const CONTENT_WIDTH = PAGE.width - MARGIN.left - MARGIN.right
const LABEL_WIDTH = 170

/**
 * Draw a document from buildModConfigDocument onto a jsPDF instance.
 *
 * Everything here is layout: where things sit, when to break the page, what is
 * bold. Exported so it can be driven with a fake doc in tests.
 */
export function renderModConfigPdf(pdf, doc) {
  let y = MARGIN.top

  // Running header and page number, added at the end when we know the total.
  function decoratePages() {
    const pages = pdf.getNumberOfPages()

    for (let page = 1; page <= pages; page++) {
      pdf.setPage(page)
      pdf.setFont('helvetica', 'normal')
      pdf.setFontSize(8)
      pdf.setTextColor(120)

      if (page > 1) {
        // Page one has the title block, so it does not need telling.
        pdf.text(doc.title, MARGIN.left, MARGIN.top - 28)
        pdf.setDrawColor(220)
        pdf.line(
          MARGIN.left,
          MARGIN.top - 22,
          PAGE.width - MARGIN.right,
          MARGIN.top - 22
        )
      }

      pdf.text(
        `Page ${page} of ${pages}`,
        PAGE.width - MARGIN.right,
        PAGE.height - MARGIN.bottom + 24,
        { align: 'right' }
      )
      pdf.setTextColor(0)
    }
  }

  function room(needed) {
    if (y + needed > PAGE.height - MARGIN.bottom) {
      pdf.addPage()
      y = MARGIN.top
      return true
    }
    return false
  }

  // A label/value pair in two columns. The label wraps rather than running
  // into the value - "Regular expression for colour-coding" is wider than the
  // column, and a label sitting on top of its own value is unreadable.
  function labelledRow(row, { size, indent, labelStyle, labelColour }) {
    pdf.setFontSize(size)

    pdf.setFont('helvetica', labelStyle)
    const labelLines = pdf.splitTextToSize(row.label, LABEL_WIDTH - indent - 10)

    pdf.setFont('helvetica', 'normal')
    const valueLines = pdf.splitTextToSize(
      row.value,
      CONTENT_WIDTH - LABEL_WIDTH
    )

    const lineHeight = size * 1.3
    const height =
      Math.max(labelLines.length, valueLines.length, 1) * lineHeight

    room(height)

    pdf.setFont('helvetica', labelStyle)
    pdf.setTextColor(labelColour)
    labelLines.forEach((line, i) => {
      pdf.text(line, MARGIN.left + indent, y + i * lineHeight)
    })

    pdf.setFont('helvetica', 'normal')
    pdf.setTextColor(0)
    valueLines.forEach((line, i) => {
      pdf.text(line, MARGIN.left + LABEL_WIDTH, y + i * lineHeight)
    })

    y += height
  }

  function paragraph(text, { size, style, indent = 0, colour = 0, gap = 4 }) {
    pdf.setFont('helvetica', style)
    pdf.setFontSize(size)
    pdf.setTextColor(colour)

    const lines = pdf.splitTextToSize(text, CONTENT_WIDTH - indent)
    const lineHeight = size * 1.35

    lines.forEach((line) => {
      room(lineHeight)
      pdf.text(line, MARGIN.left + indent, y)
      y += lineHeight
    })

    y += gap
    pdf.setTextColor(0)
  }

  // --- Title block ---
  pdf.setFont('helvetica', 'bold')
  pdf.setFontSize(20)
  pdf.text(doc.title, MARGIN.left, y)
  y += 26

  pdf.setFont('helvetica', 'normal')
  pdf.setFontSize(10)
  pdf.setTextColor(90)
  pdf.text('Standard Messages configuration', MARGIN.left, y)
  y += 16

  pdf.setFontSize(9)
  doc.meta.forEach((m) => {
    pdf.text(`${m.label}: ${m.value}`, MARGIN.left, y)
    y += 12
  })
  pdf.setTextColor(0)

  y += 6
  pdf.setDrawColor(180)
  pdf.setLineWidth(1)
  pdf.line(MARGIN.left, y, PAGE.width - MARGIN.right, y)
  y += 24

  // --- Sections ---
  doc.sections.forEach((section, index) => {
    // A heading with nothing under it on the page is worse than a page break.
    if (index > 0) {
      room(90)
    }

    pdf.setFont('helvetica', 'bold')
    pdf.setFontSize(14)
    pdf.text(section.heading, MARGIN.left, y)
    y += 8
    pdf.setDrawColor(200)
    pdf.setLineWidth(0.5)
    pdf.line(MARGIN.left, y, PAGE.width - MARGIN.right, y)
    y += 18

    section.rows.forEach((row) => {
      labelledRow(row, {
        size: 10,
        indent: 0,
        labelStyle: 'bold',
        labelColour: 70,
      })
      y += 2
    })

    if (section.messages.length) {
      y += 10
    } else if (index > 0) {
      // Say so, so that an empty section reads as empty rather than as
      // something that failed to print.
      y += 12
      paragraph('No standard messages in this section.', {
        size: 9,
        style: 'italic',
        indent: 12,
        colour: 120,
        gap: 2,
      })
    }

    section.messages.forEach((message) => {
      // Keep a message's title with at least the start of its detail.
      room(70)

      pdf.setFillColor(242, 242, 242)
      pdf.rect(MARGIN.left, y - 11, CONTENT_WIDTH, 20, 'F')
      pdf.setFont('helvetica', 'bold')
      pdf.setFontSize(11)
      pdf.text(message.title, MARGIN.left + 6, y + 3)
      y += 26

      message.rows.forEach((row) => {
        labelledRow(row, {
          size: 9,
          indent: 12,
          labelStyle: 'normal',
          labelColour: 110,
        })
      })

      y += 6

      if (message.editText) {
        paragraph('Edit text', {
          size: 9,
          style: 'bold',
          indent: 12,
          colour: 110,
          gap: 2,
        })
        paragraph(message.editText, { size: 9, style: 'normal', indent: 12 })
      }

      if (message.body) {
        paragraph('Message body', {
          size: 9,
          style: 'bold',
          indent: 12,
          colour: 110,
          gap: 2,
        })
        paragraph(message.body, {
          size: 9,
          style: 'normal',
          indent: 12,
          gap: 8,
        })
      } else {
        y += 6
      }
    })

    y += 14
  })

  decoratePages()

  return pdf
}

/**
 * Build and download the PDF. jsPDF is pulled in on demand - it is a big
 * library and most mods never press this button.
 */
export async function exportModConfigPdf(config, opts = {}) {
  const exportedAt = opts.exportedAt || new Date()
  const doc = buildModConfigDocument(config, { ...opts, exportedAt })

  const { jsPDF: JsPDF } = await import('jspdf')
  const pdf = new JsPDF({ unit: 'pt', format: 'a4' })

  pdf.setProperties({
    title: `${doc.title} - Standard Messages configuration`,
    creator: 'Freegle ModTools',
  })

  renderModConfigPdf(pdf, doc)
  pdf.save(pdfFilename(config, exportedAt))

  return doc
}
