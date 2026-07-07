package org.freegle.app.repository

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.AppNotification

class NotificationRepository(private val api: FreegleApi) {

    private val _notifications = MutableStateFlow<List<AppNotification>>(emptyList())
    val notifications: StateFlow<List<AppNotification>> = _notifications.asStateFlow()

    private val _unseenCount = MutableStateFlow(0L)
    val unseenCount: StateFlow<Long> = _unseenCount.asStateFlow()

    suspend fun loadNotifications() {
        try {
            _notifications.value = api.getNotifications()
        } catch (_: Exception) {
        }
    }

    suspend fun loadUnseenCount() {
        try {
            _unseenCount.value = api.getNotificationCount().count
        } catch (_: Exception) {
        }
    }

    suspend fun markAllSeen() {
        try {
            api.markNotificationsSeen()
            _unseenCount.value = 0
        } catch (_: Exception) {
        }
    }
}
