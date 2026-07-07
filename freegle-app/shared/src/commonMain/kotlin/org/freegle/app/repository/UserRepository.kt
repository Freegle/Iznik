package org.freegle.app.repository

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.freegle.app.api.AuthManager
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.User

class UserRepository(
    private val api: FreegleApi,
    private val authManager: AuthManager,
) {
    private val _currentUser = MutableStateFlow<User?>(null)
    val currentUser: StateFlow<User?> = _currentUser.asStateFlow()

    val isLoggedIn: Boolean get() = authManager.isLoggedIn

    fun login(token: String, userId: Long) {
        authManager.setCredentials(token, userId)
    }

    fun logout() {
        authManager.clearCredentials()
        _currentUser.value = null
    }

    suspend fun loadCurrentUser() {
        try {
            _currentUser.value = api.getMe()
        } catch (_: Exception) {
        }
    }

    suspend fun getUser(userId: Long): User? {
        return try {
            api.getUser(userId)
        } catch (_: Exception) {
            null
        }
    }
}
