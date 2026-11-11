import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'

const mocks = vi.hoisted(() => ({
  onRoute: vi.fn(),
  noteHelpSought: vi.fn(),
  afterEach: null,
}))

vi.mock('~/composables/useFlowRoutes', () => ({
  createFlowRouteTracker: () => ({ onRoute: mocks.onRoute }),
  draftAgeFromStore: vi.fn(),
}))
vi.mock('~/composables/useFlowTelemetry', () => ({
  noteHelpSought: mocks.noteHelpSought,
}))
vi.mock('~/composables/useClientLog', () => ({
  getRecentClickComponent: vi.fn(),
}))
vi.mock('~/stores/compose', () => ({ useComposeStore: () => ({}) }))

describe('plugins/flowTelemetry.client', () => {
  // Once: the plugin adds a document listener, which would otherwise pile up per test.
  beforeAll(async () => {
    globalThis.__testUseRouter = () => ({
      afterEach: (cb) => {
        mocks.afterEach = cb
      },
    })
    const mod = await import('~/plugins/flowTelemetry.client.js')
    mod.default()
  })

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('hands every successful navigation to the route tracker', () => {
    mocks.afterEach({ name: 'give' }, { name: 'index' }, undefined)
    expect(mocks.onRoute).toHaveBeenCalledWith(
      { name: 'give' },
      { name: 'index' }
    )
  })

  it('ignores a failed navigation', () => {
    mocks.afterEach({ name: 'give' }, { name: 'index' }, new Error('x'))
    expect(mocks.onRoute).not.toHaveBeenCalled()
  })

  it('records help sought when the help page is viewed', () => {
    mocks.afterEach({ name: 'help' }, { name: 'index' }, undefined)
    expect(mocks.noteHelpSought).toHaveBeenCalledTimes(1)
  })

  it('does not record help sought for other pages', () => {
    mocks.afterEach({ name: 'browse' }, { name: 'index' }, undefined)
    expect(mocks.noteHelpSought).not.toHaveBeenCalled()
  })

  it('records help sought when the contact support modal opens', () => {
    const el = document.createElement('div')
    el.className = 'modal contact-support-modal'
    document.body.appendChild(el)
    el.dispatchEvent(new Event('show.bs.modal', { bubbles: true }))
    expect(mocks.noteHelpSought).toHaveBeenCalledTimes(1)
    el.remove()
  })

  it('ignores other modals opening', () => {
    const el = document.createElement('div')
    el.className = 'modal other'
    document.body.appendChild(el)
    el.dispatchEvent(new Event('show.bs.modal', { bubbles: true }))
    expect(mocks.noteHelpSought).not.toHaveBeenCalled()
    el.remove()
  })
})
