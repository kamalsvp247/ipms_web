package site.mashmininet.smsforwarder.data.model

/**
 * Represents the forwarding status of an SMS message.
 */
enum class ForwardStatus {
    PENDING,
    DELIVERED,
    FAILED
}

/**
 * Represents an SMS message read from the device inbox.
 */
data class SmsMessage(
    val id: Long,
    val sender: String,
    val body: String,
    val timestamp: Long,
    val simSlot: Int,           // 0 = SIM1, 1 = SIM2
    val forwardStatus: ForwardStatus
)
