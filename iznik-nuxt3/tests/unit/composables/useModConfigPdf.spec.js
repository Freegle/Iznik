import { describe, it, expect, vi } from 'vitest'
import {
  buildModConfigDocument,
  pdfFilename,
  renderModConfigPdf,
} from '~/composables/useModConfigPdf'

function makeConfig(overrides = {}) {
  return {
    id: 42,
    name: 'Test Config',
    chatread: 0,
    fromname: 'My name',
    coloursubj: 1,
    subjlen: 67,
    subjreg: '/^OFFER/',
    network: 'Freegle',
    protected: 0,
    ccrejectto: 'Nobody',
    ccrejectaddr: '',
    ccfollowupto: 'Nobody',
    ccfollowupaddr: '',
    ccfollmembto: 'Nobody',
    ccfollmembaddr: '',
    messageorder: null,
    stdmsgs: [],
    ...overrides,
  }
}

function makeStdMsg(overrides = {}) {
  return {
    id: 1,
    title: 'Approve',
    action: 'Approve',
    autosend: 0,
    rarelyused: 0,
    newmodstatus: 'UNCHANGED',
    newdelstatus: 'UNCHANGED',
    subjpref: '',
    subjsuff: '',
    insert: null,
    edittext: '',
    body: '',
    ...overrides,
  }
}

function sectionNamed(doc, heading) {
  return doc.sections.find((s) => s.heading === heading)
}

function rowNamed(rows, label) {
  return rows.find((r) => r.label === label)
}

