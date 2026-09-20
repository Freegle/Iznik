/**
 * Tests for modtools/composables/useSystemLogFormatter.js — a pure formatting
 * layer that turns raw Loki/Laravel log rows into the human-readable text and
 * styling ModSystemLogEntry.vue displays. No DOM/Leaflet dependency, so this
 * suite drives the exported functions directly with fixture log objects.
 *
 * fetchSwaggerDocs()/prefetchSwaggerDocs()/formatLogTextAsync() share a
 * module-level cache (swaggerCache/swaggerFetchPromise), so those specific
 * tests reset the module and re-import it fresh (same pattern as
 * useClientLog.spec.js) to avoid cross-test cache leakage. Every other test
 * calls the synchronous formatLogText(log, swagger) with swagger passed
 * explicitly, which never touches that cache.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  formatLogText,
  getApiDescriptionWithEntities,
  getLogLevelClass,
  getLogSourceIcon,
  getLogSourceVariant,
  parseLaravelLogLevel,
  formatLogTimestamp,
  useSystemLogFormatter,
} from '~/composables/useSystemLogFormatter'

function mkLog(overrides = {}) {
  return {
    source: 'logs_table',
    type: 'User',
    subtype: 'Login',
    text: '',
    ...overrides,
  }
}

describe('formatLogText', () => {
  describe('unknown/fallback sources', () => {
    it('returns empty string for a null log', () => {
      expect(formatLogText(null)).toBe('')
    })

    it('falls back to log.text for an unrecognised source', () => {
      expect(formatLogText(mkLog({ source: 'mystery', text: 'hello' }))).toBe(
        'hello'
      )
    })

    it('falls back to raw.message when log.text is absent', () => {
      expect(
        formatLogText(
          mkLog({ source: 'mystery', text: '', raw: { message: 'from raw' } })
        )
      ).toBe('from raw')
    })

    it('falls back to raw.msg when raw.message is absent', () => {
      expect(
        formatLogText(
          mkLog({ source: 'mystery', text: '', raw: { msg: 'from msg' } })
        )
      ).toBe('from msg')
    })

    it('falls back to "source: type/subtype" when nothing else is present', () => {
      expect(
        formatLogText({ source: 'mystery', type: 'Foo', subtype: 'Bar' })
      ).toBe('mystery: Foo/Bar')
    })

    it('defaults source/type/subtype to unknown/default/default', () => {
      expect(formatLogText({})).toBe('unknown: default/default')
    })

    it('falls back past a known type with no matching subtype formatter', () => {
      expect(
        formatLogText(
          mkLog({ type: 'User', subtype: 'Nonexistent', text: 'fallback text' })
        )
      ).toBe('fallback text')
    })
  })

  describe('logs_table / User', () => {
    it.each([
      [{ subtype: 'Login', text: 'via password' }, 'Logged in via password'],
      [{ subtype: 'Login', text: '' }, 'Logged in'],
      [{ subtype: 'Logout' }, 'Logged out'],
      [{ subtype: 'Created' }, 'User created'],
      [
        { subtype: 'Bounce', text: 'mailbox full' },
        'Email bounced: mailbox full',
      ],
      [{ subtype: 'RoleChange', text: 'Admin' }, 'Role changed to Admin'],
      [
        { subtype: 'Merged', text: 'user #5' },
        'Merged with another user - user #5',
      ],
      [{ subtype: 'Approved' }, 'Member approved'],
      [{ subtype: 'Rejected' }, 'Member rejected'],
      [{ subtype: 'Mailed', text: 'welcome' }, 'Mod sent mail: welcome'],
      [{ subtype: 'Hold' }, 'Member held'],
      [{ subtype: 'Release' }, 'Member released'],
      [{ subtype: 'Suspect', text: 'spammy' }, 'Flagged: spammy'],
      [{ subtype: 'Split', text: 'user #9' }, 'Split into two users - user #9'],
      [{ subtype: 'MailOff' }, 'Turned digests off by email'],
      [{ subtype: 'EventsOff' }, 'Turned events off by email'],
      [{ subtype: 'NewslettersOff' }, 'Turned newsletters off by email'],
      [{ subtype: 'RelevantOff' }, 'Turned off "interested in" mails by email'],
      [{ subtype: 'VolunteersOff' }, 'Turned off volunteering mails by email'],
      [{ subtype: 'SuspendMail' }, 'Stop mailing - bouncing'],
      [{ subtype: 'Unbounce' }, 'Reactivated mail'],
      [
        { subtype: 'PostcodeChange', text: 'SW1A 1AA' },
        'Postcode set to SW1A 1AA',
      ],
      [
        { subtype: 'OurEmailFrequency', text: 'Daily' },
        'Set Email Frequency to Daily',
      ],
    ])('%o -> %s', (overrides, expected) => {
      expect(formatLogText(mkLog(overrides))).toBe(expected)
    })

    it('Deleted by another user reports a removal', () => {
      expect(
        formatLogText(
          mkLog({
            subtype: 'Deleted',
            user_id: 1,
            byuser_id: 2,
            text: 'ignored',
          })
        )
      ).toBe('Member removed')
    })

    it('Deleted by self reports leaving the platform', () => {
      expect(
        formatLogText(
          mkLog({ subtype: 'Deleted', user_id: 1, byuser_id: 1, text: 'bye' })
        )
      ).toBe('User left platform (bye)')
    })

    it('Deleted with no byuser_id at all reports leaving the platform', () => {
      expect(formatLogText(mkLog({ subtype: 'Deleted', text: 'bye' }))).toBe(
        'User left platform (bye)'
      )
    })

    it.each([
      ['UNCHANGED', 'Unchanged'],
      ['MODERATED', 'Moderated'],
      ['DEFAULT', 'Group Settings'],
      ['PROHIBITED', "Can't Post"],
      ['SOMETHING_ELSE', 'SOMETHING_ELSE'],
      ['', ''],
    ])('OurPostingStatus %s -> %s', (status, mapped) => {
      expect(
        formatLogText(mkLog({ subtype: 'OurPostingStatus', text: status }))
      ).toBe(`Set Posting Status to ${mapped}`)
    })
  })

  describe('logs_table / Message', () => {
    it.each([
      ['Offer', 'sofa', 'Posted Offer: sofa'],
      ['Wanted', 'drill', 'Posted Wanted: drill'],
      ['Other', 'x', 'Received message'],
      [undefined, undefined, 'Received message'],
    ])('Received (type=%s) -> %s', (msgType, subject, expected) => {
      expect(
        formatLogText(
          mkLog({
            type: 'Message',
            subtype: 'Received',
            raw: { message: { type: msgType, subject } },
          })
        )
      ).toBe(expected)
    })

    it('Received with no raw payload falls back to a generic message', () => {
      expect(
        formatLogText(mkLog({ type: 'Message', subtype: 'Received' }))
      ).toBe('Received message')
    })

    it.each([
      [{ subtype: 'Approved' }, 'Message approved'],
      [{ subtype: 'Rejected' }, 'Message rejected'],
      [{ subtype: 'Deleted' }, 'Message deleted'],
      [{ subtype: 'Hold' }, 'Message held'],
      [{ subtype: 'Release' }, 'Message released'],
      [
        { subtype: 'Edit', text: 'title change' },
        'Edited message: title change',
      ],
      [{ subtype: 'Autoapproved' }, 'Auto-approved'],
      [{ subtype: 'Autoreposted', text: '2' }, 'Autoreposted (repost 2)'],
      [{ subtype: 'Repost' }, 'Manual repost'],
      [{ subtype: 'ClassifiedSpam' }, 'Sent spam'],
      [{ subtype: 'Replied', text: 'thanks' }, 'Modmail sent: thanks'],
      [{ subtype: 'WorryWords', text: 'knife' }, 'Flagged: knife'],
    ])('%o -> %s', (overrides, expected) => {
      expect(formatLogText(mkLog({ type: 'Message', ...overrides }))).toBe(
        expected
      )
    })

    it.each([
      ['Taken', 'Marked as TAKEN (item collected)'],
      ['Received', 'Marked as RECEIVED (item obtained)'],
      ['Withdrawn', 'Marked as WITHDRAWN (no longer available)'],
      ['Repost', 'Reposted'],
      ['Something', 'Marked outcome: Something'],
      ['', 'Marked outcome: '],
    ])('Outcome %s -> %s', (outcome, expected) => {
      expect(
        formatLogText(
          mkLog({ type: 'Message', subtype: 'Outcome', text: outcome })
        )
      ).toBe(expected)
    })
  })

  describe('logs_table / Group', () => {
    it.each([
      ['Manual', 'Joined (clicked Join button)'],
      ['Rippled', 'Joined (auto-joined - post rippled into this group)'],
      ['SomeOtherReason', 'Joined (auto-joined when posting/replying)'],
      ['', 'Joined'],
      [undefined, 'Joined'],
    ])('Joined (text=%s) -> %s', (text, expected) => {
      expect(
        formatLogText(mkLog({ type: 'Group', subtype: 'Joined', text }))
      ).toBe(expected)
    })

    it('Left by another user reports a removal', () => {
      expect(
        formatLogText(
          mkLog({ type: 'Group', subtype: 'Left', user_id: 1, byuser_id: 2 })
        )
      ).toBe('Member removed')
    })

    it('Left by self reports leaving the group', () => {
      expect(
        formatLogText(
          mkLog({ type: 'Group', subtype: 'Left', user_id: 1, byuser_id: 1 })
        )
      ).toBe('Left group')
    })

    it.each([
      [{ subtype: 'Applied' }, 'Applied to join'],
      [{ subtype: 'Edit' }, 'Edited group settings'],
      [{ subtype: 'Autoapproved' }, 'Auto-approved membership'],
    ])('%o -> %s', (overrides, expected) => {
      expect(formatLogText(mkLog({ type: 'Group', ...overrides }))).toBe(
        expected
      )
    })
  })

  describe('logs_table / Config, StdMsg, Chat', () => {
    it.each([
      ['Created', 'text-value', undefined, 'Created config text-value'],
      ['Deleted', 'text-value', undefined, 'Deleted config text-value'],
      [
        'Edit',
        'fallback-text',
        { config: { name: 'from-raw' } },
        'Edited config from-raw',
      ],
      ['Edit', 'fallback-text', undefined, 'Edited config fallback-text'],
    ])('Config %s -> %s', (subtype, text, raw, expected) => {
      expect(formatLogText(mkLog({ type: 'Config', subtype, text, raw }))).toBe(
        expected
      )
    })

    it.each([
      ['Created', 'std-value', undefined, 'Created standard message std-value'],
      ['Deleted', 'std-value', undefined, 'Deleted standard message std-value'],
      [
        'Edit',
        'fallback',
        { stdmsg: { name: 'from-raw' } },
        'Edited standard message from-raw',
      ],
      ['Edit', 'fallback', undefined, 'Edited standard message fallback'],
    ])('StdMsg %s -> %s', (subtype, text, raw, expected) => {
      expect(formatLogText(mkLog({ type: 'StdMsg', subtype, text, raw }))).toBe(
        expected
      )
    })

    it('Chat Approved reports an approval', () => {
      expect(formatLogText(mkLog({ type: 'Chat', subtype: 'Approved' }))).toBe(
        'Approved chat message'
      )
    })
  })

  describe('api source', () => {
    it('prefers a swagger description when one matches', () => {
      const swagger = {
        paths: { '/message/{id}': { get: { summary: 'Fetch a post' } } },
      }
      expect(
        formatLogText(
          {
            source: 'api',
            raw: {
              method: 'GET',
              endpoint: '/apiv2/message/42',
              status_code: 200,
            },
          },
          swagger
        )
      ).toBe('Fetch a post')
    })

    it('falls back to the hardcoded description when swagger has no match', () => {
      expect(
        formatLogText({
          source: 'api',
          raw: {
            method: 'GET',
            endpoint: '/apiv2/message/42',
            status_code: 200,
          },
        })
      ).toBe('Get post #42')
    })

    it('falls back to a generated description for an unknown endpoint', () => {
      expect(
        formatLogText({
          source: 'api',
          raw: { method: 'GET', endpoint: '/apiv2/widget/7', status_code: 200 },
        })
      ).toBe('Get widget #7')
    })

    it('appends the status code for a 4xx/5xx response', () => {
      expect(
        formatLogText({
          source: 'api',
          raw: {
            method: 'GET',
            endpoint: '/apiv2/message/42',
            status_code: 404,
          },
        })
      ).toBe('Get post #42 [404]')
    })

    it('defaults the method/endpoint/status when raw is missing', () => {
      // endpoint defaults to '/', which generateHumanReadableEndpoint splits into
      // an empty resource name — an accepted quirk of the fallback path.
      expect(formatLogText({ source: 'api', raw: {} })).toBe('List ')
    })
  })

  describe('client source', () => {
    it.each([
      ['session_start', 'Opened Freegle'],
      ['session_resume', 'Returned to Freegle'],
      ['page_hidden', 'Left tab'],
      ['page_visible', 'Came back to tab'],
    ])('%s -> %s', (type, expected) => {
      expect(formatLogText({ source: 'client', type })).toBe(expected)
    })

    it('page_view formats a known route', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'page_view',
          raw: { page_name: '/give' },
        })
      ).toBe('Opened Give page')
    })

    it('outcome_intended reports the intended outcome', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'outcome_intended',
          raw: { outcome: 'Taken' },
        })
      ).toBe('Intending to mark as Taken')
    })

    it('outcome reports the recorded outcome', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'outcome',
          raw: { outcome: 'Received' },
        })
      ).toBe('Marked as Received')
    })

    describe('action', () => {
      it('defers to a matching client formatter for the action name', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'action',
            raw: { action_name: 'session_start' },
          })
        ).toBe('Opened Freegle')
      })

      it('uses actionLabels when there is no dedicated formatter', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'action',
            raw: { action_name: 'page_view' },
          })
        ).toBe('Viewed page')
      })

      it('humanises an unrecognised snake_case action name', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'action',
            raw: { action_name: 'did_a_thing' },
          })
        ).toBe('Did a thing')
      })

      it('strips an "Action: " prefix from the fallback message', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'action',
            raw: { message: 'Action: clicked something' },
          })
        ).toBe('clicked something')
      })

      it('relabels specific known messages', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'action',
            raw: { message: 'Ad impression' },
          })
        ).toBe('Job Ad impression')
      })

      it('falls back to log.text, then "User action"', () => {
        expect(
          formatLogText({ source: 'client', type: 'action', text: 'from text' })
        ).toBe('from text')
        expect(formatLogText({ source: 'client', type: 'action' })).toBe(
          'User action'
        )
      })
    })

    describe('interaction', () => {
      it.each([
        ['click', 'Button', 'Click: Button'],
        ['click', '', 'Click'],
        ['dblclick', 'Card', 'Double-click: Card'],
        ['dblclick', '', 'Double-click'],
        ['rightclick', 'Menu', 'Right-click: Menu'],
        ['rightclick', '', 'Right-click'],
        ['swipe', '', 'Swipe'],
        ['change', 'Field', 'Change: Field'],
        ['change', '', 'Form change'],
        ['submit', 'Form', 'Submit: Form'],
        ['submit', '', 'Form submit'],
        ['mystery-type', 'label text', 'label text'],
      ])(
        'interactionType=%s label=%s -> %s',
        (interactionType, label, expected) => {
          expect(
            formatLogText({
              source: 'client',
              type: 'interaction',
              raw: { interaction_type: interactionType, action_name: label },
            })
          ).toBe(expected)
        }
      )

      it('scroll includes the percentage when present', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'interaction',
            raw: { interaction_type: 'scroll', scroll_percent: 50 },
          })
        ).toBe('Scroll: 50%')
      })

      it('scroll with no percentage just says Scroll', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'interaction',
            raw: { interaction_type: 'scroll' },
          })
        ).toBe('Scroll')
      })

      it('swipe includes the direction when present', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'interaction',
            raw: { interaction_type: 'swipe', direction: 'left' },
          })
        ).toBe('Swipe left')
      })

      it('defaults interaction_type to "action" when absent', () => {
        expect(
          formatLogText({ source: 'client', type: 'interaction', raw: {} })
        ).toBe('action')
      })

      it('strips a leading "label: " prefix from the action name', () => {
        expect(
          formatLogText({
            source: 'client',
            type: 'interaction',
            raw: { interaction_type: 'click', action_name: 'Toolbar: Save' },
          })
        ).toBe('Click: Save')
      })
    })

    it.each([
      ['click', { action_name: 'Save button' }, 'Clicked Save button'],
      ['click', {}, 'Clicked button'],
      ['scroll', {}, 'Scrolled'],
      ['focus', {}, 'Started typing'],
      ['blur', {}, 'Stopped typing'],
    ])('%s -> %s', (type, raw, expected) => {
      expect(formatLogText({ source: 'client', type, raw })).toBe(expected)
    })

    it('api_call reports the method/path/duration', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'api_call',
          raw: { method: 'POST', path: '/apiv2/message', duration_ms: 123.6 },
        })
      ).toBe('API: POST /apiv2/message (124ms)')
    })

    it('api_call omits the duration when absent', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'api_call',
          raw: { method: 'GET', path: '/apiv2/message' },
        })
      ).toBe('API: GET /apiv2/message')
    })

    it('error reports the raw message, falling back to log.text then "Unknown error"', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'error',
          raw: { message: 'boom' },
        })
      ).toBe('Error: boom')
      expect(
        formatLogText({ source: 'client', type: 'error', text: 'boom2' })
      ).toBe('Error: boom2')
      expect(formatLogText({ source: 'client', type: 'error' })).toBe(
        'Error: Unknown error'
      )
    })

    it('routes an event_type that looks like a path through formatPageName', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'mystery',
          raw: { event_type: '/find' },
        })
      ).toBe('Opened Find page')
    })

    it('default handler humanises a snake_case event_type', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'totally_unknown',
          raw: { event_type: 'some_custom_event' },
        })
      ).toBe('Some custom event')
    })

    it('default handler falls back to raw.message then log.text then "Action"', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'totally_unknown',
          raw: { message: 'raw says hi' },
        })
      ).toBe('raw says hi')
      expect(
        formatLogText({
          source: 'client',
          type: 'totally_unknown',
          text: 'text says hi',
        })
      ).toBe('text says hi')
      expect(formatLogText({ source: 'client', type: 'totally_unknown' })).toBe(
        'Action'
      )
    })
  })

  describe('page_view route names', () => {
    it.each([
      ['/', 'Opened home page'],
      [undefined, 'Opened a page'],
      ['/give', 'Opened Give page'],
      ['/ask', 'Opened Ask page'],
      // Logs written before the Aug 2026 rename still say /find.
      ['/find', 'Opened Find page'],
      ['/myposts', 'Opened My Posts'],
      ['/message/123', 'Opened message #123'],
      ['/message/abc', 'Opened message'],
      ['/somethingnew', 'Opened Somethingnew page'],
    ])('%s -> %s', (pageName, expected) => {
      expect(
        formatLogText({
          source: 'client',
          type: 'page_view',
          raw: { page_name: pageName },
        })
      ).toBe(expected)
    })

    it('falls back to raw.url when page_name is absent', () => {
      expect(
        formatLogText({
          source: 'client',
          type: 'page_view',
          raw: { url: '/browse' },
        })
      ).toBe('Opened Browse page')
    })
  })

  describe('other sources', () => {
    it('email reports the type/subject/user', () => {
      expect(
        formatLogText({
          source: 'email',
          raw: { email_type: 'welcome', subject: 'Hi there', user_id: 9 },
        })
      ).toBe('Sent welcome to user #9: Hi there')
    })

    it('email omits the user part when no user id is present', () => {
      expect(
        formatLogText({ source: 'email', raw: { email_type: 'welcome' } })
      ).toBe('Sent welcome: ')
    })

    it('batch reports raw.message, falling back to log.text then "Batch job"', () => {
      expect(
        formatLogText({ source: 'batch', raw: { message: 'ran ok' } })
      ).toBe('ran ok')
      expect(formatLogText({ source: 'batch', text: 'from text' })).toBe(
        'from text'
      )
      expect(formatLogText({ source: 'batch' })).toBe('Batch job')
    })

    it('batch_event reports raw.message, falling back to "Batch event"', () => {
      expect(formatLogText({ source: 'batch_event' })).toBe('Batch event')
    })

    it('api_headers reports the endpoint, falling back to a generic label', () => {
      expect(
        formatLogText({
          source: 'api_headers',
          raw: { method: 'GET', endpoint: '/apiv2/message' },
        })
      ).toBe('Headers for GET /apiv2/message')
      expect(formatLogText({ source: 'api_headers', raw: {} })).toBe(
        'API request headers'
      )
    })

    it('laravel-batch strips the level prefix and trailing JSON', () => {
      expect(
        formatLogText({
          source: 'laravel-batch',
          raw: {
            message:
              '[2025-12-24 09:04:03] production.INFO: Welcome mail sent {"user_id":5}',
          },
        })
      ).toBe('Welcome mail sent')
    })

    it('laravel-batch with no bracketed level falls back to the raw message', () => {
      expect(
        formatLogText({
          source: 'laravel-batch',
          raw: { message: 'plain message, no level prefix' },
        })
      ).toBe('plain message, no level prefix')
    })

    it('laravel-batch with nothing at all falls back to "Batch job"', () => {
      expect(formatLogText({ source: 'laravel-batch', raw: {} })).toBe(
        'Batch job'
      )
    })
  })
})

describe('getApiDescriptionWithEntities', () => {
  it('returns null for a non-api log', () => {
    expect(getApiDescriptionWithEntities({ source: 'client' })).toBeNull()
  })

  it('extracts a userid from a matched /user/:id pattern', () => {
    const result = getApiDescriptionWithEntities({
      source: 'api',
      raw: { method: 'GET', endpoint: '/apiv2/user/42' },
    })
    expect(result.text).toBe('Get user #42')
    expect(result.entities.userid).toBe('42')
  })

  it('extracts a groupid from a matched /group/:id pattern', () => {
    const result = getApiDescriptionWithEntities({
      source: 'api',
      raw: { method: 'GET', endpoint: '/apiv2/group/7' },
    })
    expect(result.text).toBe('Get group #7')
    expect(result.entities.groupid).toBe('7')
  })

  it('extracts a msgid from a matched /message/:id pattern', () => {
    const result = getApiDescriptionWithEntities({
      source: 'api',
      raw: { method: 'GET', endpoint: '/apiv2/message/99' },
    })
    expect(result.text).toBe('Get post #99')
    expect(result.entities.msgid).toBe('99')
  })

  it('extracts a chatid from a matched /chat/rooms/:id pattern', () => {
    const result = getApiDescriptionWithEntities({
      source: 'api',
      raw: { method: 'GET', endpoint: '/api/chat/rooms/3' },
    })
    expect(result.text).toBe('Get chat room #3')
    expect(result.entities.chatid).toBe('3')
  })

  it('matches an exact (parameterless) endpoint with no entities', () => {
    const result = getApiDescriptionWithEntities({
      source: 'api',
      raw: { method: 'GET', endpoint: '/apiv2/user' },
    })
    expect(result.text).toBe('Get current user')
    expect(result.entities).toEqual({})
  })

  it('returns a null text and empty entities for an unmatched endpoint', () => {
    const result = getApiDescriptionWithEntities({
      source: 'api',
      raw: { method: 'GET', endpoint: '/apiv2/totally/unknown/path' },
    })
    expect(result.text).toBeNull()
    expect(result.entities).toEqual({})
  })

  it('defaults method to GET and endpoint to / when raw is empty', () => {
    const result = getApiDescriptionWithEntities({ source: 'api', raw: {} })
    expect(result.text).toBeNull()
  })
})

describe('getLogLevelClass', () => {
  describe('api source', () => {
    it('flags a 5xx status as an error', () => {
      expect(
        getLogLevelClass({ source: 'api', raw: { status_code: 500 } })
      ).toBe('text-danger')
    })

    it('flags a v1 ret!=0 response as an error', () => {
      expect(
        getLogLevelClass({
          source: 'api',
          raw: { status_code: 200, ret: 1, endpoint: '/api/message' },
        })
      ).toBe('text-danger')
    })

    it('does not flag ret=1 on a session/auth-check endpoint (normal "not logged in")', () => {
      expect(
        getLogLevelClass({
          source: 'api',
          raw: { status_code: 200, ret: 1, endpoint: '/api/session' },
        })
      ).toBe('')
      expect(
        getLogLevelClass({
          source: 'api',
          raw: { status_code: 200, ret: 1, endpoint: '/api/user' },
        })
      ).toBe('')
    })

    it('does not flag a plain 4xx as an error', () => {
      expect(
        getLogLevelClass({ source: 'api', raw: { status_code: 404, ret: 0 } })
      ).toBe('')
    })

    it('reads ret from response_body when top-level ret is absent', () => {
      expect(
        getLogLevelClass({
          source: 'api',
          raw: {
            status_code: 200,
            response_body: { ret: 2 },
            endpoint: '/api/message',
          },
        })
      ).toBe('text-danger')
    })
  })

  describe('laravel-batch source', () => {
    it.each([
      ['ERROR', 'text-danger'],
      ['CRITICAL', 'text-danger'],
      ['ALERT', 'text-danger'],
      ['EMERGENCY', 'text-danger'],
      ['WARNING', 'text-warning'],
      ['DEBUG', 'text-muted'],
      ['INFO', ''],
      ['NOTICE', ''],
    ])('%s -> %s', (level, expected) => {
      expect(
        getLogLevelClass({
          source: 'laravel-batch',
          raw: {
            message: `[2025-12-24 09:04:03] production.${level}: message body`,
          },
        })
      ).toBe(expected)
    })

    it('returns "" when the message does not match the Laravel format', () => {
      expect(
        getLogLevelClass({
          source: 'laravel-batch',
          raw: { message: 'no level here' },
        })
      ).toBe('')
    })
  })

  describe('generic level field', () => {
    it.each([
      ['error', 'text-danger'],
      ['warn', 'text-warning'],
      ['debug', 'text-muted'],
      ['info', ''],
      [undefined, ''],
    ])('%s -> %s', (level, expected) => {
      expect(getLogLevelClass({ source: 'client', level })).toBe(expected)
    })
  })
})

describe('getLogSourceIcon', () => {
  it.each([
    ['api', 'server'],
    ['logs_table', 'list'],
    ['client', 'desktop'],
    ['email', 'envelope'],
    ['batch', 'clock'],
    ['batch_event', 'bolt'],
    ['unknown_source', 'file-alt'],
  ])('%s -> %s', (source, expected) => {
    expect(getLogSourceIcon(source)).toBe(expected)
  })
})

describe('getLogSourceVariant', () => {
  it.each([
    ['api', 'info'],
    ['api_headers', 'light'],
    ['logs_table', 'secondary'],
    ['client', 'primary'],
    ['email', 'success'],
    ['batch', 'dark'],
    ['batch_event', 'warning'],
    ['laravel-batch', 'success'],
    ['unknown_source', 'light'],
  ])('%s -> %s', (source, expected) => {
    expect(getLogSourceVariant(source)).toBe(expected)
  })
})

describe('parseLaravelLogLevel', () => {
  it('returns null for a non-laravel-batch source', () => {
    expect(parseLaravelLogLevel({ source: 'batch', raw: {} })).toBeNull()
  })

  it('returns null when the message does not match the Laravel format', () => {
    expect(
      parseLaravelLogLevel({
        source: 'laravel-batch',
        raw: { message: 'no level' },
      })
    ).toBeNull()
  })

  it.each([
    ['DEBUG', 'secondary'],
    ['INFO', 'info'],
    ['NOTICE', 'info'],
    ['WARNING', 'warning'],
    ['ERROR', 'danger'],
    ['CRITICAL', 'danger'],
    ['ALERT', 'danger'],
    ['EMERGENCY', 'danger'],
  ])('%s -> variant %s', (level, variant) => {
    expect(
      parseLaravelLogLevel({
        source: 'laravel-batch',
        raw: { message: `[2025-12-24 09:04:03] production.${level}: body` },
      })
    ).toEqual({ level, variant })
  })

  it('falls back to secondary for an unrecognised level word', () => {
    expect(
      parseLaravelLogLevel({
        source: 'laravel-batch',
        raw: { message: '[2025-12-24 09:04:03] production.WEIRD: body' },
      })
    ).toEqual({ level: 'WEIRD', variant: 'secondary' })
  })

  it('reads the message from log.text when raw.message is absent', () => {
    expect(
      parseLaravelLogLevel({
        source: 'laravel-batch',
        text: '[2025-12-24 09:04:03] production.ERROR: body',
      })
    ).toEqual({ level: 'ERROR', variant: 'danger' })
  })
})

describe('formatLogTimestamp', () => {
  it('returns an empty string for a falsy timestamp', () => {
    expect(formatLogTimestamp(null)).toBe('')
    expect(formatLogTimestamp(undefined)).toBe('')
    expect(formatLogTimestamp('')).toBe('')
  })

  it('formats "short" as time.ms', () => {
    const result = formatLogTimestamp('2025-12-24T09:04:03.123Z', 'short')
    expect(result).toMatch(/^\d{2}:\d{2}:\d{2}\.\d{3}$/)
  })

  it('defaults to the "short" format', () => {
    const withDefault = formatLogTimestamp('2025-12-24T09:04:03.123Z')
    const withShort = formatLogTimestamp('2025-12-24T09:04:03.123Z', 'short')
    expect(withDefault).toBe(withShort)
  })

  it('formats "medium" as a short date + time.ms', () => {
    const result = formatLogTimestamp('2025-12-24T09:04:03.123Z', 'medium')
    expect(result).toMatch(/^\d{1,2} \w+, \d{2}:\d{2}:\d{2}\.\d{3}$/)
  })

  it('formats "full" as a full date + time with no milliseconds', () => {
    const result = formatLogTimestamp('2025-12-24T09:04:03.123Z', 'full')
    expect(result).toMatch(/^\d{2}\/\d{2}\/\d{4}, \d{2}:\d{2}:\d{2}$/)
    expect(result).not.toContain('.123')
  })

  it('falls back to toLocaleString for an unrecognised format', () => {
    const result = formatLogTimestamp('2025-12-24T09:04:03.123Z', 'bogus')
    const date = new Date('2025-12-24T09:04:03.123Z')
    expect(result).toBe(date.toLocaleString('en-GB'))
  })
})

describe('useSystemLogFormatter', () => {
  it('returns the full set of formatter functions', () => {
    const api = useSystemLogFormatter()
    expect(api.formatLogText).toBe(formatLogText)
    expect(api.getLogLevelClass).toBe(getLogLevelClass)
    expect(api.getLogSourceIcon).toBe(getLogSourceIcon)
    expect(api.getLogSourceVariant).toBe(getLogSourceVariant)
    expect(api.formatLogTimestamp).toBe(formatLogTimestamp)
  })
})

describe('swagger fetching (prefetchSwaggerDocs / formatLogTextAsync)', () => {
  let mod

  beforeEach(async () => {
    vi.resetModules()
    mod = await import('~/composables/useSystemLogFormatter')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('fetches swagger docs and uses them to describe an API log', async () => {
    const swagger = {
      paths: { '/message/{id}': { get: { summary: 'Fetch a post' } } },
    }
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve(swagger),
    })

    const text = await mod.formatLogTextAsync({
      source: 'api',
      raw: { method: 'GET', endpoint: '/apiv2/message/42', status_code: 200 },
    })

    expect(text).toBe('Fetch a post')
    expect(global.fetch).toHaveBeenCalledTimes(1)
  })

  it('caches the swagger response so a second call does not refetch', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ paths: {} }),
    })

    await mod.prefetchSwaggerDocs()
    await mod.prefetchSwaggerDocs()

    expect(global.fetch).toHaveBeenCalledTimes(1)
  })

  it('dedupes concurrent in-flight fetches into a single request', async () => {
    let resolveFetch
    global.fetch = vi.fn(
      () =>
        new Promise((resolve) => {
          resolveFetch = resolve
        })
    )

    const p1 = mod.prefetchSwaggerDocs()
    const p2 = mod.prefetchSwaggerDocs()
    resolveFetch({ ok: true, json: () => Promise.resolve({ paths: {} }) })
    await Promise.all([p1, p2])

    expect(global.fetch).toHaveBeenCalledTimes(1)
  })

  it('returns null and does not cache when the response is not ok', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: false, status: 500 })
    vi.spyOn(console, 'warn').mockImplementation(() => {})

    const result = await mod.prefetchSwaggerDocs()

    expect(result).toBeNull()
  })

  it('returns null when fetch throws', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('network down'))
    vi.spyOn(console, 'warn').mockImplementation(() => {})

    const result = await mod.prefetchSwaggerDocs()

    expect(result).toBeNull()
  })

  it('builds the swagger URL from a relative (non-http) API base', async () => {
    const originalConfig = globalThis.useRuntimeConfig
    globalThis.useRuntimeConfig = () => ({ public: { APIv2: '/apiv2' } })
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ paths: {} }),
    })

    try {
      await mod.prefetchSwaggerDocs()
    } finally {
      globalThis.useRuntimeConfig = originalConfig
    }

    expect(global.fetch).toHaveBeenCalledWith('/swagger/swagger.json')
  })

  it('falls back to /apiv2 when useRuntimeConfig is unavailable', async () => {
    const originalConfig = globalThis.useRuntimeConfig
    delete globalThis.useRuntimeConfig
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ paths: {} }),
    })

    try {
      await mod.prefetchSwaggerDocs()
    } finally {
      globalThis.useRuntimeConfig = originalConfig
    }

    expect(global.fetch).toHaveBeenCalledWith('/swagger/swagger.json')
  })
})
