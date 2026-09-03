package site.mashmininet.smsforwarder.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import android.util.Log
import site.mashmininet.smsforwarder.data.repository.SmsRepository
import site.mashmininet.smsforwarder.util.ServiceLauncher
import site.mashmininet.smsforwarder.util.SimSlotResolver

/**
 * Static broadcast receiver for SMS_RECEIVED with highest priority (999).
 * Registered in AndroidManifest for reliable SMS interception even when
 * the app process is not in memory.
 *
 * On receipt, enqueues a WorkManager job for guaranteed delivery (persists
 * across process death) and ensures the foreground service is running.
 * Long-running work is NOT done here to avoid exceeding broadcast limits.
 */
class SmsReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "SmsReceiver"
    }

    override fun onReceive(context: Context?, intent: Intent?) {
        if (context == null || intent == null) return
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (messages.isNullOrEmpty()) {
            Log.w(TAG, "No messages found in intent")
            return
        }

        val sender = messages[0].displayOriginatingAddress ?: "Unknown"
        val body = messages.joinToString("") { it.displayMessageBody ?: "" }
        val timestamp = messages[0].timestampMillis
        val simSlot = SimSlotResolver.fromIntent(context, intent)
        val smsId = timestamp.toString()

        Log.i(TAG, "SMS received — from=$sender, simSlot=$simSlot, bodyLength=${body.length}")

        // Enqueue a WorkManager job for guaranteed, network-aware delivery.
        // Uses ExistingWorkPolicy.KEEP so duplicate triggers for the same SMS are ignored.
        SmsRepository.getInstance(context.applicationContext)
            .enqueueForwardWork(smsId = smsId, simSlot = simSlot, body = body)

        // Ensure the foreground service is running so the dynamic receiver is also active.
        // On API 31+ a direct start from here can be rejected; ServiceLauncher falls back
        // to an exact alarm which is exempt from background-start restrictions.
        ServiceLauncher.start(context)
        // onReceive returns immediately — WorkManager handles all async/retry work.
    }
}
