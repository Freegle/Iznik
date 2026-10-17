import { describe, it, expect, vi, beforeEach } from 'vitest'
import {
  ownPhotoCount,
  givePhotoCount,
  reportGivePosted,
} from '~/composables/useGivePhotoTelemetry'

const log = vi.hoisted(() => ({ action: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({ action: log.action }))
const variant = vi.hoisted(() => ({ value: 'give-photos-carry-on:b' }))
vi.mock('~/composables/useExperiment', () => ({
  experimentVariant: () => variant.value,
}))

describe('ownPhotoCount', () => {
  it('counts photos with an id, an ouruid or an externaluid', () => {
    expect(
      ownPhotoCount({
        attachments: [{ id: 5 }, { ouruid: 'u1' }, { externaluid: 'e1' }],
      })
    ).toBe(3)
  })

  it('does not count an AI illustration, an upload still running or an empty entry', () => {
    expect(
      ownPhotoCount({
        attachments: [
          { id: 5, externalmods: { ai: true } },
          { id: 6, uploading: true },
          {},
          null,
        ],
      })
    ).toBe(0)
  })

  it('is zero for a draft with no attachments', () => {
    expect(ownPhotoCount({})).toBe(0)
    expect(ownPhotoCount(null)).toBe(0)
  })
})

describe('givePhotoCount', () => {
  it('is the most photos on any one draft', () => {
    expect(
      givePhotoCount([
        { attachments: [{ id: 1 }] },
        { attachments: [{ id: 2 }, { id: 3 }] },
      ])
    ).toBe(2)
  })

  it('is zero with no drafts', () => {
    expect(givePhotoCount([])).toBe(0)
    expect(givePhotoCount(undefined)).toBe(0)
  })
})

describe('reportGivePosted', () => {
  beforeEach(() => {
    log.action.mockReset()
    variant.value = 'give-photos-carry-on:b'
  })

  it('sends one give_posted line with the photo count and the arms', () => {
    reportGivePosted(2)

    expect(log.action).toHaveBeenCalledTimes(1)
    expect(log.action).toHaveBeenCalledWith('give_posted', {
      event_type: 'give_posted',
      photos: 2,
      with_photo: true,
      variant: 'give-photos-carry-on:b',
    })
  })

  it('says there was no photo when there was none', () => {
    reportGivePosted(0)

    expect(log.action.mock.calls[0][1]).toMatchObject({
      photos: 0,
      with_photo: false,
    })
  })

  it('never throws into the flow', () => {
    log.action.mockImplementation(() => {
      throw new Error('boom')
    })
    expect(() => reportGivePosted(0)).not.toThrow()
  })
})
