package org.freegle.app.model

import kotlinx.serialization.Serializable

// V2 API returns bare user object from GET /user/{id}
@Serializable
data class User(
    val id: Long = 0,
    val displayname: String? = null,
    val firstname: String? = null,
    val lastname: String? = null,
    val fullname: String? = null,
    val email: String? = null,
    val profile: UserProfile? = null,
    val info: UserInfo? = null,
    val settings: UserSettings? = null,
    val supporter: Boolean = false,
    val lastaccess: String? = null,
    val added: String? = null,
    val lat: Float? = null,
    val lng: Float? = null,
    val aboutme: UserAboutMe? = null,
    val spammer: Boolean = false,
)

@Serializable
data class UserProfile(
    val url: String? = null,
    val path: String? = null,
    val paththumb: String? = null,
    val turl: String? = null,
)

@Serializable
data class UserInfo(
    val offers: Int = 0,
    val wanteds: Int = 0,
    val replies: Int = 0,
    val collected: Int = 0,
)

@Serializable
data class UserSettings(
    val notificationmails: Boolean = true,
    val relevantallowed: Boolean = true,
    val newslettersallowed: Boolean = true,
)

@Serializable
data class UserAboutMe(
    val text: String? = null,
    val timestamp: String? = null,
)
