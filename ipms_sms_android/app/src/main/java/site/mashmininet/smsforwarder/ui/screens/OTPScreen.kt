package site.mashmininet.smsforwarder.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudDone
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.viewmodel.compose.viewModel
import site.mashmininet.smsforwarder.data.model.ForwardStatus
import site.mashmininet.smsforwarder.ui.components.SimBadge
import site.mashmininet.smsforwarder.ui.components.SmsCard
import site.mashmininet.smsforwarder.ui.components.SmsCardShimmer
import site.mashmininet.smsforwarder.ui.components.StatusBadge
import site.mashmininet.smsforwarder.ui.theme.Accent
import site.mashmininet.smsforwarder.ui.theme.AccentForeground
import site.mashmininet.smsforwarder.ui.theme.AccentBlack
import site.mashmininet.smsforwarder.ui.theme.AccentBlackFg
import site.mashmininet.smsforwarder.ui.theme.Border
import site.mashmininet.smsforwarder.ui.theme.Surface
import site.mashmininet.smsforwarder.ui.theme.Destructive
import site.mashmininet.smsforwarder.ui.theme.Success
import site.mashmininet.smsforwarder.ui.theme.Warning
import site.mashmininet.smsforwarder.ui.viewmodel.SmsViewModel
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OTPScreen(viewModel: SmsViewModel = viewModel()) {
    val uiState by viewModel.uiState.collectAsState()
    val bottomSheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var showDeleteConfirmation by remember { mutableStateOf(false) }
    val scrollBehavior = TopAppBarDefaults.pinnedScrollBehavior()

    LifecycleResumeEffect(Unit) {
        viewModel.refreshStatus()
        onPauseOrDispose { }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .nestedScroll(scrollBehavior.nestedScrollConnection)
    ) {
        if (uiState.isSelectionMode) {
            TopAppBar(
                title = {
                    Text("${uiState.selectedIds.size} selected", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                },
                navigationIcon = {
                    IconButton(onClick = { viewModel.exitSelectionMode() }) {
                        Icon(Icons.Rounded.Cancel, contentDescription = "Cancel")
                    }
                },
                actions = {
                    IconButton(onClick = { showDeleteConfirmation = true }, enabled = uiState.selectedIds.isNotEmpty()) {
                        Icon(
                            Icons.Rounded.DeleteOutline,
                            contentDescription = "Delete selected",
                            tint = if (uiState.selectedIds.isNotEmpty()) Destructive else MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                },
                windowInsets = WindowInsets(0, 0, 0, 0),
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.surface,
                    titleContentColor = MaterialTheme.colorScheme.onBackground
                )
            )
        } else {
            TopAppBar(
                title = { BrandTitle() },
                scrollBehavior = scrollBehavior,
                windowInsets = WindowInsets(0, 0, 0, 0),
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                    scrolledContainerColor = MaterialTheme.colorScheme.surface
                )
            )
        }

        when {
            uiState.isLoading -> {
                LazyColumn(
                    contentPadding = PaddingValues(start = 12.dp, end = 12.dp, top = 6.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp)
                ) {
                    items(5) { SmsCardShimmer() }
                }
            }
            else -> {
                LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 12.dp, end = 12.dp, top = 6.dp, bottom = 16.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp)
                ) {
                    if (!uiState.isSelectionMode) {
                        item(key = "filters") {
                            FilterRow(selected = uiState.selectedFilter, onSelect = { viewModel.setFilter(it) })
                        }
                    }

                    if (uiState.filteredMessages.isEmpty()) {
                        item(key = "empty") { EmptyState() }
                    } else {
                        items(items = uiState.filteredMessages, key = { it.id }) { message ->
                            SmsCard(
                                smsMessage = message,
                                isSelected = message.id in uiState.selectedIds,
                                isSelectionMode = uiState.isSelectionMode,
                                onClick = {
                                    if (uiState.isSelectionMode) viewModel.toggleSelection(message.id)
                                    else viewModel.selectMessage(message)
                                },
                                onLongClick = {
                                    if (!uiState.isSelectionMode) viewModel.enterSelectionMode(message.id)
                                }
                            )
                        }
                    }
                }
            }
        }
    }

    if (showDeleteConfirmation) {
        AlertDialog(
            onDismissRequest = { showDeleteConfirmation = false },
            title = { Text("Delete Messages", style = MaterialTheme.typography.titleMedium) },
            text = {
                Text(
                    "Remove ${uiState.selectedIds.size} message${if (uiState.selectedIds.size > 1) "s" else ""} from this list?",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            },
            confirmButton = {
                TextButton(onClick = { showDeleteConfirmation = false; viewModel.deleteSelected() }) {
                    Text("Delete", color = Destructive)
                }
            },
            dismissButton = {
                TextButton(onClick = { showDeleteConfirmation = false }) {
                    Text("Cancel", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            },
            containerColor = MaterialTheme.colorScheme.background,
            shape = RoundedCornerShape(10.dp)
        )
    }

    if (uiState.showBottomSheet && uiState.selectedMessage != null) {
        val message = uiState.selectedMessage!!

        ModalBottomSheet(
            onDismissRequest = { viewModel.dismissBottomSheet() },
            sheetState = bottomSheetState,
            containerColor = MaterialTheme.colorScheme.background,
            contentColor = MaterialTheme.colorScheme.onBackground,
            shape = RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp),
            tonalElevation = 0.dp
        ) {
            Column(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 32.dp)
            ) {
                Text("From", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.height(4.dp))
                Text(message.sender, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onBackground)

                Spacer(Modifier.height(16.dp))

                Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    SimBadge(simSlot = message.simSlot)
                    StatusBadge(status = message.forwardStatus)
                    Text(
                        text = SimpleDateFormat("MMM dd, yyyy  hh:mm a", Locale.getDefault()).format(Date(message.timestamp)),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }

                Spacer(Modifier.height(20.dp))
                Box(modifier = Modifier.fillMaxWidth().height(1.dp).background(MaterialTheme.colorScheme.outline))
                Spacer(Modifier.height(20.dp))

                Text("Message", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.height(8.dp))
                Text(message.body, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onBackground, lineHeight = 22.sp)

                if (message.forwardStatus != ForwardStatus.DELIVERED) {
                    Spacer(Modifier.height(24.dp))
                    SendNowButton(isFailed = message.forwardStatus == ForwardStatus.FAILED, sending = uiState.sendingNow, onClick = { viewModel.sendNow(message) })
                }
            }
        }
    }
}

