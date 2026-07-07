package org.freegle.app.repository

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.ChatMessage
import org.freegle.app.model.ChatRoom

class ChatRepository(private val api: FreegleApi) {

    private val _chatRooms = MutableStateFlow<List<ChatRoom>>(emptyList())
    val chatRooms: StateFlow<List<ChatRoom>> = _chatRooms.asStateFlow()

    private val _currentMessages = MutableStateFlow<List<ChatMessage>>(emptyList())
    val currentMessages: StateFlow<List<ChatMessage>> = _currentMessages.asStateFlow()

    private val _isLoading = MutableStateFlow(false)
    val isLoading: StateFlow<Boolean> = _isLoading.asStateFlow()

    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error.asStateFlow()

    suspend fun loadChatRooms() {
        _isLoading.value = true
        _error.value = null
        try {
            _chatRooms.value = api.getChatRooms()
        } catch (e: Exception) {
            _error.value = e.message ?: "Couldn\u2019t load conversations"
        } finally {
            _isLoading.value = false
        }
    }

    suspend fun loadMessages(chatId: Long) {
        _isLoading.value = true
        _error.value = null
        try {
            _currentMessages.value = api.getChatMessages(chatId)
        } catch (e: Exception) {
            _error.value = e.message ?: "Couldn\u2019t load messages"
        } finally {
            _isLoading.value = false
        }
    }

    suspend fun sendMessage(chatId: Long, message: String): Boolean {
        return try {
            val success = api.sendChatMessage(chatId, message)
            if (success) {
                loadMessages(chatId)
            }
            success
        } catch (_: Exception) {
            false
        }
    }

    fun getTotalUnseenCount(): Long {
        return _chatRooms.value.sumOf { it.unseen }
    }
}
