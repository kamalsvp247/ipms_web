package site.mashmininet.smsforwarder.util

import android.Manifest
import android.app.AlarmManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import android.app.role.RoleManager
import androidx.core.content.ContextCompat
import site.mashmininet.smsforwarder.admin.MyDeviceAdminReceiver
import site.mashmininet.smsforwarder.service.SmsAccessibilityService

/**
 * Centralized, side-effect-free permission and capability checks.
 * Shared by the setup wizard and the Settings screen so both report identical state.
 */
object PermissionsHelper {

    fun isDefaultSmsApp(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.Q ||
            context.getSystemService(RoleManager::class.java)?.isRoleHeld(RoleManager.ROLE_SMS) == true

    fun defaultSmsRoleIntent(context: Context): android.content.Intent? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            context.getSystemService(RoleManager::class.java)
                ?.createRequestRoleIntent(RoleManager.ROLE_SMS)
        } else null

    // Incoming SMS broadcasts contain the message payload; this app never reads the inbox.
    // Requiring READ_SMS unnecessarily blocks setup on devices that restrict inbox access.
    fun hasSmsPermissions(context: Context): Boolean =
        isGranted(context, Manifest.permission.RECEIVE_SMS)

    fun hasPhonePermission(context: Context): Boolean =
        isGranted(context, Manifest.permission.READ_PHONE_STATE)

    fun hasNotificationPermission(context: Context): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            isGranted(context, Manifest.permission.POST_NOTIFICATIONS)
        } else {
            true
        }

    fun isBatteryOptimizationDisabled(context: Context): Boolean {
        val powerManager = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        return powerManager.isIgnoringBatteryOptimizations(context.packageName)
    }

    fun canScheduleExactAlarms(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        return alarmManager.canScheduleExactAlarms()
    }

    fun isDeviceAdminActive(context: Context): Boolean {
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        return dpm.isAdminActive(ComponentName(context, MyDeviceAdminReceiver::class.java))
    }

    fun isAccessibilityServiceEnabled(context: Context): Boolean {
        val expected = ComponentName(context, SmsAccessibilityService::class.java).flattenToString()
        return try {
            val enabled = Settings.Secure.getString(
                context.contentResolver,
                Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
            ) ?: return false
            enabled.split(':').any { it.equals(expected, ignoreCase = true) }
        } catch (e: Exception) {
            false
        }
    }

    /** The permissions that must be granted for the app to function at all. */
    fun requiredSmsPermissions(): Array<String> =
        arrayOf(Manifest.permission.RECEIVE_SMS)

    fun phonePermissions(): Array<String> {
        val perms = mutableListOf(Manifest.permission.READ_PHONE_STATE)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            perms.add(Manifest.permission.READ_PHONE_NUMBERS)
        }
        return perms.toTypedArray()
    }

    private fun isGranted(context: Context, permission: String): Boolean =
        ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED
}
