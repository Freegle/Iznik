package org.freegle.app.api

import io.ktor.client.*
import io.ktor.client.call.*
import io.ktor.client.plugins.contentnegotiation.*
import io.ktor.client.plugins.logging.*
import io.ktor.client.request.*
import io.ktor.client.statement.*
import io.ktor.http.*
import io.ktor.serialization.kotlinx.json.*
import kotlinx.serialization.json.Json
import org.freegle.app.model.*

class FreegleApi(
    private val baseUrl: String,
    private val v1BaseUrl: String,
    private val authManager: AuthManager,
) {
    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        coerceInputValues = true
    }

    private val client = HttpClient {
        install(ContentNegotiation) {
            json(this@FreegleApi.json)
        }
        install(Logging) {
            level = LogLevel.NONE
        }
    }

    private fun HttpRequestBuilder.addAuth() {
        authManager.token.value?.let { jwt ->
            parameter("jwt", jwt)
        }
        // Persistent token as fallback (supported by Go V2 API via Authorization2 header)
        authManager.persistentToken.value?.let { persistent ->
            header("Authorization2", persistent)
        }
    }

    // === V1 API - User creation and session management ===

    /** Create a new user via V1 PHP API. Returns JWT + persistent token. */
    suspend fun createDeviceUser(email: String): V1UserResponse? {
        val response = client.put("$v1BaseUrl/user") {
            contentType(ContentType.Application.Json)
            setBody(mapOf("email" to email))
        }
        if (!response.status.isSuccess()) return null
        return response.body()
    }

    /** Add or verify email on the current session via V1 PHP API. */
    suspend fun addEmailToSession(email: String): V1SessionResponse? {
        val response = client.patch("$v1BaseUrl/session") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("email" to email))
        }
        if (!response.status.isSuccess()) return null
        return response.body()
    }

    /** Confirm email verification with key via V1 PHP API. */
    suspend fun confirmEmailVerification(key: String): V1SessionResponse? {
        val response = client.patch("$v1BaseUrl/session") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("key" to key))
        }
        if (!response.status.isSuccess()) return null
        return response.body()
    }

    // === Messages ===

    // V2 returns bare array of MessageSummary
    suspend fun getLocalMessages(
        swlat: Double, swlng: Double,
        nelat: Double, nelng: Double,
        types: List<String>? = null,
    ): List<MessageSummary> {
        val response = client.get("$baseUrl/message/inbounds") {
            addAuth()
            parameter("swlat", swlat)
            parameter("swlng", swlng)
            parameter("nelat", nelat)
            parameter("nelng", nelng)
            types?.forEach { parameter("messagetype[]", it) }
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // V2 returns bare array of SearchResult
    suspend fun searchMessages(term: String): List<SearchResult> {
        val response = client.get("$baseUrl/message/search/$term") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // V2 returns bare MessageSummary object for single ID
    suspend fun getMessage(id: Long): MessageSummary? {
        val response = client.get("$baseUrl/message/$id") {
            addAuth()
        }
        if (!response.status.isSuccess()) return null
        return response.body()
    }

    // V2 returns bare array for multiple IDs
    suspend fun getMessages(ids: List<Long>): List<MessageSummary> {
        val response = client.get("$baseUrl/message/${ids.joinToString(",")}") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // V2 returns bare array
    suspend fun getIsochroneMessages(): List<MessageSummary> {
        val response = client.get("$baseUrl/isochrone/message") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // Message actions - these use POST and may return simple status
    suspend fun promiseMessage(messageId: Long, userId: Long): Boolean {
        val response = client.post("$baseUrl/message") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf(
                "id" to messageId.toString(),
                "action" to "Promise",
                "userid" to userId.toString(),
            ))
        }
        return response.status.isSuccess()
    }

    suspend fun renegeMessage(messageId: Long, userId: Long): Boolean {
        val response = client.post("$baseUrl/message") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf(
                "id" to messageId.toString(),
                "action" to "Renege",
                "userid" to userId.toString(),
            ))
        }
        return response.status.isSuccess()
    }

    suspend fun markOutcome(messageId: Long, outcome: String): Boolean {
        val response = client.post("$baseUrl/message") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf(
                "id" to messageId.toString(),
                "action" to "Outcome",
                "outcome" to outcome,
            ))
        }
        return response.status.isSuccess()
    }

    // === Chats ===

    // V2 returns bare array of ChatRoom
    suspend fun getChatRooms(): List<ChatRoom> {
        val response = client.get("$baseUrl/chat") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // V2 returns bare array of ChatMessage
    suspend fun getChatMessages(chatId: Long): List<ChatMessage> {
        val response = client.get("$baseUrl/chat/$chatId/message") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    suspend fun sendChatMessage(chatId: Long, message: String): Boolean {
        val response = client.post("$baseUrl/chat/$chatId/message") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("message" to message))
        }
        return response.status.isSuccess()
    }

    // Reply to a message (creates a chat room if needed and sends initial message)
    suspend fun replyToMessage(messageId: Long, message: String): Boolean {
        val response = client.post("$baseUrl/message/$messageId/reply") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("message" to message))
        }
        return response.status.isSuccess()
    }

    // === User ===

    // V2 returns bare User object
    suspend fun getUser(userId: Long): User? {
        val response = client.get("$baseUrl/user/$userId") {
            addAuth()
        }
        if (!response.status.isSuccess()) return null
        return response.body()
    }

    suspend fun getMe(): User? {
        val myId = authManager.userId.value ?: return null
        return getUser(myId)
    }

    // V2 returns bare array of messages for a specific user
    suspend fun getUserMessages(userId: Long, active: Boolean = true): List<MessageSummary> {
        val response = client.get("$baseUrl/user/$userId/message") {
            addAuth()
            if (active) parameter("active", true)
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // === Notifications ===

    // V2 returns bare array of AppNotification
    suspend fun getNotifications(): List<AppNotification> {
        val response = client.get("$baseUrl/notification") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // V2 returns { "count": N }
    suspend fun getNotificationCount(): NotificationCountResponse {
        val response = client.get("$baseUrl/notification/count") {
            addAuth()
        }
        if (!response.status.isSuccess()) return NotificationCountResponse()
        return response.body()
    }

    suspend fun markNotificationsSeen(): Boolean {
        val response = client.post("$baseUrl/notification/seen") {
            addAuth()
        }
        return response.status.isSuccess()
    }

    // === Trysts (collection arrangements) ===

    // V2 returns bare array
    suspend fun getTrysts(): List<Tryst> {
        val response = client.get("$baseUrl/tryst") {
            addAuth()
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    suspend fun createTryst(user1: Long, user2: Long, arrangedFor: String): Boolean {
        val response = client.put("$baseUrl/tryst") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf(
                "user1" to user1.toString(),
                "user2" to user2.toString(),
                "arrangedfor" to arrangedFor,
            ))
        }
        return response.status.isSuccess()
    }

    suspend fun confirmTryst(id: Long): Boolean {
        val response = client.post("$baseUrl/tryst") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("id" to id.toString(), "confirm" to "true"))
        }
        return response.status.isSuccess()
    }

    suspend fun declineTryst(id: Long): Boolean {
        val response = client.post("$baseUrl/tryst") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("id" to id.toString(), "decline" to "true"))
        }
        return response.status.isSuccess()
    }

    // === Location ===

    suspend fun resolveLocation(lat: Double, lng: Double): LocationResponse? {
        val response = client.get("$baseUrl/location/latlng") {
            addAuth()
            parameter("lat", lat)
            parameter("lng", lng)
        }
        if (!response.status.isSuccess()) return null
        return response.body()
    }

    // V2 returns bare array of LocationResult
    suspend fun searchLocations(query: String, limit: Int = 10, postcodeOnly: Boolean = true): List<LocationResult> {
        val response = client.get("$baseUrl/location/typeahead") {
            addAuth()
            parameter("q", query)
            parameter("limit", limit)
            parameter("pconly", postcodeOnly)
        }
        if (!response.status.isSuccess()) return emptyList()
        return response.body()
    }

    // === Memberships (auto-join groups) ===

    suspend fun joinGroup(groupId: Long): Boolean {
        val response = client.put("$baseUrl/memberships") {
            addAuth()
            contentType(ContentType.Application.Json)
            setBody(mapOf("groupid" to groupId.toString()))
        }
        return response.status.isSuccess()
    }

    // === AI Illustrations ===

    /** Fetch a cached AI illustration for an item name. Returns the image URL or null. */
    suspend fun getIllustration(itemName: String): String? {
        val response = client.get("$baseUrl/illustration") {
            addAuth()
            parameter("item", itemName)
        }
        if (!response.status.isSuccess()) return null
        val result: IllustrationResponse = response.body()
        if (result.ret != 0) return null
        return result.url
    }
}
