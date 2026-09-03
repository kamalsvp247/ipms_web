package site.mashmininet.smsforwarder.data.repository

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "sms_forwarder_settings")

/**
 * Repository for managing app preferences using Jetpack DataStore.
 * Exposes all settings as Flow<T> for reactive observation.
 */
class PreferencesRepository(private val context: Context) {

    companion object {
        val KEY_SIM1_NUMBER = stringPreferencesKey("sim1_number")
        val KEY_SIM2_NUMBER = stringPreferencesKey("sim2_number")
        val KEY_SERVICE_ENABLED = booleanPreferencesKey("service_enabled")
        val KEY_LAST_DETECTED_TIME = longPreferencesKey("last_detected_time")
        val KEY_FORWARD_LOG = stringPreferencesKey("forward_log")
        val KEY_HIDDEN_SMS_IDS = stringPreferencesKey("hidden_sms_ids")
        val KEY_SETUP_COMPLETE = booleanPreferencesKey("setup_complete")

        @Volatile
        private var instance: PreferencesRepository? = null

        fun getInstance(context: Context): PreferencesRepository {
            return instance ?: synchronized(this) {
                instance ?: PreferencesRepository(context.applicationContext).also {
                    instance = it
                }
            }
        }
    }

    // ── SIM Numbers ──

    val sim1Number: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[KEY_SIM1_NUMBER] ?: ""
    }

    val sim2Number: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[KEY_SIM2_NUMBER] ?: ""
    }

    suspend fun saveSim1Number(number: String) {
        context.dataStore.edit { prefs ->
            prefs[KEY_SIM1_NUMBER] = number
        }
    }

    suspend fun saveSim2Number(number: String) {
        context.dataStore.edit { prefs ->
            prefs[KEY_SIM2_NUMBER] = number
        }
    }

    suspend fun getSim1NumberSync(): String {
        return context.dataStore.data.first()[KEY_SIM1_NUMBER] ?: ""
    }

    suspend fun getSim2NumberSync(): String {
        return context.dataStore.data.first()[KEY_SIM2_NUMBER] ?: ""
    }

    // ── Service Enabled ──

    val serviceEnabled: Flow<Boolean> = context.dataStore.data.map { prefs ->
        prefs[KEY_SERVICE_ENABLED] ?: false
    }

    suspend fun setServiceEnabled(enabled: Boolean) {
        context.dataStore.edit { prefs ->
            prefs[KEY_SERVICE_ENABLED] = enabled
        }
    }

    // ── First-launch Setup ──

    val setupComplete: Flow<Boolean> = context.dataStore.data.map { prefs ->
        prefs[KEY_SETUP_COMPLETE] ?: false
    }

    suspend fun getSetupCompleteSync(): Boolean {
        return context.dataStore.data.first()[KEY_SETUP_COMPLETE] ?: false
    }

    suspend fun setSetupComplete(complete: Boolean) {
        context.dataStore.edit { prefs ->
            prefs[KEY_SETUP_COMPLETE] = complete
        }
    }

    // ── Last Detected Time ──

    val lastDetectedTime: Flow<Long> = context.dataStore.data.map { prefs ->
        prefs[KEY_LAST_DETECTED_TIME] ?: 0L
    }

    suspend fun saveLastDetectedTime(time: Long) {
        context.dataStore.edit { prefs ->
            prefs[KEY_LAST_DETECTED_TIME] = time
        }
    }

    // ── Forward Log (JSON) ──

    val forwardLog: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[KEY_FORWARD_LOG] ?: ""
    }

    suspend fun saveForwardLog(log: String) {
        context.dataStore.edit { prefs ->
            prefs[KEY_FORWARD_LOG] = log
        }
    }

    // ── Hidden SMS IDs ──

    val hiddenSmsIds: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[KEY_HIDDEN_SMS_IDS] ?: ""
    }

    suspend fun saveHiddenSmsIds(json: String) {
        context.dataStore.edit { prefs ->
            prefs[KEY_HIDDEN_SMS_IDS] = json
        }
    }

    // ── Danger Zone ──

    suspend fun clearForwardLog() {
        context.dataStore.edit { prefs ->
            prefs[KEY_FORWARD_LOG] = ""
        }
    }

    suspend fun resetAllSettings() {
        context.dataStore.edit { prefs ->
            prefs.clear()
        }
    }
}
