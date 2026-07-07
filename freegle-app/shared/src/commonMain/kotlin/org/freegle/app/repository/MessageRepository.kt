package org.freegle.app.repository

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.MessageSummary

class MessageRepository(private val api: FreegleApi) {

    private val _messages = MutableStateFlow<List<MessageSummary>>(emptyList())
    val messages: StateFlow<List<MessageSummary>> = _messages.asStateFlow()

    private val _isLoading = MutableStateFlow(false)
    val isLoading: StateFlow<Boolean> = _isLoading.asStateFlow()

    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error.asStateFlow()

    suspend fun loadLocalMessages(
        lat: Double, lng: Double, radiusKm: Double = 30.0,
        types: List<String>? = null,
    ) {
        _isLoading.value = true
        _error.value = null
        try {
            // Convert center + radius to bounding box
            val latDelta = radiusKm / 111.0
            val lngDelta = radiusKm / (111.0 * kotlin.math.cos(Math.toRadians(lat)))
            val summaries = api.getLocalMessages(
                swlat = lat - latDelta,
                swlng = lng - lngDelta,
                nelat = lat + latDelta,
                nelng = lng + lngDelta,
                types = types,
            )

            if (summaries.isEmpty()) {
                _messages.value = emptyList()
                return
            }

            // The inbounds endpoint returns minimal data (id, type, lat/lng).
            // Fetch full message details (subject, fromuser, attachments, etc.)
            // in batches (API allows max 19 IDs per request).
            val ids = summaries.map { it.id }
            val fullMessages = ids.chunked(19).flatMap { batch ->
                if (batch.size == 1) {
                    // Single ID returns a bare object, not an array — use getMessage
                    listOfNotNull(api.getMessage(batch.first()))
                } else {
                    api.getMessages(batch)
                }
            }
            _messages.value = fullMessages
        } catch (e: Exception) {
            _error.value = e.message ?: "Network error"
        } finally {
            _isLoading.value = false
        }
    }

    suspend fun searchMessages(query: String): List<MessageSummary> {
        return try {
            // Search returns SearchResult which has id - fetch full messages for those IDs
            val results = api.searchMessages(query)
            if (results.isEmpty()) return emptyList()
            val ids = results.map { it.id }
            api.getMessages(ids)
        } catch (e: Exception) {
            emptyList()
        }
    }

    suspend fun getMessageDetail(id: Long): MessageSummary? {
        return try {
            api.getMessage(id)
        } catch (e: Exception) {
            null
        }
    }
}

// Simple Math helper for common code
object Math {
    fun toRadians(degrees: Double): Double = degrees * kotlin.math.PI / 180.0
}
