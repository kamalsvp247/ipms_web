package site.mashmininet.smsforwarder.data.repository

import android.annotation.SuppressLint
import android.content.Context
import android.database.ContentObserver
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Telephony
import android.telephony.SubscriptionManager
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.channelFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import site.mashmininet.smsforwarder.data.api.RetrofitClient
import site.mashmininet.smsforwarder.data.model.ForwardStatus
import site.mashmininet.smsforwarder.data.model.SmsForwardRequest
import site.mashmininet.smsforwarder.data.model.SmsMessage
import site.mashmininet.smsforwarder.worker.SmsForwardWorker
import java.io.IOException
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

/**
 * Repository responsible for reading SMS messages from the device inbox,
 * tracking forward status, and handling the API forwarding logic with retries.
 */
class SmsRepository(private val context: Context) {

    companion object {
        private const val TAG = "SmsRepository"
        private const val MAX_RETRIES = 3
        private const val MAX_LOG_ENTRIES = 500
        private const val OBSERVER_DEBOUNCE_MS = 300L
        private val SMS_INBOX_URI: Uri = Uri.parse("content://sms/inbox")

        @Volatile
        private var instance: SmsRepository? = null

        fun getInstance(context: Context): SmsRepository {
            return instance ?: synchronized(this) {
                instance ?: SmsRepository(context.applicationContext).also { instance = it }
            }
        }
    }

    private val preferencesRepository = PreferencesRepository.getInstance(context)
    private val gson = Gson()
    private val persistScope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    /** Tracks SMS IDs currently being forwarded to prevent duplicate concurrent requests. */
    private val inProgressIds: ConcurrentHashMap.KeySetView<String, Boolean> = ConcurrentHashMap.newKeySet()

    // ── Subscription ID → SIM slot cache ──
    // At most 2 SIMs, so at most 2 IPC calls ever — all subsequent lookups are from this map.
    private val subIdToSlotCache = ConcurrentHashMap<Int, Int>()

    // ── In-memory forward status cache ──
    // Key: SMS timestamp string (matches the key used in SmsReceiver / forwardSms).
    // Loaded from disk once on first use, then kept in sync with all updates.
    @Volatile
    private var statusCache: ConcurrentHashMap<String, ForwardStatus>? = null
    private val cacheMutex = Mutex()

    // ── Hidden SMS IDs cache ──
    // IDs added here are excluded from getSmsMessages() — persisted across restarts.
    @Volatile
    private var hiddenIds: Set<Long>? = null
    private val hiddenIdsMutex = Mutex()

    // Emits Unit whenever a forward status changes so observeSmsChanges() can push a fresh list
    // to the UI without waiting for the next ContentObserver event.
    private val statusChangeTrigger = MutableSharedFlow<Unit>(extraBufferCapacity = 8)

    // ── SMS Reading ──

    suspend fun getSmsMessages(): List<SmsMessage> = withContext(Dispatchers.IO) {
        val statuses = getStatusCache()
        val hidden = getHiddenIds()
        val messages = mutableListOf<SmsMessage>()

        try {
            val cursor = context.contentResolver.query(
                SMS_INBOX_URI,
                arrayOf(
                    Telephony.Sms._ID,
                    Telephony.Sms.ADDRESS,
                    Telephony.Sms.BODY,
                    Telephony.Sms.DATE,
                    Telephony.Sms.DATE_SENT,
                    Telephony.Sms.SUBSCRIPTION_ID
                ),
                null,
                null,
                "${Telephony.Sms.DATE} DESC"
            )

            cursor?.use {
                val idIndex = it.getColumnIndexOrThrow(Telephony.Sms._ID)
                val addressIndex = it.getColumnIndexOrThrow(Telephony.Sms.ADDRESS)
                val bodyIndex = it.getColumnIndexOrThrow(Telephony.Sms.BODY)
                val dateIndex = it.getColumnIndexOrThrow(Telephony.Sms.DATE)
                val dateSentIndex = it.getColumnIndex(Telephony.Sms.DATE_SENT)
                val subIdIndex = it.getColumnIndex(Telephony.Sms.SUBSCRIPTION_ID)

                while (it.moveToNext()) {
                    val id = it.getLong(idIndex)
                    if (id in hidden) continue

                    val sender = it.getString(addressIndex) ?: "Unknown"
                    val body = it.getString(bodyIndex) ?: ""
                    val timestamp = it.getLong(dateIndex)
                    // DATE_SENT stores the PDU/SMSC timestamp, which matches
                    // SmsMessage.getTimestampMillis() from the broadcast — the key used
                    // in forwardSms() and SmsReceiver. DATE is the device receipt time
                    // (System.currentTimeMillis()) and never matches.
                    val dateSent = if (dateSentIndex >= 0) it.getLong(dateSentIndex) else 0L
                    val subId = if (subIdIndex >= 0) it.getInt(subIdIndex) else -1
                    val simSlot = subscriptionIdToSlot(subId)

                    // Prefer DATE_SENT key; fall back to DATE for resilience.
                    val status = statuses[dateSent.toString()]
                        ?: statuses[timestamp.toString()]
                        ?: ForwardStatus.PENDING

                    messages.add(
                        SmsMessage(
                            id = id,
                            sender = sender,
                            body = body,
                            timestamp = timestamp,
                            simSlot = simSlot,
                            forwardStatus = status
                        )
                    )
                }
            }
        } catch (e: SecurityException) {
            Log.e(TAG, "SMS read permission not granted", e)
        } catch (e: Exception) {
            Log.e(TAG, "Error reading SMS messages", e)
        }

        messages
    }