describe('buildModConfigDocument', () => {
  const exportedAt = new Date('2026-08-08T14:05:00Z')

  it('refuses to export nothing', () => {
    expect(() => buildModConfigDocument(null)).toThrow('No config to export')
  })

  it('titles the document with the config name', () => {
    const doc = buildModConfigDocument(makeConfig({ name: 'South West' }), {
      exportedAt,
    })

    expect(doc.title).toBe('South West')
  })

  it('falls back to a title when the config has no name', () => {
    const doc = buildModConfigDocument(makeConfig({ name: '' }), { exportedAt })

    expect(doc.title).toBe('Standard Messages')
  })

  it('stamps who exported it and when', () => {
    const doc = buildModConfigDocument(makeConfig(), {
      exportedAt,
      exportedBy: 'Jane Moderator',
    })

    expect(rowNamed(doc.meta, 'Exported by').value).toBe('Jane Moderator')
    expect(rowNamed(doc.meta, 'Exported').value).toContain('2026')
    expect(rowNamed(doc.meta, 'Config id').value).toBe('42')
  })

  it('leaves out the exporter when we do not know who it is', () => {
    const doc = buildModConfigDocument(makeConfig(), { exportedAt })

    expect(rowNamed(doc.meta, 'Exported by')).toBeUndefined()
  })

  it('has the four sections a mod sees on the settings page, in that order', () => {
    const doc = buildModConfigDocument(makeConfig(), { exportedAt })

    expect(doc.sections.map((s) => s.heading)).toEqual([
      'General Settings',
      'Pending Messages',
      'Approved Messages',
      'Approved Members',
    ])
  })

  describe('general settings', () => {
    it('says what the settings mean rather than what we store', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          chatread: 1,
          fromname: 'My name',
          coloursubj: 1,
          protected: 1,
        }),
        { exportedAt }
      )

      const rows = sectionNamed(doc, 'General Settings').rows
      expect(rowNamed(rows, 'Leave chat unread?').value).toBe('Mark as read')
      expect(rowNamed(rows, "'From:' name in messages").value).toBe('Own Name')
      expect(rowNamed(rows, 'Colour-code subjects?').value).toBe('Yes')
      expect(rowNamed(rows, 'Locked against changes?').value).toBe('Yes')
    })

    it('reads the other way round too', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          chatread: 0,
          fromname: 'Groupname Moderator',
          coloursubj: 0,
          protected: 0,
        }),
        { exportedAt }
      )

      const rows = sectionNamed(doc, 'General Settings').rows
      expect(rowNamed(rows, 'Leave chat unread?').value).toBe('Leave as unread')
      expect(rowNamed(rows, "'From:' name in messages").value).toBe(
        '$groupname Moderator'
      )
      expect(rowNamed(rows, 'Colour-code subjects?').value).toBe('No')
      expect(rowNamed(rows, 'Locked against changes?').value).toBe('No')
    })

    it('shows a dash rather than a blank for an empty setting', () => {
      const doc = buildModConfigDocument(makeConfig({ subjreg: '  ' }), {
        exportedAt,
      })

      const rows = sectionNamed(doc, 'General Settings').rows
      expect(rowNamed(rows, 'Regular expression for colour-coding').value).toBe(
        '-'
      )
    })
  })

  describe('BCC settings', () => {
    it('includes the specific address only when BCC is set to Specific', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          ccrejectto: 'Specific',
          ccrejectaddr: 'mods@example.org',
          ccfollowupto: 'Me',
          ccfollowupaddr: 'ignored@example.org',
        }),
        { exportedAt }
      )

      const pending = sectionNamed(doc, 'Pending Messages').rows
      expect(rowNamed(pending, 'BCC to').value).toBe('Specific')
      expect(rowNamed(pending, 'Specific address').value).toBe(
        'mods@example.org'
      )

      const approved = sectionNamed(doc, 'Approved Messages').rows
      expect(rowNamed(approved, 'BCC to').value).toBe('Me')
      expect(rowNamed(approved, 'Specific address')).toBeUndefined()
    })
  })

  describe('standard messages', () => {
    it('files each message under the section its action belongs to', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          stdmsgs: [
            makeStdMsg({ id: 1, title: 'Approve it', action: 'Approve' }),
            makeStdMsg({
              id: 2,
              title: 'Chase',
              action: 'Leave Approved Message',
            }),
            makeStdMsg({
              id: 3,
              title: 'Welcome',
              action: 'Leave Approved Member',
            }),
          ],
        }),
        { exportedAt }
      )

      expect(
        sectionNamed(doc, 'Pending Messages').messages.map((m) => m.title)
      ).toEqual(['Approve it'])
      expect(
        sectionNamed(doc, 'Approved Messages').messages.map((m) => m.title)
      ).toEqual(['Chase'])
      expect(
        sectionNamed(doc, 'Approved Members').messages.map((m) => m.title)
      ).toEqual(['Welcome'])
    })

    it('keeps the order the mod arranged their buttons in', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          messageorder: JSON.stringify([3, 1, 2]),
          stdmsgs: [
            makeStdMsg({ id: 1, title: 'First added', action: 'Approve' }),
            makeStdMsg({ id: 2, title: 'Second added', action: 'Reject' }),
            makeStdMsg({ id: 3, title: 'Third added', action: 'Reject' }),
          ],
        }),
        { exportedAt }
      )

      expect(
        sectionNamed(doc, 'Pending Messages').messages.map((m) => m.title)
      ).toEqual(['Third added', 'First added', 'Second added'])
    })

    it('does not consume the config messageorder it was given', () => {
      // copyStdMsgs shifts the array it parses; the caller's config must
      // survive being exported so the settings page still works afterwards.
      const config = makeConfig({
        messageorder: JSON.stringify([2, 1]),
        stdmsgs: [
          makeStdMsg({ id: 1, action: 'Approve' }),
          makeStdMsg({ id: 2, action: 'Reject' }),
        ],
      })

      buildModConfigDocument(config, { exportedAt })

      expect(JSON.parse(config.messageorder)).toEqual([2, 1])
    })

    it('spells out how a message behaves', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          stdmsgs: [
            makeStdMsg({
              action: 'Reject',
              autosend: 1,
              rarelyused: 1,
              subjpref: 'OFFER: ',
              subjsuff: ' (area)',
              insert: 'Top',
            }),
          ],
        }),
        { exportedAt }
      )

      const rows = sectionNamed(doc, 'Pending Messages').messages[0].rows
      expect(rowNamed(rows, 'Action').value).toBe('Reject')
      expect(rowNamed(rows, 'Autosend').value).toBe('Send immediately')
      expect(rowNamed(rows, 'How often used').value).toBe('Rarely')
      expect(rowNamed(rows, 'Subject prefix').value).toBe('OFFER: ')
      expect(rowNamed(rows, 'Subject suffix').value).toBe(' (area)')
      expect(rowNamed(rows, 'Insert text').value).toBe('Top')
    })

    it('translates status changes into the words the UI uses', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          stdmsgs: [
            makeStdMsg({
              newmodstatus: 'PROHIBITED',
              newdelstatus: 'ANNOUNCEMENT',
            }),
          ],
        }),
        { exportedAt }
      )

      const rows = sectionNamed(doc, 'Pending Messages').messages[0].rows
      expect(rowNamed(rows, 'Change moderation status').value).toBe(
        "Can't Post"
      )
      expect(rowNamed(rows, 'Change delivery settings').value).toBe(
        'Special Notices'
      )
    })

    it('leaves out status rows that change nothing', () => {
      const doc = buildModConfigDocument(
        makeConfig({ stdmsgs: [makeStdMsg()] }),
        { exportedAt }
      )

      const rows = sectionNamed(doc, 'Pending Messages').messages[0].rows
      expect(rowNamed(rows, 'Change moderation status')).toBeUndefined()
      expect(rowNamed(rows, 'Change delivery settings')).toBeUndefined()
      expect(rowNamed(rows, 'Subject prefix')).toBeUndefined()
    })

    it('carries the edit text and the message body', () => {
      const doc = buildModConfigDocument(
        makeConfig({
          stdmsgs: [
            makeStdMsg({
              edittext: 'Reject - wrong format',
              body: 'Hi there,\n\nPlease repost.',
            }),
          ],
        }),
        { exportedAt }
      )

      const message = sectionNamed(doc, 'Pending Messages').messages[0]
      expect(message.editText).toBe('Reject - wrong format')
      expect(message.body).toBe('Hi there,\n\nPlease repost.')
    })

    it('copes with a config that has no messages at all', () => {
      const doc = buildModConfigDocument(makeConfig({ stdmsgs: [] }), {
        exportedAt,
      })

      doc.sections.forEach((section) => {
        expect(section.messages).toEqual([])
      })
    })
  })
})

