import { describe, it, expect, vi } from 'vitest'
import SupportCasesAPI from '~/api/SupportCasesAPI'
import ChatAPI from '~/api/ChatAPI'
import api from '~/api'

function cases() {
  const a = new SupportCasesAPI({ public: {} })
  a.$getv2 = vi.fn().mockResolvedValue([])
  a.$postv2 = vi.fn().mockResolvedValue({ ret: 0 })
  a.$patchv2 = vi.fn().mockResolvedValue({ ret: 0 })
  return a
}

describe('SupportCasesAPI', () => {
  it('lists and fetches cases', async () => {
    const a = cases()
    await a.fetchCases({ status: 'Open', limit: 50 })
    expect(a.$getv2).toHaveBeenCalledWith('/supportcases', {
      status: 'Open',
      limit: 50,
    })
    await a.fetchCase(7)
    expect(a.$getv2).toHaveBeenCalledWith('/supportcases/7')
  })

  it('sends a message with or without a draft marker', async () => {
    const a = cases()
    await a.sendMessage(7, 'Hi', 'Edited')
    expect(a.$postv2).toHaveBeenCalledWith('/supportcases/7/messages', {
      body: 'Hi',
      draftused: 'Edited',
    })
    await a.sendMessage(7, 'Hi')
    expect(a.$postv2).toHaveBeenLastCalledWith('/supportcases/7/messages', {
      body: 'Hi',
    })
  })

  it('sets the status', async () => {
    const a = cases()
    await a.setStatus(7, 'Handled')
    expect(a.$patchv2).toHaveBeenCalledWith('/supportcases', {
      id: 7,
      status: 'Handled',
    })
  })

  it('is registered on the api object', () => {
    expect(api({ public: {} }).supportcases).toBeInstanceOf(SupportCasesAPI)
  })
})

describe('ChatAPI.referToSupport', () => {
  it('sends the reason when there is one and returns the response', async () => {
    const a = new ChatAPI({ public: {} })
    a.$postv2 = vi.fn().mockResolvedValue({ ret: 0, caseid: 12 })
    const ret = await a.referToSupport(5, 'Help')
    expect(a.$postv2).toHaveBeenCalledWith('/chatrooms', {
      id: 5,
      action: 'ReferToSupport',
      reason: 'Help',
    })
    expect(ret.caseid).toBe(12)
  })

  it('leaves the reason out when it is empty', async () => {
    const a = new ChatAPI({ public: {} })
    a.$postv2 = vi.fn().mockResolvedValue({ ret: 0 })
    await a.referToSupport(5, '')
    expect(a.$postv2).toHaveBeenCalledWith('/chatrooms', {
      id: 5,
      action: 'ReferToSupport',
    })
  })
})
