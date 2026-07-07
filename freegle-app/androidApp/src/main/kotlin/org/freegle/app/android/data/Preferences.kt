package org.freegle.app.android.data

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.doublePreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "freegle_prefs")

class FreeglePreferences(private val context: Context) {

    companion object {
        private val POSTCODE = stringPreferencesKey("postcode")
        private val LOCATION_NAME = stringPreferencesKey("location_name")
        private val LAT = doublePreferencesKey("lat")
        private val LNG = doublePreferencesKey("lng")
        private val DEVICE_ID = stringPreferencesKey("device_id")
        private val ONBOARDING_COMPLETE = booleanPreferencesKey("onboarding_complete")
        private val TOUR_COMPLETE = booleanPreferencesKey("tour_complete")

        // Auth credentials
        private val AUTH_JWT = stringPreferencesKey("auth_jwt")
        private val AUTH_USER_ID = longPreferencesKey("auth_user_id")
        private val AUTH_PERSISTENT = stringPreferencesKey("auth_persistent")
        private val AUTH_EMAIL = stringPreferencesKey("auth_email")

        // Streak tracking
        private val STREAK_COUNT = intPreferencesKey("streak_count")
        private val STREAK_LAST_DATE = stringPreferencesKey("streak_last_date")
        private val STREAK_BEST = intPreferencesKey("streak_best")
    }

    // === Location ===

    suspend fun saveLocation(postcode: String, locationName: String, lat: Double, lng: Double) {
        context.dataStore.edit { prefs ->
            prefs[POSTCODE] = postcode
            prefs[LOCATION_NAME] = locationName
            prefs[LAT] = lat
            prefs[LNG] = lng
        }
    }

    suspend fun getPostcode(): String =
        context.dataStore.data.map { it[POSTCODE] ?: "" }.first()

    suspend fun getLocationName(): String =
        context.dataStore.data.map { it[LOCATION_NAME] ?: "" }.first()

    suspend fun getLat(): Double =
        context.dataStore.data.map { it[LAT] ?: 0.0 }.first()

    suspend fun getLng(): Double =
        context.dataStore.data.map { it[LNG] ?: 0.0 }.first()

    // === Device ID ===

    suspend fun getOrCreateDeviceId(): String {
        val existing = context.dataStore.data.map { it[DEVICE_ID] }.first()
        if (existing != null) return existing
        val newId = java.util.UUID.randomUUID().toString()
        context.dataStore.edit { it[DEVICE_ID] = newId }
        return newId
    }

    suspend fun getDeviceId(): String? =
        context.dataStore.data.map { it[DEVICE_ID] }.first()

    // === Onboarding ===

    suspend fun isOnboardingComplete(): Boolean =
        context.dataStore.data.map { it[ONBOARDING_COMPLETE] ?: false }.first()

    suspend fun setOnboardingComplete() {
        context.dataStore.edit { it[ONBOARDING_COMPLETE] = true }
    }

    suspend fun isTourComplete(): Boolean =
        context.dataStore.data.map { it[TOUR_COMPLETE] ?: false }.first()

    suspend fun setTourComplete() {
        context.dataStore.edit { it[TOUR_COMPLETE] = true }
    }

    // === Auth credentials ===

    suspend fun saveAuth(jwt: String, userId: Long, persistent: String?) {
        context.dataStore.edit { prefs ->
            prefs[AUTH_JWT] = jwt
            prefs[AUTH_USER_ID] = userId
            if (persistent != null) prefs[AUTH_PERSISTENT] = persistent
        }
    }

    suspend fun getAuthJwt(): String? =
        context.dataStore.data.map { it[AUTH_JWT] }.first()

    suspend fun getAuthUserId(): Long? =
        context.dataStore.data.map { it[AUTH_USER_ID] }.first()

    suspend fun getAuthPersistent(): String? =
        context.dataStore.data.map { it[AUTH_PERSISTENT] }.first()

    suspend fun clearAuth() {
        context.dataStore.edit { prefs ->
            prefs.remove(AUTH_JWT)
            prefs.remove(AUTH_USER_ID)
            prefs.remove(AUTH_PERSISTENT)
        }
    }

    suspend fun saveEmail(email: String) {
        context.dataStore.edit { it[AUTH_EMAIL] = email }
    }

    suspend fun getEmail(): String? =
        context.dataStore.data.map { it[AUTH_EMAIL] }.first()

    // === Streak tracking ===

    data class StreakState(
        val count: Int,
        val best: Int,
        val completedToday: Boolean,
        val atRisk: Boolean, // true if yesterday was completed but today isn't yet
    )

    suspend fun getStreakState(): StreakState {
        val count = context.dataStore.data.map { it[STREAK_COUNT] ?: 0 }.first()
        val best = context.dataStore.data.map { it[STREAK_BEST] ?: 0 }.first()
        val lastDateStr = context.dataStore.data.map { it[STREAK_LAST_DATE] }.first()

        val today = LocalDate.now()
        val lastDate = lastDateStr?.let {
            try { LocalDate.parse(it, DateTimeFormatter.ISO_LOCAL_DATE) } catch (_: Exception) { null }
        }

        val completedToday = lastDate == today
        val atRisk = lastDate != null && ChronoUnit.DAYS.between(lastDate, today) == 1L

        return StreakState(
            count = count,
            best = best,
            completedToday = completedToday,
            atRisk = atRisk,
        )
    }

    suspend fun recordDailyCompletion() {
        val today = LocalDate.now()
        val todayStr = today.format(DateTimeFormatter.ISO_LOCAL_DATE)

        context.dataStore.edit { prefs ->
            val lastDateStr = prefs[STREAK_LAST_DATE]
            val lastDate = lastDateStr?.let {
                try { LocalDate.parse(it, DateTimeFormatter.ISO_LOCAL_DATE) } catch (_: Exception) { null }
            }

            val currentCount = prefs[STREAK_COUNT] ?: 0
            val currentBest = prefs[STREAK_BEST] ?: 0

            val newCount = when {
                lastDate == today -> return@edit // Already recorded today
                lastDate != null && ChronoUnit.DAYS.between(lastDate, today) == 1L -> currentCount + 1
                else -> 1 // Gap > 1 day or first time
            }

            prefs[STREAK_COUNT] = newCount
            prefs[STREAK_LAST_DATE] = todayStr
            if (newCount > currentBest) {
                prefs[STREAK_BEST] = newCount
            }
        }
    }
}