describe('pdfFilename', () => {
  const exportedAt = new Date('2026-08-08T14:05:00Z')

  it('is something you can find again in Downloads', () => {
    expect(pdfFilename({ name: 'South West Mods' }, exportedAt)).toBe(
      'south-west-mods-2026-08-08.pdf'
    )
  })

  it('flattens punctuation that has no business in a filename', () => {
    expect(
      pdfFilename({ name: 'Freegle: standard/messages (v2)!' }, exportedAt)
    ).toBe('freegle-standard-messages-v2-2026-08-08.pdf')
  })

  it('still produces a filename for a nameless config', () => {
    expect(pdfFilename({}, exportedAt)).toBe('modconfig-2026-08-08.pdf')
    expect(pdfFilename({ name: '!!!' }, exportedAt)).toBe(
      'modconfig-2026-08-08.pdf'
    )
  })
})

describe('renderModConfigPdf', () => {
  // A stand-in for jsPDF that records what was drawn, so the layout rules can
  // be tested without producing an actual PDF.
  function fakePdf() {
    const calls = { text: [], pages: 1 }

    return {
      calls,
      setFont: vi.fn(),
      setFontSize: vi.fn(),
      setTextColor: vi.fn(),
      setDrawColor: vi.fn(),
      setFillColor: vi.fn(),
      setLineWidth: vi.fn(),
      line: vi.fn(),
      rect: vi.fn(),
      setPage: vi.fn(),
      getNumberOfPages: () => calls.pages,
      addPage: vi.fn(() => {
        calls.pages++
      }),
      // Wrap on width alone; enough to exercise the page-break arithmetic.
      splitTextToSize: (text, width) => {
        const perLine = Math.max(1, Math.floor(width / 5))
        return String(text)
          .split('\n')
          .flatMap((line) => {
            const out = []
            for (let i = 0; i < line.length; i += perLine) {
              out.push(line.slice(i, i + perLine))
            }
            return out.length ? out : ['']
          })
      },
      text: vi.fn((str, x, y, opts) => {
        calls.text.push({ str, x, y, page: calls.pages, opts })
      }),
    }
  }

  const doc = {
    title: 'South West',
    meta: [{ label: 'Exported', value: '8 August 2026' }],
    sections: [
      {
        heading: 'General Settings',
        rows: [{ label: 'Name', value: 'South West' }],
        messages: [],
      },
      {
        heading: 'Pending Messages',
        rows: [{ label: 'BCC to', value: 'Nobody' }],
        messages: [
          {
            title: 'Wrong format',
            rows: [{ label: 'Action', value: 'Reject' }],
            editText: 'Reject - wrong format',
            body: 'Hi there,\n\nPlease repost.',
          },
        ],
      },
    ],
  }

  function drawn(pdf) {
    return pdf.calls.text.map((t) => t.str)
  }

  it('writes the title, the headings and the message content', () => {
    const pdf = fakePdf()
    renderModConfigPdf(pdf, doc)

    const text = drawn(pdf)
    expect(text).toContain('South West')
    expect(text).toContain('Standard Messages configuration')
    expect(text).toContain('General Settings')
    expect(text).toContain('Pending Messages')
    expect(text).toContain('Wrong format')
    expect(text).toContain('Edit text')
    expect(text).toContain('Message body')
    expect(text).toContain('Hi there,')
  })

  it('numbers every page', () => {
    const pdf = fakePdf()
    renderModConfigPdf(pdf, doc)

    expect(drawn(pdf)).toContain('Page 1 of 1')
  })

  it('says when a section has no standard messages', () => {
    const pdf = fakePdf()
    renderModConfigPdf(pdf, {
      ...doc,
      sections: [
        doc.sections[0],
        { heading: 'Pending Messages', rows: [], messages: [] },
      ],
    })

    expect(drawn(pdf)).toContain('No standard messages in this section.')
  })

  it('breaks the page rather than running off the bottom', () => {
    const pdf = fakePdf()
    const long = Array.from({ length: 40 }, (_, i) => `Line ${i}`).join('\n')

    renderModConfigPdf(pdf, {
      ...doc,
      sections: [
        {
          heading: 'Pending Messages',
          rows: [],
          messages: Array.from({ length: 4 }, (_, i) => ({
            title: `Message ${i}`,
            rows: [{ label: 'Action', value: 'Reject' }],
            editText: '',
            body: long,
          })),
        },
      ],
    })

    expect(pdf.getNumberOfPages()).toBeGreaterThan(1)
    // Nothing may be drawn below the bottom margin.
    pdf.calls.text.forEach((t) => {
      expect(t.y).toBeLessThanOrEqual(841.89 - 56 + 24)
    })
  })

  it('repeats the config name as a running header after page one', () => {
    const pdf = fakePdf()
    const long = Array.from({ length: 60 }, (_, i) => `Line ${i}`).join('\n')

    renderModConfigPdf(pdf, {
      ...doc,
      sections: [
        {
          heading: 'Pending Messages',
          rows: [],
          messages: [
            {
              title: 'Long one',
              rows: [],
              editText: '',
              body: long,
            },
          ],
        },
      ],
    })

    expect(pdf.getNumberOfPages()).toBeGreaterThan(1)
    // The header is drawn once per page after the first.
    const headers = pdf.calls.text.filter(
      (t) => t.str === 'South West' && t.y < 64
    )
    expect(headers.length).toBe(pdf.getNumberOfPages() - 1)
  })
})
