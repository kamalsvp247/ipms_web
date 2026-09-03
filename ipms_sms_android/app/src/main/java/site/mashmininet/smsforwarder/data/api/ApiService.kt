package site.mashmininet.smsforwarder.data.api

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.POST
import site.mashmininet.smsforwarder.data.model.SmsForwardRequest
import site.mashmininet.smsforwarder.data.model.SmsForwardResponse

/**
 * Retrofit API service for the IPMS OTP ingest endpoint.
 * The endpoint is fixed at <BASE_URL>/otp on the portal.
 */
interface ApiService {

    @POST("otp")
    suspend fun forwardOtp(
        @Body request: SmsForwardRequest
    ): Response<SmsForwardResponse>
}
