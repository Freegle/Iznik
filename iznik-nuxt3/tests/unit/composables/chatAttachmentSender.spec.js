import { describe, it, expect, vi } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { createAttachmentSender } from '~/composables/chatAttachmentSender'

// Issue #109: the uploader adds images one at a time and the chat footer's watcher sees
// the whole list each time. These are the lists it sees during a three-photo upload.
describe('createAttachmentSender', () => {
  it('sends each image once while the list grows one image at a time', async () => {
    const send = vi.fn().mockResolvedValue({})
    const sendNew = createAttachmentSender(send)

    await Promise.all(sendNew([{ id: 1 }]))
    await Promise.all(sendNew([{ id: 1 }, { id: 2 }]))
    await Promise.all(sendNew([{ id: 1 }, { id: 2 }, { id: 3 }]))

    expect(send.mock.calls.map(([id]) => id)).toEqual([1, 2, 3])
  })

  it('sends nothing when the list has nothing new, including when it is cleared', () => {
    const send = vi.fn().mockResolvedValue({})
    const sendNew = createAttachmentSender(send)

    sendNew([{ id: 7 }])
    expect(sendNew([{ id: 7 }])).toEqual([])
    expect(sendNew([])).toEqual([])
    expect(sendNew(null)).toEqual([])
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('ignores an entry with no image id', () => {
    const send = vi.fn().mockResolvedValue({})
    const sendNew = createAttachmentSender(send)

    expect(sendNew([{}, null, { id: 0 }])).toEqual([])
    expect(send).not.toHaveBeenCalled()
  })

  it('lets a later change retry an image whose send failed', async () => {
    const send = vi
      .fn()
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValue({})
    const sendNew = createAttachmentSender(send)

    await expect(Promise.all(sendNew([{ id: 5 }]))).rejects.toThrow('network')
    await Promise.all(sendNew([{ id: 5 }]))

    expect(send.mock.calls.map(([id]) => id)).toEqual([5, 5])
  })

  it('does not send an image twice when the list changes while its send is in flight', () => {
    let finish
    const send = vi.fn(() => new Promise((resolve) => (finish = resolve)))
    const sendNew = createAttachmentSender(send)

    sendNew([{ id: 9 }])
    sendNew([{ id: 9 }, { id: 10 }])
    finish()

    expect(send.mock.calls.map(([id]) => id)).toEqual([9, 10])
  })

  it.each([
    'components/ChatFooter.vue',
    'modtools/components/ModChatFooter.vue',
  ])('is what %s sends uploaded images through', (path) => {
    const footer = readFileSync(resolve(__dirname, '../../..', path), 'utf8')
    expect(footer).toContain('createAttachmentSender(')
    expect(footer).toContain('sendNewAttachments(newVal)')
    expect(footer).not.toContain('null, null, att.id')
  })
})
