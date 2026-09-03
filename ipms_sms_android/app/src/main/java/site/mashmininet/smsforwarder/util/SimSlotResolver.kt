package site.mashmininet.smsforwarder.util

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.os.Build
import android.telephony.SubscriptionManager
import android.util.Log

/**
 * Resolves which physical SIM slot (0 = SIM1, 1 = SIM2) an SMS broadcast arrived on.
 *
 * OEMs disagree on both the extra key and the value type (Int, Long, even String),
 * and some only provide a subscription ID that must be mapped to a slot index.
 */
object SimSlotResolver {

    private const val TAG = "SimSlotResolver"

    /**
     * Keys whose value is a slot index directly.
     *
     * Deliberately excludes "phone" and "simId": on most OEMs the "phone" extra is the
     * modem/RIL phone-id, not the physical SIM slot, and its 0/1 ordering is frequently
     * reversed versus the SIM tray. Trusting it swapped SIM1/SIM2 on the forward path
     * while the inbox (subscription-derived) badge stayed correct.
     */
    private val SLOT_KEYS = listOf(
        "slot", "sim_slot", "slotId", "slot_id", "simSlot",
        "android.telephony.extra.SLOT_INDEX"
    )

    /** Keys whose value is a subscription ID that must be mapped to a slot. */
    private val SUBSCRIPTION_KEYS = listOf(
        "subscription", "subscription_id", "android.telephony.extra.SUBSCRIPTION_INDEX"
    )

    fun fromIntent(context: Context, intent: Intent): Int {
        val extras = intent.extras ?: return 0

        // Resolve via the subscription ID first. SubscriptionManager.getSlotIndex() is the
        // Android-authoritative mapping and matches what the inbox-based display path uses
        // (Telephony.Sms.SUBSCRIPTION_ID), keeping the forwarded SIM consistent with the badge/tab.
        for (key in SUBSCRIPTION_KEYS) {
            val subId = parseNumeric(extras.get(key)) ?: continue
            if (subId >= 0) {
                subscriptionIdToSlot(context, subId)?.let { return it }
            }
        }

        // Fall back to a direct slot index only when no subscription extra is present.
        for (key in SLOT_KEYS) {
            val slot = parseNumeric(extras.get(key)) ?: continue
            if (slot in 0..3) return slot
        }

        return 0
    }

    /** Some OEMs ship the slot extra as Long or String instead of Int. */
    private fun parseNumeric(value: Any?): Int? = when (value) {
        is Int -> value
        is Long -> value.toInt()
        is String -> value.toIntOrNull()
        else -> null
    }

    @SuppressLint("MissingPermission")
    fun subscriptionIdToSlot(context: Context, subId: Int): Int? {
        return try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                SubscriptionManager.getSlotIndex(subId).takeIf { it >= 0 }
            } else {
                val subscriptionManager = context.getSystemService(
                    Context.TELEPHONY_SUBSCRIPTION_SERVICE
                ) as SubscriptionManager
                subscriptionManager.getActiveSubscriptionInfo(subId)?.simSlotIndex
            }
        } catch (e: Exception) {
            Log.w(TAG, "Could not map subscription $subId to a slot", e)
            null
        }
    }
}
