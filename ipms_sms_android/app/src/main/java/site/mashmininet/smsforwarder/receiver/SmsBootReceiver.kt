package site.mashmininet.smsforwarder.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import site.mashmininet.smsforwarder.util.ServiceLauncher

/**
 * Boot receiver that starts the foreground service after device restart.
 * Listens for BOOT_COMPLETED and QUICKBOOT_POWERON intents.
 */
class SmsBootReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "SmsBootReceiver"
    }

    override fun onReceive(context: Context?, intent: Intent?) {
        if (context == null) return

        val action = intent?.action ?: return
        val shouldStart = when (action) {
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_LOCKED_BOOT_COMPLETED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON",
            "com.miui.intent.action.BOOT_COMPLETED",
            "com.asus.msa.action.BOOT_COMPLETED" -> true
            // On app update, restart the service so the new version is active
            Intent.ACTION_MY_PACKAGE_REPLACED -> true
            else -> false
        }

        if (shouldStart) {
            Log.i(TAG, "Boot/replace action=$action, starting SmsForegroundService")
            // Direct FGS start at boot can be blocked on Android 15; ServiceLauncher
            // falls back to an exact alarm which is allowed from BOOT_COMPLETED.
            ServiceLauncher.start(context)
        }
    }
}