    /**
     * Observes SMS inbox changes and forward-status changes.
     * Debounces rapid ContentObserver events; also reacts immediately to status updates so the
     * list refreshes to Delivered/Failed without waiting for the next inbox change.
     */
    fun observeSmsChanges(): Flow<List<SmsMessage>> = channelFlow {
        val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())
        var debounceJob: Job? = null

        val observer = object : ContentObserver(Handler(Looper.getMainLooper())) {
            override fun onChange(selfChange: Boolean) {
                debounceJob?.cancel()
                debounceJob = scope.launch {
                    delay(OBSERVER_DEBOUNCE_MS)
                    send(getSmsMessages())
                }
            }
        }

        context.contentResolver.registerContentObserver(SMS_INBOX_URI, true, observer)

        // React to status updates (Delivered / Failed) so the badge updates immediately
        // even when the SMS inbox itself has not changed.
        scope.launch {
            statusChangeTrigger.collect { send(getSmsMessages()) }
        }

        send(getSmsMessages())

        awaitClose {
            context.contentResolver.unregisterContentObserver(observer)
            scope.cancel()
        }
    }

    // ── SMS Forwarding ──

    /**
     * Forward an SMS to the IPMS portal with in-process retry logic.
     * Uses a dedup lock so simultaneous calls for the same SMS ID are suppressed.
     */
    suspend fun forwardSms(smsMessage: SmsMessage): Boolean = withContext(Dispatchers.IO) {
        // Key = SmsMessage.id, which SmsForegroundService sets to timestampMillis (PDU time).
        // This matches DATE_SENT in the inbox — the key getSmsMessages() uses for status lookup.
        val smsId = smsMessage.id.toString()

        if (!inProgressIds.add(smsId)) {
            Log.i(TAG, "SMS $smsId already in progress, skipping duplicate call")
            return@withContext false
        }

        try {
            val simNumber = when (smsMessage.simSlot) {
                0 -> preferencesRepository.getSim1NumberSync()
                1 -> preferencesRepository.getSim2NumberSync()
                else -> ""
            }

            if (simNumber.isBlank()) {
                Log.w(TAG, "SIM number for slot ${smsMessage.simSlot} not configured, skipping forward")
                updateForwardStatus(smsId, ForwardStatus.FAILED)
                return@withContext false
            }

            val request = SmsForwardRequest(phone = simNumber, msg = smsMessage.body)
            var lastException: Exception? = null

            for (attempt in 1..MAX_RETRIES) {
                try {
                    val response = RetrofitClient.apiService.forwardOtp(request)
                    when {
                        response.isSuccessful -> {
                            updateForwardStatus(smsId, ForwardStatus.DELIVERED)
                            Log.i(TAG, "Forward succeeded for smsId=$smsId on attempt $attempt")
                            return@withContext true
                        }
                        response.code() in 400..499 && response.code() != 429 -> {
                            Log.w(TAG, "Permanent failure (${response.code()}) for smsId=$smsId, not retrying")
                            updateForwardStatus(smsId, ForwardStatus.FAILED)
                            return@withContext false
                        }
                        else -> {
                            Log.w(TAG, "Transient failure (${response.code()}) for smsId=$smsId, attempt $attempt")
                        }
                    }
                } catch (e: IOException) {
                    lastException = e
                    Log.w(TAG, "Network error for smsId=$smsId, attempt $attempt", e)
                } catch (e: Exception) {
                    lastException = e
                    Log.e(TAG, "Unexpected error for smsId=$smsId, attempt $attempt", e)
                }

                if (attempt < MAX_RETRIES) {
                    delay(1000L * (1 shl (attempt - 1)))
                }
            }

            Log.e(TAG, "All $MAX_RETRIES in-process attempts failed for smsId=$smsId", lastException)
            updateForwardStatus(smsId, ForwardStatus.FAILED)
            false
        } finally {
            inProgressIds.remove(smsId)
        }
    }

    /**
     * Manually (re)forwards a message already shown in the list — used by the
     * "Send now" / "Retry" action in the detail sheet. Status is keyed by the
     * message timestamp so it matches what getSmsMessages() looks up. Returns the
     * resulting status so the UI can reflect it immediately.
     */
    suspend fun sendNow(message: SmsMessage): ForwardStatus = withContext(Dispatchers.IO) {
        val statusKey = message.timestamp.toString()
        val phone = when (message.simSlot) {
            0 -> preferencesRepository.getSim1NumberSync()
            1 -> preferencesRepository.getSim2NumberSync()
            else -> ""
        }
        if (phone.isBlank()) {
            Log.w(TAG, "sendNow: no number configured for slot ${message.simSlot}")
            updateForwardStatus(statusKey, ForwardStatus.FAILED)
            return@withContext ForwardStatus.FAILED
        }

        updateForwardStatus(statusKey, ForwardStatus.PENDING)
        val request = SmsForwardRequest(phone = phone, msg = message.body)

        for (attempt in 1..MAX_RETRIES) {
            try {
                val response = RetrofitClient.apiService.forwardOtp(request)
                when {
                    response.isSuccessful -> {
                        updateForwardStatus(statusKey, ForwardStatus.DELIVERED)
                        return@withContext ForwardStatus.DELIVERED
                    }
                    response.code() in 400..499 && response.code() != 429 -> {
                        updateForwardStatus(statusKey, ForwardStatus.FAILED)
                        return@withContext ForwardStatus.FAILED
                    }
                    else -> Log.w(TAG, "sendNow transient ${response.code()} attempt $attempt")
                }
            } catch (e: IOException) {
                Log.w(TAG, "sendNow network error attempt $attempt", e)
            } catch (e: Exception) {
                Log.e(TAG, "sendNow unexpected error", e)
                updateForwardStatus(statusKey, ForwardStatus.FAILED)
                return@withContext ForwardStatus.FAILED
            }
            if (attempt < MAX_RETRIES) {
                delay(1000L * (1 shl (attempt - 1)))
            }
        }

        updateForwardStatus(statusKey, ForwardStatus.FAILED)
        ForwardStatus.FAILED
    }

    /**
     * Enqueues a WorkManager job for guaranteed, network-aware SMS delivery.
     */
    fun enqueueForwardWork(smsId: String, simSlot: Int, body: String) {
        val workRequest = OneTimeWorkRequestBuilder<SmsForwardWorker>()
            .setInputData(
                workDataOf(
                    SmsForwardWorker.KEY_SMS_ID to smsId,
                    SmsForwardWorker.KEY_SIM_SLOT to simSlot,
                    SmsForwardWorker.KEY_BODY to body
                )
            )
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .setBackoffCriteria(
                BackoffPolicy.EXPONENTIAL,
                30_000L,
                TimeUnit.MILLISECONDS
            )
            .build()

        WorkManager.getInstance(context).enqueueUniqueWork(
            "sms_forward_$smsId",
            ExistingWorkPolicy.KEEP,
            workRequest
        )
        Log.i(TAG, "WorkManager job enqueued for smsId=$smsId (simSlot=$simSlot)")
    }

    // ── Forward Status Tracking ──

    suspend fun getForwardStatus(smsId: String): ForwardStatus? {
        return getStatusCache()[smsId]
    }

    /**
     * Updates the in-memory cache immediately, persists to DataStore in the background,
     * and triggers a UI refresh so the list badge updates without waiting for an inbox change.
     */
    suspend fun updateForwardStatus(smsId: String, status: ForwardStatus) {
        val cache = getStatusCache()
        cache[smsId] = status
        statusChangeTrigger.tryEmit(Unit)
        persistScope.launch { persistStatusCache(cache) }
    }

    suspend fun clearForwardLog() {
        cacheMutex.withLock { statusCache = ConcurrentHashMap() }
        preferencesRepository.saveForwardLog("")
        statusChangeTrigger.tryEmit(Unit)
    }

    /**
     * Hides the given SMS IDs from the messages list by adding them to the persisted hidden set.
     * The messages remain in the device inbox but are excluded from getSmsMessages().
     */
    suspend fun deleteMessages(ids: Set<Long>) {
        val updated = getHiddenIds().toMutableSet().also { it.addAll(ids) }
        hiddenIdsMutex.withLock { hiddenIds = updated }
        statusChangeTrigger.tryEmit(Unit)
        persistScope.launch {
            val json = gson.toJson(updated.toList())
            preferencesRepository.saveHiddenSmsIds(json)
        }
    }

    /** Clears in-memory hidden IDs cache — call after DataStore reset. */
    fun resetAll() {
        statusCache = null
        hiddenIds = null
        statusChangeTrigger.tryEmit(Unit)
    }

    // ── Internal helpers ──

    private suspend fun getStatusCache(): ConcurrentHashMap<String, ForwardStatus> {
        statusCache?.let { return it }
        return cacheMutex.withLock {
            statusCache ?: run {
                val loaded = loadStatusesFromDisk()
                ConcurrentHashMap(loaded).also { statusCache = it }
            }
        }
    }

    private suspend fun loadStatusesFromDisk(): Map<String, ForwardStatus> {
        val json = preferencesRepository.forwardLog.first()
        if (json.isBlank()) return emptyMap()
        return try {
            val type = object : TypeToken<Map<String, String>>() {}.type
            val map: Map<String, String> = gson.fromJson(json, type)
            map.mapValues { ForwardStatus.valueOf(it.value) }
        } catch (e: Exception) {
            Log.e(TAG, "Error parsing forward statuses from disk", e)
            emptyMap()
        }
    }

    private suspend fun persistStatusCache(cache: ConcurrentHashMap<String, ForwardStatus>) {
        val trimmed = if (cache.size > MAX_LOG_ENTRIES) {
            cache.entries
                .sortedByDescending { it.key.toLongOrNull() ?: 0L }
                .take(MAX_LOG_ENTRIES)
                .associate { it.toPair() }
        } else {
            cache
        }
        val json = gson.toJson(trimmed.mapValues { it.value.name })
        preferencesRepository.saveForwardLog(json)
    }

    private suspend fun getHiddenIds(): Set<Long> {
        hiddenIds?.let { return it }
        return hiddenIdsMutex.withLock {
            hiddenIds ?: run {
                val json = preferencesRepository.hiddenSmsIds.first()
                loadHiddenIdsFromJson(json).also { hiddenIds = it }
            }
        }
    }

    private fun loadHiddenIdsFromJson(json: String): Set<Long> {
        if (json.isBlank()) return emptySet()
        return try {
            val type = object : TypeToken<List<Long>>() {}.type
            val list: List<Long> = gson.fromJson(json, type)
            list.toHashSet()
        } catch (e: Exception) {
            Log.e(TAG, "Error parsing hidden SMS IDs from disk", e)
            emptySet()
        }
    }

    /**
     * Converts a subscription ID to a physical SIM slot index.
     * Result is cached — at most one IPC call per unique subscription ID (max 2 per device).
     */
    @SuppressLint("MissingPermission")
    private fun subscriptionIdToSlot(subId: Int): Int {
        if (subId < 0) return 0
        return subIdToSlotCache.getOrPut(subId) {
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    SubscriptionManager.getSlotIndex(subId).takeIf { it >= 0 } ?: 0
                } else {
                    val subManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager
                    subManager.getActiveSubscriptionInfo(subId)?.simSlotIndex ?: 0
                }
            } catch (e: Exception) {
                Log.w(TAG, "Could not resolve subId=$subId to slot, defaulting to 0", e)
                0
            }
        }
    }
}
