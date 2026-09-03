package site.mashmininet.smsforwarder.worker

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import site.mashmininet.smsforwarder.data.model.ForwardStatus
import site.mashmininet.smsforwarder.data.model.SmsMessage
import site.mashmininet.smsforwarder.data.repository.PreferencesRepository
import site.mashmininet.smsforwarder.data.repository.SmsRepository

/**
 * WorkManager worker that forwards an SMS to the IPMS portal.
 * Persists across process death and retries with exponential backoff
 * when the network is unavailable or the server returns a transient error.
 *
 * Routes through SmsRepository.forwardSms() to share the inProgressIds dedup
 * lock with the dynamic receiver path, preventing the same SMS being forwarded
 * twice when both paths fire for the same message.
 *
 * Input data:
 *   KEY_SIM_SLOT — Int (0=SIM1, 1=SIM2); the phone number is looked up at run time
 *   KEY_BODY     — String, raw SMS body
 *   KEY_SMS_ID   — String, unique ID for deduplication (timestamp as string)
 */
class SmsForwardWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val TAG = "SmsForwardWorker"
        const val KEY_SIM_SLOT = "sim_slot"
        const val KEY_BODY = "body"
        const val KEY_SMS_ID = "sms_id"
    }

    override suspend fun doWork(): Result {
        val simSlot = inputData.getInt(KEY_SIM_SLOT, 0)
        val body = inputData.getString(KEY_BODY) ?: run {
            Log.e(TAG, "Missing SMS body in work data — cannot forward")
            return Result.failure()
        }
        val smsId = inputData.getString(KEY_SMS_ID) ?: run {
            Log.e(TAG, "Missing SMS ID in work data — cannot forward")
            return Result.failure()
        }

        val repo = SmsRepository.getInstance(applicationContext)

        // Skip if the direct-call path already delivered this SMS.
        if (repo.getForwardStatus(smsId) == ForwardStatus.DELIVERED) {
            Log.i(TAG, "SMS $smsId already delivered by in-process path, skipping WorkManager")
            return Result.success()
        }

        val prefsRepo = PreferencesRepository.getInstance(applicationContext)
        val phone = when (simSlot) {
            0 -> prefsRepo.getSim1NumberSync()
            1 -> prefsRepo.getSim2NumberSync()
            else -> ""
        }

        if (phone.isBlank()) {
            Log.w(TAG, "No phone number configured for SIM slot $simSlot — marking failed")
            repo.updateForwardStatus(smsId, ForwardStatus.FAILED)
            return Result.failure()
        }

        Log.i(TAG, "WorkManager attempt ${runAttemptCount + 1} — smsId=$smsId, phone=$phone")

        // Route through forwardSms() to share the inProgressIds dedup lock with the
        // dynamic receiver path. If the dynamic receiver is actively forwarding this
        // SMS, forwardSms() returns false immediately (inProgressIds blocks it).
        val smsMessage = SmsMessage(
            id = smsId.toLongOrNull() ?: 0L,
            sender = "",
            body = body,
            timestamp = smsId.toLongOrNull() ?: 0L,
            simSlot = simSlot,
            forwardStatus = ForwardStatus.PENDING
        )

        val success = repo.forwardSms(smsMessage)
        return when {
            success -> Result.success()
            // Check status: if the in-process path delivered it while we were dedup'd, that's fine.
            repo.getForwardStatus(smsId) == ForwardStatus.DELIVERED -> {
                Log.i(TAG, "SMS $smsId delivered by concurrent in-process path")
                Result.success()
            }
            // Still pending or failed — let WorkManager retry with network backoff.
            else -> {
                Log.w(TAG, "forwardSms returned false for smsId=$smsId, scheduling WorkManager retry")
                Result.retry()
            }
        }
    }
}
