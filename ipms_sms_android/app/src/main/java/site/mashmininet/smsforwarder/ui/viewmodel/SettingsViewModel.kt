package site.mashmininet.smsforwarder.ui.viewmodel

import android.app.Application
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import android.util.Log
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import site.mashmininet.smsforwarder.admin.MyDeviceAdminReceiver
import site.mashmininet.smsforwarder.data.repository.PreferencesRepository
import site.mashmininet.smsforwarder.data.repository.SmsRepository
import site.mashmininet.smsforwarder.service.SmsForegroundService
import site.mashmininet.smsforwarder.util.OemSettings
import site.mashmininet.smsforwarder.util.PermissionsHelper
import site.mashmininet.smsforwarder.util.ServiceLauncher

/**
 * ViewModel for the Settings screen.
 * Manages configuration state, permissions, SIM detection, and service control.
 */
class SettingsViewModel(application: Application) : AndroidViewModel(application) {

    companion object {
        private const val TAG = "SettingsViewModel"
    }

    private val preferencesRepository = PreferencesRepository.getInstance(application)
    private val smsRepository = SmsRepository.getInstance(application)

    // ── UI State ──

    data class SettingsUiState(
        // SIM Configuration
        val sim1Number: String = "",
        val sim2Number: String = "",
        val lastDetectedTime: Long = 0L,
        // Permissions
        val smsPermissionGranted: Boolean = false,
        val phoneStatePermissionGranted: Boolean = false,
        val notificationPermissionGranted: Boolean = false,
        val batteryOptimizationDisabled: Boolean = false,
        val exactAlarmAllowed: Boolean = false,
        val deviceAdminEnabled: Boolean = false,
        val accessibilityServiceEnabled: Boolean = false,
        val serviceRunning: Boolean = false,
        // OEM-specific power management (Xiaomi, OPPO, Vivo, Realme, etc.)
        val oemManufacturerName: String = "",
        // General
        val showResetDialog: Boolean = false,
        val showClearLogsDialog: Boolean = false
    )

    private val _uiState = MutableStateFlow(SettingsUiState())
    val uiState: StateFlow<SettingsUiState> = _uiState.asStateFlow()

    // Snackbar events
    private val _snackbarEvent = MutableSharedFlow<String>()
    val snackbarEvent = _snackbarEvent.asSharedFlow()

    // Intent events
    private val _intentEvent = MutableSharedFlow<Intent>()
    val intentEvent = _intentEvent.asSharedFlow()

    init {
        loadSettings()
        refreshPermissions()
    }

    /**
     * Load all saved settings from DataStore.
     */
    private fun loadSettings() {
        viewModelScope.launch {
            val sim1 = preferencesRepository.sim1Number.first()
            val sim2 = preferencesRepository.sim2Number.first()
            val lastDetected = preferencesRepository.lastDetectedTime.first()

            _uiState.value = _uiState.value.copy(
                sim1Number = sim1,
                sim2Number = sim2,
                lastDetectedTime = lastDetected
            )
        }
    }

    // ── SIM Configuration ──

    fun updateSim1Number(number: String) {
        _uiState.value = _uiState.value.copy(sim1Number = number)
    }

    fun updateSim2Number(number: String) {
        _uiState.value = _uiState.value.copy(sim2Number = number)
    }

    fun savePhoneNumbers() {
        viewModelScope.launch {
            preferencesRepository.saveSim1Number(_uiState.value.sim1Number)
            preferencesRepository.saveSim2Number(_uiState.value.sim2Number)
            _snackbarEvent.emit("Phone numbers updated successfully")
        }
    }

    @Suppress("MissingPermission")
    fun detectSimNumbers() {
        val context = getApplication<Application>()
        try {
            val telephonyManager = context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager

            // Try to detect SIM 1 number
            var sim1 = ""
            var sim2 = ""

            try {
                val line1 = telephonyManager.line1Number
                if (!line1.isNullOrBlank()) {
                    sim1 = line1
                }
            } catch (e: SecurityException) {
                Log.w(TAG, "Cannot read SIM1 number: ${e.message}")
            }

            // Try to detect SIM 2 via SubscriptionManager
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP_MR1) {
                try {
                    val subManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager
                    val subscriptions = subManager.activeSubscriptionInfoList
                    if (subscriptions != null && subscriptions.size > 1) {
                        val sub2 = subscriptions[1]
                        val tmForSub = telephonyManager.createForSubscriptionId(sub2.subscriptionId)
                        val line2 = tmForSub.line1Number
                        if (!line2.isNullOrBlank()) {
                            sim2 = line2
                        }
                    }
                    // Also try first subscription if sim1 was empty
                    if (sim1.isBlank() && subscriptions != null && subscriptions.isNotEmpty()) {
                        val sub1 = subscriptions[0]
                        val tmForSub = telephonyManager.createForSubscriptionId(sub1.subscriptionId)
                        val line1 = tmForSub.line1Number
                        if (!line1.isNullOrBlank()) {
                            sim1 = line1
                        }
                    }
                } catch (e: SecurityException) {
                    Log.w(TAG, "Cannot read subscription info: ${e.message}")
                }
            }

            val now = System.currentTimeMillis()
            _uiState.value = _uiState.value.copy(
                sim1Number = sim1.ifBlank { _uiState.value.sim1Number },
                sim2Number = sim2.ifBlank { _uiState.value.sim2Number },
                lastDetectedTime = now
            )

            viewModelScope.launch {
                preferencesRepository.saveLastDetectedTime(now)
                if (sim1.isNotBlank()) preferencesRepository.saveSim1Number(sim1)
                if (sim2.isNotBlank()) preferencesRepository.saveSim2Number(sim2)
                _snackbarEvent.emit(
                    if (sim1.isNotBlank() || sim2.isNotBlank()) "SIM numbers detected"
                    else "Could not detect SIM numbers. Enter manually."
                )
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error detecting SIM numbers", e)
            viewModelScope.launch {
                _snackbarEvent.emit("Error detecting SIM numbers")
            }
        }
    }

