package org.freegle.app.model

import kotlinx.serialization.Serializable

// V2 API returns bare array from GET /notification
@Serializable
data class AppNotification(
    val id: Long = 0,
    val fromuser: Long? = null,
    val touser: Long? = null,
    val type: String? = null,
    val newsfeedid: Long? = null,
    val eventid: Long? = null,
    val volunteeringid: Long? = null,
    val url: String? = null,
    val seen: Boolean = false,
    val mailed: Boolean = false,
    val timestamp: String? = null,
    val title: String? = null,
    val text: String? = null,
)

// GET /notification/count returns { "count": N }
@Serializable
data class NotificationCountResponse(
    val count: Long = 0,
)
