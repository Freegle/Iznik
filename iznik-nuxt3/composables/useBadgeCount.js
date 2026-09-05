/**
 * Combined unread total used to write the native app-icon badge: unread
 * chats plus unread notifications, clamped to the same ceiling either count
 * shows on its own. Both writers of the OS badge - useNavbar()'s chatCount
 * computed and mobileStore's startBadgeSync() watch - share this function so
 * the formula cannot drift between them (Discourse 9953/6 review: they'd
 * been reimplementing it independently).
 *
 * @param {number} chatUnread chatStore.unreadCount
 * @param {number} notificationCount notificationStore.count
 * @returns {number}
 */
export function combinedBadgeCount(chatUnread, notificationCount) {
  return Math.min(99, (chatUnread || 0) + (notificationCount || 0))
}