@Composable
private fun BrandTitle() {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(
            modifier = Modifier
                .size(32.dp)
                .clip(RoundedCornerShape(8.dp))
                .background(AccentBlack)
                .border(1.dp, AccentBlack, RoundedCornerShape(8.dp)),
            contentAlignment = Alignment.Center
        ) {
            Icon(Icons.Rounded.Bolt, contentDescription = null, tint = AccentBlackFg, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(8.dp))
        Column {
            Text(
                "BLITZ",
                style = MaterialTheme.typography.titleLarge,
                color = MaterialTheme.colorScheme.onBackground,
                fontWeight = FontWeight.Bold,
                letterSpacing = 2.sp
            )
            Text(
                "SMS Forwarder",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}

@Composable
private fun FilterRow(selected: SmsViewModel.SmsFilter, onSelect: (SmsViewModel.SmsFilter) -> Unit) {
    val filters = listOf(
        SmsViewModel.SmsFilter.ALL  to "All",
        SmsViewModel.SmsFilter.SIM1 to "SIM 1",
        SmsViewModel.SmsFilter.SIM2 to "SIM 2"
    )
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(filters) { (filter, label) ->
            FilterChip(
                selected = selected == filter,
                onClick = { onSelect(filter) },
                label = {
                    Text(label, fontSize = 13.sp, fontWeight = if (selected == filter) FontWeight.Medium else FontWeight.Normal)
                },
                shape = RoundedCornerShape(8.dp),
                colors = FilterChipDefaults.filterChipColors(
                    selectedContainerColor = AccentBlack,
                    selectedLabelColor = AccentBlackFg,
                    containerColor = MaterialTheme.colorScheme.surface,
                    labelColor = MaterialTheme.colorScheme.onSurfaceVariant
                ),
                border = FilterChipDefaults.filterChipBorder(
                    borderColor = MaterialTheme.colorScheme.outline,
                    selectedBorderColor = AccentBlack,
                    enabled = true,
                    selected = selected == filter
                )
            )
        }
    }
}

@Composable
private fun EmptyState() {
    Box(modifier = Modifier.fillMaxWidth().padding(top = 80.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Icon(Icons.Rounded.Inbox, contentDescription = null, modifier = Modifier.size(64.dp), tint = MaterialTheme.colorScheme.outlineVariant)
            Text("No messages yet", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text("Incoming SMS messages will appear here", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
        }
    }
}

@Composable
private fun SendNowButton(isFailed: Boolean, sending: Boolean, onClick: () -> Unit) {
    val enabled = !sending
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(44.dp)
            .clip(RoundedCornerShape(8.dp))
            .background(if (enabled) AccentBlack else AccentBlack.copy(alpha = 0.35f))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 16.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (sending) {
            CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp, color = AccentForeground)
            Spacer(Modifier.width(10.dp))
            Text("Sending…", color = AccentForeground, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
        } else {
            Icon(Icons.Rounded.Send, contentDescription = null, tint = AccentForeground, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(10.dp))
            Text(if (isFailed) "Retry now" else "Send now", color = AccentForeground, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
        }
    }
}

@Composable
private fun HealthDashboard(serviceRunning: Boolean, permissionsHealthy: Boolean, deliveredCount: Int, failedCount: Int) {
    val statusColor = when {
        !serviceRunning       -> site.mashmininet.smsforwarder.ui.theme.Destructive
        !permissionsHealthy   -> Warning
        else                  -> Success
    }
    val statusText = when {
        !serviceRunning       -> "Service stopped"
        !permissionsHealthy   -> "Action needed"
        else                  -> "Active & forwarding"
    }

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(MaterialTheme.colorScheme.surface)
            .border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(14.dp))
            .padding(16.dp)
    ) {
        Column {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(modifier = Modifier.size(8.dp).clip(CircleShape).background(statusColor))
                Spacer(Modifier.width(8.dp))
                Text(statusText, style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.onBackground, fontWeight = FontWeight.SemiBold)
                if (serviceRunning && permissionsHealthy) {
                    Spacer(Modifier.weight(1f))
                    Box(
                        modifier = Modifier
                            .clip(RoundedCornerShape(6.dp))
                            .background(Success.copy(alpha = 0.12f))
                            .padding(horizontal = 8.dp, vertical = 3.dp)
                    ) {
                        Text("Live", style = MaterialTheme.typography.labelSmall, color = Success, fontWeight = FontWeight.SemiBold)
                    }
                }
            }

            Spacer(Modifier.height(14.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatTile(Modifier.weight(1f), Icons.Rounded.CloudDone,   Success,     deliveredCount.toString(), "Forwarded")
                StatTile(Modifier.weight(1f), Icons.Rounded.CloudOff,    Destructive, failedCount.toString(),    "Failed")
                StatTile(
                    Modifier.weight(1f),
                    Icons.Rounded.VerifiedUser,
                    if (permissionsHealthy) Success else Warning,
                    if (permissionsHealthy) "OK" else "Check",
                    "Permissions"
                )
            }
        }
    }
}

@Composable
private fun StatTile(modifier: Modifier = Modifier, icon: ImageVector, tint: Color, value: String, label: String) {
    Column(
        modifier = modifier
            .clip(RoundedCornerShape(10.dp))
            .background(MaterialTheme.colorScheme.background)
            .border(1.dp, MaterialTheme.colorScheme.outline.copy(alpha = 0.5f), RoundedCornerShape(10.dp))
            .padding(vertical = 12.dp, horizontal = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
        Spacer(Modifier.height(5.dp))
        Text(value, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onBackground, fontWeight = FontWeight.Bold)
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
