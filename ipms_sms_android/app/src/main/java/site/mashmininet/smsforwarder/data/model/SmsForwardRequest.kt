package site.mashmininet.smsforwarder.data.model

/**
 * Request body for the IPMS OTP ingest endpoint (POST /otp).
 * Field names match the Laravel validator on the portal side.
 */
data class SmsForwardRequest(
    val phone: String,
    val msg: String
)

/**
 * Response shape returned by POST /otp on the IPMS portal.
 */
data class SmsForwardResponse(
    val id: Long? = null,
    val phone: String? = null,
    val otp_code: String? = null,
    val is_ivacbd: Boolean? = null,
    val fetched_at: String? = null
)
