package site.mashmininet.smsforwarder.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import site.mashmininet.smsforwarder.service.SmsForegroundService
import site.mashmininet.smsforwarder.util.ServiceLauncher

/**
 * Receiver that restarts the foreground service when triggered by AlarmManager.
 * Used in the service's onTaskRemoved() to ensure service persistence.
 *
 * Alarm broadcasts are exempt from background FGS-start restrictions, so a direct
 * start from here is allowed even when the app is otherwise in the background.
 */
class ServiceRestartReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "ServiceRestartReceiver"
    }

    override fun onReceive(context: Context?, intent: Intent?) {
        if (context == null) return

        Log.i(TAG, "Service restart triggered via AlarmManager")

        if (!SmsForegroundService.isRunning) {
            ServiceLauncher.start(context)
        } else {
            Log.i(TAG, "SmsForegroundService is already running, no restart needed")
        }
    }
}
