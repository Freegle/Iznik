package org.freegle.app.model

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// V2 API returns bare arrays of these from /message/inbounds
@Serializable
data class MessageSummary(
    val id: Long,
    val subject: String? = null,
    val type: String? = null, // "Offer" or "Wanted"
    val textbody: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val arrival: String? = null,
    val availablenow: Int = 1,
    val promisecount: Int = 0,
    val promised: Boolean = false,
    val replycount: Int = 0,
    val successful: Boolean = false,
    val unseen: Boolean = false,
    val fromuser: Long? = null,
    val date: String? = null,
    val groupid: Long? = null,
    @SerialName("groups")
    val messageGroups: List<MessageGroup>? = null,
    @SerialName("attachments")
    val messageAttachments: List<MessageAttachment>? = null,
    val location: MessageLocation? = null,
    val item: MessageItem? = null,
    val url: String? = null,
    // Distance from user (calculated client-side)
    val distance: Double? = null,
)

@Serializable
data class MessageGroup(
    val groupid: Long = 0,
    val msgid: Long? = null,
    val arrival: String? = null,
    val namedisplay: String? = null,
    val collection: String? = null,
)

@Serializable
data class MessageAttachment(
    val id: Long = 0,
    val path: String? = null,
    val paththumb: String? = null,
)

@Serializable
data class MessageLocation(
    val id: Long = 0,
    val name: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val areaid: Long? = null,
    val areaname: String? = null,
)

@Serializable
data class MessageItem(
    val itemid: Long? = null,
    val itemname: String? = null,
    val weight: Double? = null,
)

// V1 API response for user creation (PUT /user)
@Serializable
data class V1UserResponse(
    val ret: Int = -1,
    val status: String? = null,
    val id: Long? = null,
    val jwt: String? = null,
    val persistent: String? = null,
)

// V1 API response for session (PATCH /session)
@Serializable
data class V1SessionResponse(
    val ret: Int = -1,
    val status: String? = null,
    val jwt: String? = null,
    val persistent: String? = null,
    val id: Long? = null,
    val email: String? = null,
)

// AI illustration response from /illustration endpoint
@Serializable
data class IllustrationResponse(
    val ret: Int = -1,
    val url: String? = null,
    val externaluid: String? = null,
)

// Search result has slightly different shape
@Serializable
data class SearchResult(
    val id: Long,
    val arrival: String? = null,
    val groupid: Long? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val word: String? = null,
    val type: String? = null,
    val matchedon: SearchMatchedOn? = null,
)

@Serializable
data class SearchMatchedOn(
    val type: String? = null,
    val word: String? = null,
)
