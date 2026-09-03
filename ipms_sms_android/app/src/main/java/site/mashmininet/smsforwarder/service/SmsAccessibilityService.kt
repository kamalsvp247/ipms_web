package site.mashmininet.smsforwarder.service

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.AccessibilityServiceInfo
import android.util.Log
import android.view.accessibility.AccessibilityEvent
import site.mashmininet.smsforwarder.util.ServiceLauncher

/**
 * Accessibility service that keeps the app active and detects system-level events.
 * This service helps prevent the OS from killing the app by maintaining an active
 * accessibility service connection.
 */
class SmsAccessibilityService : AccessibilityService() {

    companion object {
        private const val TAG = "SmsAccessibilityService"
    }

    override fun onServiceConnected() {
        super.onServiceConnected()
        Log.i(TAG, "Accessibility service connected")

        val info = AccessibilityServiceInfo().apply {
            eventTypes = AccessibilityEvent.TYPE_NOTIFICATION_STATE_CHANGED
            feedbackType = AccessibilityServiceInfo.FEEDBACK_GENERIC
            notificationTimeout = 100
            // Minimal configuration to reduce performance impact
            flags = AccessibilityServiceInfo.FLAG_INCLUDE_NOT_IMPORTANT_VIEWS
        }
        serviceInfo = info

        // Ensure the foreground service is running
        ensureForegroundServiceRunning()
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        // Minimal processing — this service primarily exists to keep the app alive
    }

    override fun onInterrupt() {
        Log.w(TAG, "Accessibility service interrupted")
    }

    override fun onDestroy() {
        super.onDestroy()
        Log.w(TAG, "Accessibility service destroyed")
    }

    private fun ensureForegroundServiceRunning() {
        if (!SmsForegroundService.isRunning) {
            Log.i(TAG, "Foreground service not running, starting it from accessibility service")
            ServiceLauncher.start(this)
        }
    }
}
