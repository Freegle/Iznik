import { describe, it, expect, vi, beforeEach } from 'vitest'
import { effectScope } from 'vue'

import {
  startSettingsFlow,
  beginSettingsSave,
  changedFields,
  noteSettingsSave,
} from '~/composables/useSettingsTelemetry'
import {
  FlowName,
  useFlowTelemetry,
  _resetFlowTelemetryForTests,
} from '~/composables/useFlowTelemetry'

const log = vi.hoisted(() => ({ action: vi.fn(), flush: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: log.flush,
}))
vi.mock('~/composables/useExperiment', () => ({ experimentVariant: () => '' }))

const events = () =>
  log.action.mock.calls.map(([name, ctx]) => ({ name, ...ctx }))
const names = () => events().map((e) => e.name)

describe('changedFields', () => {
  const user = {
    displayname: 'Sam Example',
    settings: { notificationmails: true, simplemail: 'Full', modmails: 1 },
  }

  it('names the settings that differ, with a kind', () => {
    expect(
      changedFields(user, {
        settings: {
          notificationmails: false,
          simplemail: 'Basic',
          modmails: 1,
        },
      })
    ).toEqual([
      { field: 'notificationmails', kind: 'toggled' },
      { field: 'simplemail', kind: 'replaced' },
    ])
  })

  it('names a top level param that differs', () => {
    expect(changedFields(user, { displayname: 'Other' })).toEqual([
      { field: 'displayname', kind: 'replaced' },
    ])
  })

  it('counts a setting new to the user as replaced', () => {
    expect(
      changedFields(user, { settings: { ...user.settings, newone: 1 } })
    ).toEqual([{ field: 'newone', kind: 'replaced' }])
  })

  it('ignores unchanged values and a missing user', () => {
    expect(
      changedFields(user, {
        displayname: 'Sam Example',
        settings: user.settings,
      })
    ).toEqual([])
    expect(changedFields(null, { displayname: 'x' })).toEqual([
      { field: 'displayname', kind: 'replaced' },
    ])
  })

  it('never contains a value', () => {
    const out = JSON.stringify(
      changedFields(user, {
        displayname: 'Secret Name',
        settings: { simplemail: 'Basic' },
      })
    )
    expect(out).not.toContain('Secret')
    expect(out).not.toContain('Basic')
  })
})

describe('settings flow', () => {
  let scope

  // What the settings save does: begin (first save starts the flow), submit, result, note.
  const save = (fields, ok = true) => {
    beginSettingsSave()
    const flow = useFlowTelemetry(FlowName.SETTINGS)
    flow.submit()
    flow.submitResult(ok, ok ? 200 : 500, ok ? null : 'error')
    if (ok) noteSettingsSave(fields)
  }

  beforeEach(() => {
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    scope = effectScope()
    scope.run(() => startSettingsFlow())
  })

  it('sends nothing when the page opens', () => {
    expect(names()).toEqual([])
  })

  it('sends nothing at all for a visit that only looks', () => {
    scope.stop()
    expect(names()).toEqual([])
  })

  it('starts on the settings step at the first save', () => {
    save([{ field: 'simplemail', kind: 'replaced' }])
    expect(names().slice(0, 2)).toEqual(['flow_start', 'step_enter'])
    expect(events()[0]).toMatchObject({ flow: 'settings', entry: 'page' })
    expect(events()[1].step).toBe('settings')
    expect(names()).not.toContain('field_revisit')
  })

  it('starts once per visit however many saves there are', () => {
    save([{ field: 'a', kind: 'replaced' }])
    save([{ field: 'b', kind: 'replaced' }])
    expect(names().filter((n) => n === 'flow_start')).toHaveLength(1)
  })

  it('reports a setting saved again in the same visit, by name and kind', () => {
    save([{ field: 'notificationmails', kind: 'toggled' }])
    save([{ field: 'notificationmails', kind: 'toggled' }])
    expect(events().find((e) => e.name === 'field_revisit')).toMatchObject({
      field: 'notificationmails',
      change_kind: 'toggled',
    })
  })

  it('does not report different settings each saved once', () => {
    save([{ field: 'a', kind: 'replaced' }])
    save([{ field: 'b', kind: 'replaced' }])
    expect(names()).not.toContain('field_revisit')
  })

  it('starts counting afresh on the next visit', () => {
    save([{ field: 'a', kind: 'replaced' }])
    scope.stop()
    effectScope().run(() => startSettingsFlow())
    log.action.mockClear()
    save([{ field: 'a', kind: 'replaced' }])
    expect(names()).not.toContain('field_revisit')
  })

  it('does not start a flow for a save made with the settings page closed', () => {
    scope.stop()
    log.action.mockClear()
    save([{ field: 'a', kind: 'replaced' }])
    expect(names()).not.toContain('flow_start')
  })

  it('completes the settings step and the flow when the page is left after a successful save', () => {
    save([{ field: 'a', kind: 'replaced' }])
    log.action.mockClear()
    scope.stop()
    expect(names()).toEqual(['step_complete', 'flow_complete'])
  })

  it('abandons when every save in the visit failed', () => {
    save([{ field: 'a', kind: 'replaced' }], false)
    log.action.mockClear()
    scope.stop()
    expect(events().map((e) => [e.name, e.reason])).toEqual([
      ['flow_abandon', 'navigated'],
    ])
  })
})
