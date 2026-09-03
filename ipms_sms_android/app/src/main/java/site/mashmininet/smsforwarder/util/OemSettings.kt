package site.mashmininet.smsforwarder.util

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import java.util.Locale

/**
 * Resolves the best OEM-specific autostart / power-manager settings screen for the
 * current device. Aggressive OEM battery managers (Xiaomi, OPPO, Vivo, etc.) kill
 * background services unless the app is explicitly whitelisted in these screens.
 */
object OemSettings {

    private const val TAG = "OemSettings"

    fun hasKnownOem(context: Context): Boolean = candidates(context).isNotEmpty()

    /** First OEM component that resolves on this device, or null if none apply. */
    fun resolveIntent(context: Context): Intent? {
        val pm = context.packageManager
        for (component in candidates(context)) {
            try {
                val intent = Intent().apply {
                    this.component = component
                    putExtra("package_name", context.packageName)
                    putExtra("package_label", context.applicationInfo.loadLabel(pm).toString())
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }
                if (pm.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY) != null) {
                    Log.i(TAG, "OEM settings resolved: ${component.flattenToString()}")
                    return intent
                }
            } catch (e: Exception) {
                Log.d(TAG, "OEM component not found: ${component.flattenToString()}")
            }
        }
        return null
    }

    private fun candidates(context: Context): List<ComponentName> {
        val manufacturer = Build.MANUFACTURER.lowercase(Locale.ROOT)
        return when {
            manufacturer.contains("xiaomi") || manufacturer.contains("redmi") || manufacturer.contains("poco") -> listOf(
                ComponentName("com.miui.powerkeeper", "com.miui.powerkeeper.ui.HiddenAppsConfigActivity"),
                ComponentName("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"),
                ComponentName("com.miui.securitycenter", "com.miui.securitycenter.MainActivity")
            )
            manufacturer.contains("oppo") -> listOf(
                ComponentName("com.coloros.oppoguardelf", "com.coloros.powermanager.fuelgaue.PowerUsageModelActivity"),
                ComponentName("com.coloros.safecenter", "com.coloros.safecenter.permission.startup.StartupAppListActivity"),
                ComponentName("com.oppo.safe", "com.oppo.safe.permission.startup.StartupAppListActivity"),
                ComponentName("com.coloros.healthcheck", "com.coloros.healthcheck.ui.main.BootAutoStartManagerActivity")
            )
            manufacturer.contains("realme") -> listOf(
                ComponentName("com.coloros.oppoguardelf", "com.coloros.powermanager.fuelgaue.PowerUsageModelActivity"),
                ComponentName("com.coloros.safecenter", "com.coloros.safecenter.permission.startup.StartupAppListActivity"),
                ComponentName("com.realme.powersaver", "com.realme.powersaver.ui.HiddenAppsConfigActivity")
            )
            manufacturer.contains("vivo") || manufacturer.contains("iqoo") -> listOf(
                ComponentName("com.vivo.abe", "com.vivo.applicationbehaviorengine.ui.ExcessivePowerManagerActivity"),
                ComponentName("com.iqoo.secure", "com.iqoo.secure.safeguard.PurviewTabActivity"),
                ComponentName("com.vivo.permissionmanager", "com.vivo.permissionmanager.activity.BgStartUpManagerActivity")
            )
            manufacturer.contains("huawei") || manufacturer.contains("honor") -> listOf(
                ComponentName("com.huawei.systemmanager", "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity"),
                ComponentName("com.huawei.systemmanager", "com.huawei.systemmanager.optimize.process.ProtectActivity"),
                ComponentName("com.huawei.systemmanager", "com.huawei.systemmanager.appcontrol.activity.StartupAppControlActivity")
            )
            manufacturer.contains("samsung") -> listOf(
                ComponentName("com.samsung.android.lool", "com.samsung.android.sm.battery.ui.BatteryActivity"),
                ComponentName("com.samsung.android.sm.battery", "com.samsung.android.sm.battery.ui.BatteryActivity"),
                ComponentName("com.samsung.android.lool", "com.samsung.android.sm.ui.battery.BatteryActivity")
            )
            manufacturer.contains("oneplus") -> listOf(
                ComponentName("com.oneplus.security", "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity")
            )
            manufacturer.contains("asus") -> listOf(
                ComponentName("com.asus.mobilemanager", "com.asus.mobilemanager.autostart.AutoStartActivity"),
                ComponentName("com.asus.mobilemanager", "com.asus.mobilemanager.MainActivity")
            )
            manufacturer.contains("meizu") -> listOf(
                ComponentName("com.meizu.safe", "com.meizu.safe.permission.SmartPermissionActivity")
            )
            else -> emptyList()
        }
    }
}
