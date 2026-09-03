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
import site.mashmininet.smsforwarder.util.OemSettings
import site.mashmininet.smsforwarder.util.PermissionsHelper
import site.mashmininet.smsforwarder.util.ServiceLauncher

/**
 * Drives the first-launch setup wizard: sequential permission grants, SIM number
 * capture, and finally arming the forwarding service.
 */
class SetupViewModel(application: Application) : AndroidViewModel(application) {

    companion object {
        private const val TAG = "SetupViewModel"
    }

    private val preferencesRepository = PreferencesRepository.getInstance(application)

    data class SetupUiState(
        val stepIndex: Int = 0,
        val sim1Number: String = "",
        val sim2Number: String = "",
        val smsGranted: Boolean = false,
        val phoneGranted: Boolean = false,
        val notificationGranted: Boolean = false,
        val batteryOptimizationDisabled: Boolean = false,
        val exactAlarmAllowed: Boolean = false,
        val deviceAdminEnabled: Boolean = false,
        val accessibilityEnabled: Boolean = false,
        val oemManufacturerName: String = "",
        val finished: Boolean = false
    )

    private val _uiState = MutableStateFlow(SetupUiState())
    val uiState: StateFlow<SetupUiState> = _uiState.asStateFlow()

    private val _intentEvent = MutableSharedFlow<Intent>()
    val intentEvent = _intentEvent.asSharedFlow()

    init {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(
                sim1Number = preferencesRepository.sim1Number.first(),
                sim2Number = preferencesRepository.sim2Number.first()
            )
            refresh()
        }
    }

    /** Re-reads every permission/capability. Call on resume and after each grant. */
    fun refresh() {
        val context = getApplication<Application>()
        val manufacturer = if (OemSettings.hasKnownOem(context)) Build.MANUFACTURER.trim() else ""
        _uiState.value = _uiState.value.copy(
            smsGranted = PermissionsHelper.hasSmsPermissions(context),
            phoneGranted = PermissionsHelper.hasPhonePermission(context),
            notificationGranted = PermissionsHelper.hasNotificationPermission(context),
            batteryOptimizationDisabled = PermissionsHelper.isBatteryOptimizationDisabled(context),
            exactAlarmAllowed = PermissionsHelper.canScheduleExactAlarms(context),
            deviceAdminEnabled = PermissionsHelper.isDeviceAdminActive(context),
            accessibilityEnabled = PermissionsHelper.isAccessibilityServiceEnabled(context),
            oemManufacturerName = manufacturer
        )
    }

    fun goToStep(index: Int) {
        _uiState.value = _uiState.value.copy(stepIndex = index)
    }

    fun nextStep() {
        _uiState.value = _uiState.value.copy(stepIndex = _uiState.value.stepIndex + 1)
    }

    fun previousStep() {
        val target = (_uiState.value.stepIndex - 1).coerceAtLeast(0)
        _uiState.value = _uiState.value.copy(stepIndex = target)
    }

    fun updateSim1Number(number: String) {
        _uiState.value = _uiState.value.copy(sim1Number = number)
    }

    fun updateSim2Number(number: String) {
        _uiState.value = _uiState.value.copy(sim2Number = number)
    }

    @Suppress("MissingPermission")
    fun detectSimNumbers() {
        val context = getApplication<Application>()
        if (!PermissionsHelper.hasPhonePermission(context)) return
        try {
            val telephonyManager = context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager
            var sim1 = runCatching { telephonyManager.line1Number }.getOrNull().orEmpty()
            var sim2 = ""

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP_MR1) {
                runCatching {
                    val subManager = context.getSystemService(
                        Context.TELEPHONY_SUBSCRIPTION_SERVICE
                    ) as SubscriptionManager
                    val subs = subManager.activeSubscriptionInfoList
                    if (subs != null) {
                        if (sim1.isBlank() && subs.isNotEmpty()) {
                            sim1 = telephonyManager.createForSubscriptionId(subs[0].subscriptionId)
                                .line1Number.orEmpty()
                        }
                        if (subs.size > 1) {
                            sim2 = telephonyManager.createForSubscriptionId(subs[1].subscriptionId)
                                .line1Number.orEmpty()
                        }
                    }
                }
            }

            _uiState.value = _uiState.value.copy(
                sim1Number = sim1.ifBlank { _uiState.value.sim1Number },
                sim2Number = sim2.ifBlank { _uiState.value.sim2Number }
            )
        } catch (e: Exception) {
            Log.w(TAG, "SIM auto-detect failed", e)
        }
    }

    fun requestBatteryOptimization() = emitIntent(
        Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = Uri.parse("package:${getApplication<Application>().packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    )

    fun requestExactAlarm() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return
        emitIntent(
            Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM).apply {
                data = Uri.parse("package:${getApplication<Application>().packageName}")
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
        )
    }

    fun requestDeviceAdmin() {
        val context = getApplication<Application>()
        emitIntent(
            Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN).apply {
                putExtra(
                    DevicePolicyManager.EXTRA_DEVICE_ADMIN,
                    ComponentName(context, MyDeviceAdminReceiver::class.java)
                )
                putExtra(
                    DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                    "SMS Forwarder uses device admin to prevent accidental force-stop, keeping OTP delivery reliable."
                )
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
        )
    }

    fun openAccessibilitySettings() = emitIntent(
        Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
    )

    fun openOemSettings() {
        val context = getApplication<Application>()
        emitIntent(OemSettings.resolveIntent(context) ?: appDetailsIntent(context))
    }

    /** Persists SIM numbers, marks setup complete, enables and starts the service. */
    fun finishSetup() {
        viewModelScope.launch {
            preferencesRepository.saveSim1Number(_uiState.value.sim1Number.trim())
            preferencesRepository.saveSim2Number(_uiState.value.sim2Number.trim())
            preferencesRepository.setServiceEnabled(true)
            preferencesRepository.setSetupComplete(true)
            ServiceLauncher.start(getApplication())
            _uiState.value = _uiState.value.copy(finished = true)
        }
    }

    private fun appDetailsIntent(context: Context): Intent =
        Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.parse("package:${context.packageName}")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }

    private fun emitIntent(intent: Intent) {
        viewModelScope.launch { _intentEvent.emit(intent) }
    }
}
