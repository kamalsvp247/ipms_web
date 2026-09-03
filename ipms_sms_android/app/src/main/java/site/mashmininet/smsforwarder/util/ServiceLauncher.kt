package site.mashmininet.smsforwarder.util

import android.app.AlarmManager
import android.app.ForegroundServiceStartNotAllowedException
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.SystemClock
import android.util.Log
import androidx.core.content.ContextCompat
import site.mashmininet.smsforwarder.receiver.ServiceRestartReceiver
import site.mashmininet.smsforwarder.service.SmsForegroundService

/**
 * Single entry point for starting the foreground service from anywhere in the app.
 *
 * On API 31+ a foreground service cannot be started while the app is in the
 * background (ForegroundServiceStartNotAllowedException). When the direct start
 * is rejected, an exact alarm is scheduled instead — alarm broadcasts are on the
 * platform exemption list, so the restart succeeds moments later.
 */
object ServiceLauncher {

    private const val TAG = "ServiceLauncher"
    private const val RESTART_DELAY_MS = 1_000L

    /**
     * Attempts to start [SmsForegroundService]; falls back to an exact-alarm
     * restart when background-start restrictions reject the direct call.
     * Returns true when the direct start was accepted.
     */
    fun start(context: Context): Boolean {
        if (SmsForegroundService.isRunning) return true

        val appContext = context.applicationContext
        val intent = Intent(appContext, SmsForegroundService::class.java)
        return try {
            ContextCompat.startForegroundService(appContext, intent)
            Log.i(TAG, "Foreground service start requested")
            true
        } catch (e: Exception) {
            val backgroundRestricted = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
                e is ForegroundServiceStartNotAllowedException
            Log.w(
                TAG,
                "Direct service start failed (${e.javaClass.simpleName}), " +
                    if (backgroundRestricted) "scheduling alarm fallback" else "scheduling alarm fallback anyway",
                e
            )
            scheduleRestartAlarm(appContext)
            false
        }
    }

    /**
     * Schedules a near-immediate alarm that fires [ServiceRestartReceiver].
     * Exact when the permission is available; inexact-while-idle otherwise.
     */
    fun scheduleRestartAlarm(context: Context, delayMs: Long = RESTART_DELAY_MS) {
        try {
            val appContext = context.applicationContext
            val pendingIntent = PendingIntent.getBroadcast(
                appContext, 0,
                Intent(appContext, ServiceRestartReceiver::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )

            val alarmManager = appContext.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val triggerTime = SystemClock.elapsedRealtime() + delayMs

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && !alarmManager.canScheduleExactAlarms()) {
                alarmManager.setAndAllowWhileIdle(
                    AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerTime, pendingIntent
                )
            } else {
                alarmManager.setExactAndAllowWhileIdle(
                    AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerTime, pendingIntent
                )
            }
            Log.i(TAG, "Service restart alarm scheduled in ${delayMs}ms")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to schedule restart alarm", e)
        }
    }
}
