package org.freegle.app.api

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

class AuthManager {
    private val _token = MutableStateFlow<String?>(null)
    val token: StateFlow<String?> = _token.asStateFlow()

    private val _userId = MutableStateFlow<Long?>(null)
    val userId: StateFlow<Long?> = _userId.asStateFlow()

    private val _persistentToken = MutableStateFlow<String?>(null)
    val persistentToken: StateFlow<String?> = _persistentToken.asStateFlow()

    val isLoggedIn: Boolean get() = _token.value != null || _persistentToken.value != null

    fun setCredentials(token: String, userId: Long) {
        _token.value = token
        _userId.value = userId
    }

    fun setPersistentToken(persistent: String) {
        _persistentToken.value = persistent
    }

    fun clearCredentials() {
        _token.value = null
        _userId.value = null
        _persistentToken.value = null
    }
}
