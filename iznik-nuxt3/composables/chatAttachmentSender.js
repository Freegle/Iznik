// Sends each uploaded chat image once.
//
// The uploader adds images to the chat footer's list one at a time, and every addition
// re-runs the footer's watcher over the whole list. Sending the whole list each time sent
// every earlier image again, so one upload of nine photos became dozens of chat messages
// sharing the same images (issue #109). This remembers what has been sent and sends only
// images it has not seen. An image whose send fails is forgotten again, so a later change
// to the list can retry it.
export function createAttachmentSender(send) {
  const sent = new Set()

  return function sendNew(attachments) {
    const fresh = (attachments || []).filter(
      (att) => att?.id && !sent.has(att.id)
    )

    return fresh.map((att) => {
      sent.add(att.id)
      return Promise.resolve(send(att.id)).catch((e) => {
        sent.delete(att.id)
        throw e
      })
    })
  }
}
