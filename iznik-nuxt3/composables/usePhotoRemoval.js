// Taking a photo off a post, shared by ModTools (ModPhoto, ModPhotoModal) and the member
// site's photo viewer (MessagePhotosModal, for moderators). The V2 message PATCH takes the
// list of attachments to KEEP; an AI-generated image the moderator says is bad for any
// post of this item is also listed in badAIImages, which stops AI images for the item.

// The attachment's modifiers, whether they arrive as a JSON string or an object, in
// externalmods (V2) or mods (older payloads). Anything unparseable reads as no modifiers.
export function attachmentMods(attachment) {
  const raw = attachment?.externalmods || attachment?.mods
  if (!raw) return {}
  try {
    const mods = typeof raw === 'string' ? JSON.parse(raw) : raw
    return mods && typeof mods === 'object' ? mods : {}
  } catch (e) {
    return {}
  }
}

// The V2 API also sends a computed `ai` flag on each attachment; the modifiers are the
// source of truth in ModTools payloads, so accept either.
export function isAIAttachment(attachment) {
  return attachment?.ai === true || Boolean(attachmentMods(attachment).ai)
}

// The PATCH body that removes one attachment from a message.
export function removePhotoPatch(message, attachmentId, badForAnyPost) {
  const attachments = (message?.attachments || [])
    .filter((a) => a.id !== attachmentId)
    .map((a) => a.id)
  const patch = { id: message.id, attachments }
  if (badForAnyPost) {
    patch.badAIImages = [attachmentId]
  }
  return patch
}