    // ── Permissions ──

    fun refreshPermissions() {
        val context = getApplication<Application>()
        val manufacturer = if (OemSettings.hasKnownOem(context)) Build.MANUFACTURER.trim() else ""

        _uiState.value = _uiState.value.copy(
            smsPermissionGranted = PermissionsHelper.hasSmsPermissions(context),
            phoneStatePermissionGranted = PermissionsHelper.hasPhonePermission(context),
            notificationPermissionGranted = PermissionsHelper.hasNotificationPermission(context),
            batteryOptimizationDisabled = PermissionsHelper.isBatteryOptimizationDisabled(context),
            exactAlarmAllowed = PermissionsHelper.canScheduleExactAlarms(context),
            deviceAdminEnabled = PermissionsHelper.isDeviceAdminActive(context),
            accessibilityServiceEnabled = PermissionsHelper.isAccessibilityServiceEnabled(context),
            serviceRunning = SmsForegroundService.isRunning,
            oemManufacturerName = manufacturer
        )
    }

    fun requestExactAlarm() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return
        val context = getApplication<Application>()
        viewModelScope.launch {
            val intent = Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM).apply {
                data = Uri.parse("package:${context.packageName}")
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            _intentEvent.emit(intent)
        }
    }

    fun requestBatteryOptimization() {
        val context = getApplication<Application>()
        viewModelScope.launch {
            val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                data = Uri.parse("package:${context.packageName}")
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            _intentEvent.emit(intent)
        }
    }

    fun requestDeviceAdmin() {
        val context = getApplication<Application>()
        viewModelScope.launch {
            val adminComponent = ComponentName(context, MyDeviceAdminReceiver::class.java)
            val intent = Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN).apply {
                putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, adminComponent)
                putExtra(
                    DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                    "SMS Forwarder needs device admin to prevent force-stopping."
                )
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            _intentEvent.emit(intent)
        }
    }

    fun openAccessibilitySettings() {
        viewModelScope.launch {
            val intent = Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            _intentEvent.emit(intent)
        }
    }

    fun openAutostartSettings() {
        val context = getApplication<Application>()
        viewModelScope.launch {
            val intent = OemSettings.resolveIntent(context) ?: appDetailsIntent(context)
            _intentEvent.emit(intent)
        }
    }

    fun openOemBatterySettings() {
        val context = getApplication<Application>()
        viewModelScope.launch {
            val intent = OemSettings.resolveIntent(context) ?: batteryOptimizationIntent(context)
            _intentEvent.emit(intent)
        }
    }

    private fun appDetailsIntent(context: Context): Intent =
        Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.parse("package:${context.packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }

    private fun batteryOptimizationIntent(context: Context): Intent =
        Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = Uri.parse("package:${context.packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }

    // ── Service Control ──

    fun toggleService() {
        val context = getApplication<Application>()
        if (SmsForegroundService.isRunning) {
            context.stopService(Intent(context, SmsForegroundService::class.java))
            _uiState.value = _uiState.value.copy(serviceRunning = false)
            viewModelScope.launch {
                preferencesRepository.setServiceEnabled(false)
                _snackbarEvent.emit("Service stopped")
            }
        } else {
            ServiceLauncher.start(context)
            _uiState.value = _uiState.value.copy(serviceRunning = true)
            viewModelScope.launch {
                preferencesRepository.setServiceEnabled(true)
                _snackbarEvent.emit("Service started")
            }
        }
    }

    // ── Danger Zone ──

    fun showResetDialog() {
        _uiState.value = _uiState.value.copy(showResetDialog = true)
    }

    fun dismissResetDialog() {
        _uiState.value = _uiState.value.copy(showResetDialog = false)
    }

    fun showClearLogsDialog() {
        _uiState.value = _uiState.value.copy(showClearLogsDialog = true)
    }

    fun dismissClearLogsDialog() {
        _uiState.value = _uiState.value.copy(showClearLogsDialog = false)
    }

    fun resetSettings() {
        viewModelScope.launch {
            preferencesRepository.resetAllSettings()
            smsRepository.resetAll()
            _uiState.value = SettingsUiState()
            _snackbarEvent.emit("All settings have been reset")
            dismissResetDialog()
        }
    }

    fun clearLogs() {
        viewModelScope.launch {
            smsRepository.clearForwardLog()
            _snackbarEvent.emit("SMS forward logs cleared")
            dismissClearLogsDialog()
        }
    }
}
