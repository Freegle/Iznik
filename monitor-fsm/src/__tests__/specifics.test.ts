import { describe, it, expect } from 'vitest'
import { assessReportSpecifics, detailRequestBody } from '../specifics.js'
import { proseProblems } from '../prose.js'

describe('assessReportSpecifics', () => {
  it('asks who and where when a report points at nobody in particular', () => {
    const r = assessReportSpecifics({ text: 'A member says a group is not showing her post.' })
    expect(r.isVague).toBe(true)
    expect(r.missing.join(' ')).toMatch(/member/)
    expect(r.missing.join(' ')).toMatch(/group/)
  })

  it('leaves a report alone once it names an id', () => {
    const r = assessReportSpecifics({ text: 'A member (user 38471926) says her post is not showing.' })
    expect(r.isVague).toBe(false)
  })

  it('leaves a report alone once it gives an email address', () => {
    const r = assessReportSpecifics({ text: 'A member, jane@example.org, says her post is not showing.' })
    expect(r.isVague).toBe(false)
  })

  it('leaves a report alone once it links to the site', () => {
    const r = assessReportSpecifics({
      text: 'Someone reported this post: https://www.ilovefreegle.org/message/44120987',
    })
    expect(r.isVague).toBe(false)
  })

  it('counts a screenshot as something to work from', () => {
    const r = assessReportSpecifics({ text: 'A member cannot see the button.', hasScreenshot: true })
    expect(r.isVague).toBe(false)
  })

  it('counts a group named by triage as something to work from', () => {
    const r = assessReportSpecifics({ text: 'A group is losing posts.', groupName: 'Freegle Cardiff' })
    expect(r.isVague).toBe(false)
  })

  it('does not nag about a report that is already general', () => {
    const r = assessReportSpecifics({ text: 'Chat notification emails are going out twice.' })
    expect(r.isVague).toBe(false)
    expect(r.missing).toEqual([])
  })

  it('does not treat a year as an identifier', () => {
    const r = assessReportSpecifics({ text: 'A member told me in 2026 that a group lost her post.' })
    expect(r.isVague).toBe(true)
  })

  it('asks for the post when one is referred to but not linked', () => {
    const r = assessReportSpecifics({ text: 'Someone deleted a post and it came back.' })
    expect(r.missing.join(' ')).toMatch(/post/)
  })

  it('asks for a screenshot when the report is about what they saw', () => {
    const r = assessReportSpecifics({ text: 'A member says the page looks wrong and the button is missing.' })
    expect(r.missing.join(' ')).toMatch(/screenshot/)
  })

  it('asks for no more than three things', () => {
    const r = assessReportSpecifics({
      text: 'Someone on a group says a post looks wrong and the button is missing from the page.',
    })
    expect(r.missing.length).toBeLessThanOrEqual(3)
  })

  it('reports which anchors it found', () => {
    const r = assessReportSpecifics({ text: 'See https://www.ilovefreegle.org/message/44120987', hasScreenshot: true })
    expect(r.anchors).toContain('link')
    expect(r.anchors).toContain('screenshot')
  })
})

describe('detailRequestBody', () => {
  it('asks for exactly what is missing', () => {
    const body = detailRequestBody(['which member this was, with their email address or a link to their profile', 'which group this was on'])
    expect(body).toContain('which member')
    expect(body).toContain('which group')
  })

  it('reads plainly enough to send', async () => {
    const body = detailRequestBody([
      'which member this was, with their email address or a link to their profile',
      'which group this was on',
      'a screenshot of what they saw',
    ])
    expect(await proseProblems(body)).toEqual([])
  })

  it('refuses to write an empty ask', () => {
    expect(() => detailRequestBody([])).toThrow()
  })
})
