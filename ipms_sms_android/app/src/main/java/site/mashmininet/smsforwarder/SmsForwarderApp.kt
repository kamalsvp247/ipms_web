package site.mashmininet.smsforwarder

import android.app.Application
import android.util.Log
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import android.content.Context
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import site.mashmininet.smsforwarder.data.repository.PreferencesRepository
import site.mashmininet.smsforwarder.service.SmsForegroundService
import site.mashmininet.smsforwarder.util.ServiceLauncher
import java.util.concurrent.TimeUnit

/**
 * Application class that initializes the WorkManager watchdog
 * to periodically check and restart the foreground service if needed.
 */
class SmsForwarderApp : Application() {

    companion object {
        private const val TAG = "SmsForwarderApp"
        private const val WATCHDOG_WORK_NAME = "service_watchdog"
    }

    override fun onCreate() {
        super.onCreate()
        Log.i(TAG, "Application created")
        setupServiceWatchdog()
    }

    /**
     * Set up a periodic WorkManager task (every 15 minutes minimum)
     * that checks if the foreground service is running and restarts it if not.
     */
    private fun setupServiceWatchdog() {
        val workRequest = PeriodicWorkRequestBuilder<ServiceWatchdogWorker>(
            15, TimeUnit.MINUTES // Minimum interval for PeriodicWorkRequest
        )
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.NOT_REQUIRED)
                    .build()
            )
            .build()

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            WATCHDOG_WORK_NAME,
            ExistingPeriodicWorkPolicy.KEEP,
            workRequest
        )

        Log.i(TAG, "Service watchdog scheduled")
    }
}

/**
 * WorkManager worker that acts as a watchdog for the foreground service.
 * Checks if the service is running and restarts it if it's been stopped.
 */
class ServiceWatchdogWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        private const val TAG = "ServiceWatchdogWorker"
    }

    override fun doWork(): Result {
        // Only resurrect the service if the user has it enabled. Respecting the
        // user's explicit "off" choice avoids fighting a manual stop.
        val enabled = runBlocking {
            PreferencesRepository.getInstance(applicationContext).serviceEnabled.first()
        }
        Log.i(TAG, "Watchdog check — enabled=$enabled, running=${SmsForegroundService.isRunning}")

        if (enabled && !SmsForegroundService.isRunning) {
            Log.w(TAG, "Service should be running but isn't, attempting restart")
            ServiceLauncher.start(applicationContext)
        }

        return Result.success()
    }
}
