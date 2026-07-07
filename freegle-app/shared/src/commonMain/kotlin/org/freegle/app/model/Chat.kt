package org.freegle.app.model

import kotlinx.serialization.Serializable

// V2 API returns bare arrays of these
@Serializable
data class ChatRoom(
    val id: Long = 0,
    val chattype: String? = null, // "User2User", "User2Mod"
    val otheruid: Long? = null,
    val supporter: Boolean = false,
    val icon: String? = null,
    val lastdate: String? = null,
    val lastmsg: Long? = null,
    val lastmsgseen: Long? = null,
    val name: String? = null,
    val replyexpected: Long? = null,
    val snippet: String? = null,
    val unseen: Long = 0,
    val status: String? = null,
)

@Serializable
data class ChatMessage(
    val id: Long = 0,
    val chatid: Long = 0,
    val userid: Long = 0,
    val type: String? = null, // "Default", "System", "Image", etc.
    val message: String? = null,
    val date: String? = null,
    val seenbyall: Boolean = false,
    val mailedtoall: Boolean = false,
    val refmsgid: Long? = null,
    val refchatid: Long? = null,
    val imageid: Long? = null,
    val image: ChatImage? = null,
    val replyexpected: Boolean = false,
    val replyreceived: Boolean = false,
    val reportreason: String? = null,
    val processingrequired: Boolean = false,
)

@Serializable
data class ChatImage(
    val id: Long = 0,
    val path: String? = null,
    val paththumb: String? = null,
    val externaluid: String? = null,
    val ouruid: String? = null,
)
