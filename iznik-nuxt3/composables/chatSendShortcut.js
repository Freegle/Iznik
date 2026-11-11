// Ctrl+Enter (Cmd+Enter on a Mac) sends a chat message whatever the member's "What the enter key
// does" setting is. Shift and Alt combinations keep their newline meaning.
export function isSendShortcut(e) {
  return (
    e.key === 'Enter' &&
    (e.ctrlKey || e.metaKey) &&
    !e.shiftKey &&
    !e.altKey &&
    !e.isComposing
  )
}
