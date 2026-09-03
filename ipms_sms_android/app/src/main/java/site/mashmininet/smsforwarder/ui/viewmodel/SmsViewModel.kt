package site.mashmininet.smsforwarder.ui.viewmodel

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.catch
import kotlinx.coroutines.launch
import site.mashmininet.smsforwarder.data.model.ForwardStatus
import site.mashmininet.smsforwarder.data.model.SmsMessage
import site.mashmininet.smsforwarder.data.repository.SmsRepository
import site.mashmininet.smsforwarder.service.SmsForegroundService
import site.mashmininet.smsforwarder.util.PermissionsHelper

/**
 * ViewModel for the OTP/Messages screen.
 * Manages SMS list state, filtering, and content observation.
 */
class SmsViewModel(application: Application) : AndroidViewModel(application) {

    private val smsRepository = SmsRepository.getInstance(application)

    // ── UI State ──

    data class SmsUiState(
        val messages: List<SmsMessage> = emptyList(),
        val filteredMessages: List<SmsMessage> = emptyList(),
        val isLoading: Boolean = true,
        val selectedFilter: SmsFilter = SmsFilter.ALL,
        val selectedMessage: SmsMessage? = null,
        val showBottomSheet: Boolean = false,
        val sendingNow: Boolean = false,
        val error: String? = null,
        val isSelectionMode: Boolean = false,
        val selectedIds: Set<Long> = emptySet(),
        // Health dashboard
        val serviceRunning: Boolean = false,
        val deliveredCount: Int = 0,
        val failedCount: Int = 0,
        val permissionsHealthy: Boolean = true
    )

    enum class SmsFilter { ALL, SIM1, SIM2 }

    private val _uiState = MutableStateFlow(SmsUiState())
    val uiState: StateFlow<SmsUiState> = _uiState.asStateFlow()

    init {
        observeMessages()
        refreshStatus()
    }

    /**
     * Refreshes the service-running flag and permission health. Cheap, side-effect-free;
     * call from the screen's resume so the dashboard reflects the live state.
     */
    fun refreshStatus() {
        val context = getApplication<Application>()
        _uiState.value = _uiState.value.copy(
            serviceRunning = SmsForegroundService.isRunning,
            permissionsHealthy = PermissionsHelper.hasSmsPermissions(context) &&
                PermissionsHelper.hasNotificationPermission(context) &&
                PermissionsHelper.isBatteryOptimizationDisabled(context)
        )
    }

    /**
     * Start observing SMS inbox changes via ContentObserver.
     */
    private fun observeMessages() {
        viewModelScope.launch {
            smsRepository.observeSmsChanges()
                .catch { e ->
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        error = e.message ?: "Error loading messages"
                    )
                }
                .collect { messages ->
                    _uiState.value = _uiState.value.copy(
                        messages = messages,
                        filteredMessages = applyFilter(messages, _uiState.value.selectedFilter),
                        isLoading = false,
                        error = null,
                        deliveredCount = messages.count { it.forwardStatus == ForwardStatus.DELIVERED },
                        failedCount = messages.count { it.forwardStatus == ForwardStatus.FAILED }
                    )
                }
        }
    }

    /**
     * Refresh the SMS list manually.
     */
    fun refreshMessages() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true)
            try {
                val messages = smsRepository.getSmsMessages()
                _uiState.value = _uiState.value.copy(
                    messages = messages,
                    filteredMessages = applyFilter(messages, _uiState.value.selectedFilter),
                    isLoading = false,
                    error = null
                )
            } catch (e: Exception) {
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    error = e.message
                )
            }
        }
    }

    /**
     * Set the SIM filter and refilter messages.
     */
    fun setFilter(filter: SmsFilter) {
        _uiState.value = _uiState.value.copy(
            selectedFilter = filter,
            filteredMessages = applyFilter(_uiState.value.messages, filter)
        )
    }

    /**
     * Select a message to show in the bottom sheet.
     */
    fun selectMessage(message: SmsMessage) {
        _uiState.value = _uiState.value.copy(
            selectedMessage = message,
            showBottomSheet = true
        )
    }

    /**
     * Dismiss the bottom sheet.
     */
    fun dismissBottomSheet() {
        _uiState.value = _uiState.value.copy(
            showBottomSheet = false,
            selectedMessage = null
        )
    }

    /**
     * Manually forward the given message now (from the detail sheet) and reflect
     * the result on the open sheet. The list refreshes automatically.
     */
    fun sendNow(message: SmsMessage) {
        if (_uiState.value.sendingNow) return
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(sendingNow = true)
            val result = smsRepository.sendNow(message)
            val updated = message.copy(forwardStatus = result)
            _uiState.value = _uiState.value.copy(
                sendingNow = false,
                selectedMessage = if (_uiState.value.selectedMessage?.id == message.id) {
                    updated
                } else {
                    _uiState.value.selectedMessage
                }
            )
        }
    }

    /**
     * Enter selection mode and immediately select the tapped message.
     */
    fun enterSelectionMode(id: Long) {
        _uiState.value = _uiState.value.copy(
            isSelectionMode = true,
            selectedIds = setOf(id)
        )
    }

    /**
     * Toggle selection of a single message. Exits selection mode when no items remain selected.
     */
    fun toggleSelection(id: Long) {
        val current = _uiState.value.selectedIds.toMutableSet()
        if (id in current) current.remove(id) else current.add(id)
        _uiState.value = _uiState.value.copy(
            selectedIds = current,
            isSelectionMode = current.isNotEmpty()
        )
    }

    /**
     * Exit selection mode without deleting anything.
     */
    fun exitSelectionMode() {
        _uiState.value = _uiState.value.copy(
            isSelectionMode = false,
            selectedIds = emptySet()
        )
    }

    /**
     * Hide all selected messages from the list (persisted across restarts).
     */
    fun deleteSelected() {
        val ids = _uiState.value.selectedIds
        if (ids.isEmpty()) return
        viewModelScope.launch {
            smsRepository.deleteMessages(ids)
            _uiState.value = _uiState.value.copy(
                isSelectionMode = false,
                selectedIds = emptySet()
            )
        }
    }

    /**
     * Apply SIM filter to messages list.
     */
    private fun applyFilter(messages: List<SmsMessage>, filter: SmsFilter): List<SmsMessage> {
        return when (filter) {
            SmsFilter.ALL -> messages
            SmsFilter.SIM1 -> messages.filter { it.simSlot == 0 }
            SmsFilter.SIM2 -> messages.filter { it.simSlot == 1 }
        }
    }
}
