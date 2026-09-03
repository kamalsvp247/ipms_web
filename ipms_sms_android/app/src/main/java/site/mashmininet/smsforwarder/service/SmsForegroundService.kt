package site.mashmininet.smsforwarder.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.provider.Telephony
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import site.mashmininet.smsforwarder.MainActivity
import site.mashmininet.smsforwarder.R
import site.mashmininet.smsforwarder.data.model.ForwardStatus
import site.mashmininet.smsforwarder.data.repository.SmsRepository
import site.mashmininet.smsforwarder.util.ServiceLauncher
import site.mashmininet.smsforwarder.util.SimSlotResolver

/**
 * Foreground service that keeps the SMS forwarder alive.
 * Uses START_STICKY, WakeLock, dynamic BroadcastReceiver, and
 * AlarmManager restart on task removal for "never kill" strategy.
 */
class SmsForegroundService : Service() {

    companion object {
        private const val TAG = "SmsForegroundService"
        private const val CHANNEL_ID = "sms_forwarder_service_channel"
        private const val NOTIFICATION_ID = 1001
        private const val WAKELOCK_TAG = "SMSForwarder::ServiceWakeLock"

        @Volatile
        var isRunning: Boolean = false
            private set
    }

    private val serviceJob = SupervisorJob()
    private val serviceScope = CoroutineScope(Dispatchers.IO + serviceJob)
    private var wakeLock: PowerManager.WakeLock? = null
    private var smsReceiver: BroadcastReceiver? = null
    private lateinit var smsRepository: SmsRepository

    override fun onCreate() {
        super.onCreate()
        isRunning = true
        smsRepository = SmsRepository.getInstance(applicationContext)
        createNotificationChannel()
        // specialUse on 14+ — exempt from the Android 15 dataSync boot-start
        // restriction and from the dataSync 6-hour runtime cap.
        val foregroundServiceType = when {
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE ->
                ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q ->
                ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            else -> 0
        }
        ServiceCompat.startForeground(this, NOTIFICATION_ID, createNotification(), foregroundServiceType)
        acquireWakeLock()
        registerDynamicSmsReceiver()
        Log.i(TAG, "SmsForegroundService created and started")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        isRunning = true
        Log.i(TAG, "onStartCommand called with flags=$flags, startId=$startId")
        return START_STICKY // Android will automatically restart this service if killed
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        isRunning = false
        releaseWakeLock()
        unregisterDynamicSmsReceiver()
        serviceJob.cancel()
        Log.i(TAG, "SmsForegroundService destroyed")
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        super.onTaskRemoved(rootIntent)
        Log.w(TAG, "Task removed, scheduling service restart via AlarmManager")
        ServiceLauncher.scheduleRestartAlarm(this)
    }

    // ── Notification ──

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "SMS Forwarder Service",
                NotificationManager.IMPORTANCE_LOW // Silent but persistent
            ).apply {
                description = "Keeps the SMS forwarding service running in the background"
                setShowBadge(false)
            }

            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(channel)
        }
    }

    private fun createNotification(): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("SMS Forwarder active")
            .setContentText("Monitoring incoming SMS and forwarding in real time")
            .setSmallIcon(R.drawable.ic_stat_blitz)
            .setContentIntent(pendingIntent)
            .setOngoing(true) // Non-dismissible
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .build()
    }

    // ── WakeLock ──

    private fun acquireWakeLock() {
        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = powerManager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            WAKELOCK_TAG
        ).apply {
            acquire() // Indefinite — released in onDestroy() or releaseWakeLock()
        }
        Log.i(TAG, "WakeLock acquired")
    }

    private fun releaseWakeLock() {
        try {
            wakeLock?.let {
                if (it.isHeld) {
                    it.release()
                    Log.i(TAG, "WakeLock released")
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error releasing WakeLock", e)
        }
        wakeLock = null
    }

    // ── Dynamic SMS Receiver (Fallback) ──

    private fun registerDynamicSmsReceiver() {
        smsReceiver = object : BroadcastReceiver() {
            override fun onReceive(context: Context?, intent: Intent?) {
                if (intent?.action == Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
                    Log.i(TAG, "SMS received via dynamic receiver (fallback)")
                    handleIncomingSms(intent)
                }
            }
        }

        val filter = IntentFilter(Telephony.Sms.Intents.SMS_RECEIVED_ACTION).apply {
            priority = 998 // Slightly lower than manifest receiver
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(smsReceiver, filter, RECEIVER_NOT_EXPORTED)
        } else {
            registerReceiver(smsReceiver, filter)
        }
        Log.i(TAG, "Dynamic SMS receiver registered")
    }

    private fun unregisterDynamicSmsReceiver() {
        try {
            smsReceiver?.let { unregisterReceiver(it) }
        } catch (e: Exception) {
            Log.e(TAG, "Error unregistering dynamic SMS receiver", e)
        }
        smsReceiver = null
    }

    private fun handleIncomingSms(intent: Intent) {
        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (messages.isNullOrEmpty()) return

        val sender = messages[0].displayOriginatingAddress ?: "Unknown"
        val body = messages.joinToString("") { it.displayMessageBody ?: "" }
        val timestamp = messages[0].timestampMillis
        val simSlot = SimSlotResolver.fromIntent(this, intent)

        val smsMessage = site.mashmininet.smsforwarder.data.model.SmsMessage(
            id = timestamp, // Use timestamp as temporary ID
            sender = sender,
            body = body,
            timestamp = timestamp,
            simSlot = simSlot,
            forwardStatus = ForwardStatus.PENDING
        )

        serviceScope.launch {
            smsRepository.forwardSms(smsMessage)
        }
    }

}
