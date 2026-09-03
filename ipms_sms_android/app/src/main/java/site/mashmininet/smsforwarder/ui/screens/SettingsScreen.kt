package site.mashmininet.smsforwarder.ui.screens

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccessibilityNew
import androidx.compose.material.icons.rounded.AdminPanelSettings
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.BatteryChargingFull
import androidx.compose.material.icons.rounded.ManageAccounts
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.PlayCircle
import androidx.compose.material.icons.rounded.RocketLaunch
import androidx.compose.material.icons.rounded.SimCard
import androidx.compose.material.icons.rounded.Sms
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import site.mashmininet.smsforwarder.BuildConfig
import site.mashmininet.smsforwarder.ui.components.PermissionToggleRow
import site.mashmininet.smsforwarder.ui.components.ShadcnButton
import site.mashmininet.smsforwarder.ui.components.ShadcnButtonVariant
import site.mashmininet.smsforwarder.ui.components.ShadcnDestructiveOutlinedButton
import site.mashmininet.smsforwarder.ui.components.ShadcnTextField
import site.mashmininet.smsforwarder.ui.theme.Destructive
import site.mashmininet.smsforwarder.ui.viewmodel.SettingsViewModel
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
fun SettingsScreen(viewModel: SettingsViewModel = viewModel(), snackbarHostState: SnackbarHostState) {
    val uiState by viewModel.uiState.collectAsState()
    val context = LocalContext.current
    val scrollState = rememberScrollState()

    val smsPermissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        viewModel.refreshPermissions()
    }
    val phonePermissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        viewModel.refreshPermissions()
    }
    val notificationPermissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        viewModel.refreshPermissions()
    }

    LifecycleResumeEffect(Unit) {
        viewModel.refreshPermissions()
        onPauseOrDispose { }
    }

    LaunchedEffect(Unit) {
        viewModel.snackbarEvent.collect { message -> snackbarHostState.showSnackbar(message) }
    }

    LaunchedEffect(Unit) {
        viewModel.intentEvent.collect { intent ->
            try { context.startActivity(intent) }
            catch (e: Exception) { snackbarHostState.showSnackbar("Could not open settings: ${e.message}") }
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(scrollState)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(16.dp))

        Text("Settings", style = MaterialTheme.typography.displayLarge, color = MaterialTheme.colorScheme.onBackground)

        Spacer(modifier = Modifier.height(24.dp))

        // SIM Configuration
        SectionCard {
            Text("SIM Configuration", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onBackground)
            Spacer(modifier = Modifier.height(4.dp))
            Text("Phone numbers are auto-detected. Edit if incorrect.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

            Spacer(modifier = Modifier.height(16.dp))

            ShadcnTextField(
                value = uiState.sim1Number,
                onValueChange = { viewModel.updateSim1Number(it) },
                label = "SIM 1 Number",
                placeholder = "Auto-detecting...",
                keyboardType = KeyboardType.Phone,
                leadingIcon = {
                    Icon(Icons.Rounded.SimCard, contentDescription = "SIM 1", tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            )

            Spacer(modifier = Modifier.height(12.dp))

            ShadcnTextField(
                value = uiState.sim2Number,
                onValueChange = { viewModel.updateSim2Number(it) },
                label = "SIM 2 Number",
                placeholder = "Not detected / Not available",
                keyboardType = KeyboardType.Phone,
                leadingIcon = {
                    Icon(Icons.Rounded.SimCard, contentDescription = "SIM 2", tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            )

            Spacer(modifier = Modifier.height(12.dp))

            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = if (uiState.lastDetectedTime > 0)
                        "Last detected: ${SimpleDateFormat("MMM dd, hh:mm a", Locale.getDefault()).format(Date(uiState.lastDetectedTime))}"
                    else "Last detected: Never",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                ShadcnButton(text = "Re-detect", onClick = { viewModel.detectSimNumbers() }, variant = ShadcnButtonVariant.GHOST)
            }

            Spacer(modifier = Modifier.height(12.dp))

            ShadcnButton(text = "Update Numbers", onClick = { viewModel.savePhoneNumbers() }, modifier = Modifier.fillMaxWidth(), variant = ShadcnButtonVariant.FILLED)
        }

        Spacer(modifier = Modifier.height(16.dp))

        // Permissions
        SectionCard {
            Text("App Permissions", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onBackground)
            Spacer(modifier = Modifier.height(4.dp))
            Text("Grant all permissions to ensure reliable SMS forwarding.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)

            Spacer(modifier = Modifier.height(12.dp))

            PermissionToggleRow(
                icon = Icons.Rounded.Sms,
                title = "SMS Access",
                description = "Required to read and receive incoming SMS",
                isGranted = uiState.smsPermissionGranted,
                showToggle = true,
                onToggle = { smsPermissionLauncher.launch(arrayOf(Manifest.permission.RECEIVE_SMS, Manifest.permission.READ_SMS)) }
            )

            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)

            PermissionToggleRow(
                icon = Icons.Rounded.PhoneAndroid,
                title = "Phone State",
                description = "Required to detect SIM card numbers",
                isGranted = uiState.phoneStatePermissionGranted,
                showToggle = true,
                onToggle = {
                    val perms = mutableListOf(Manifest.permission.READ_PHONE_STATE)
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) perms.add(Manifest.permission.READ_PHONE_NUMBERS)
                    phonePermissionLauncher.launch(perms.toTypedArray())
                }
            )

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
                PermissionToggleRow(
                    icon = Icons.Rounded.NotificationsActive,
                    title = "Foreground Notifications",
                    description = "Show persistent notification to keep service alive",
                    isGranted = uiState.notificationPermissionGranted,
                    showToggle = true,
                    onToggle = { notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS) }
                )
            }

            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
            PermissionToggleRow(
                icon = Icons.Rounded.BatteryChargingFull,
                title = "Battery Optimization",
                description = "Prevent Android from killing the app in low battery",
                isGranted = uiState.batteryOptimizationDisabled,
                showToggle = false,
                onArrowClick = { viewModel.requestBatteryOptimization() }
            )

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
                PermissionToggleRow(
                    icon = Icons.Rounded.Alarm,
                    title = "Exact Alarms",
                    description = "Lets the app reliably restart its service if the system stops it",
                    isGranted = uiState.exactAlarmAllowed,
                    showToggle = false,
                    onArrowClick = { viewModel.requestExactAlarm() }
                )
            }

            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
            PermissionToggleRow(
                icon = Icons.Rounded.AdminPanelSettings,
                title = "Device Administrator",
                description = "Prevents the app from being force-stopped or uninstalled",
                isGranted = uiState.deviceAdminEnabled,
                showToggle = false,
                onArrowClick = { viewModel.requestDeviceAdmin() }
            )

            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
            PermissionToggleRow(
                icon = Icons.Rounded.AccessibilityNew,
                title = "Accessibility Service",
                description = "Keeps app active and detects system-level events",
                isGranted = uiState.accessibilityServiceEnabled,
                showToggle = false,
                onArrowClick = { viewModel.openAccessibilitySettings() }
            )

            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
            PermissionToggleRow(
                icon = Icons.Rounded.PlayCircle,
                title = "Background Service",
                description = "Run continuously in the background",
                isGranted = uiState.serviceRunning,
                showToggle = true,
                onToggle = { viewModel.toggleService() }
            )

            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
            PermissionToggleRow(
                icon = Icons.Rounded.RocketLaunch,
                title = "Autostart Permission",
                description = "Enable in your phone's battery or autostart settings",
                isGranted = false,
                showToggle = false,
                onArrowClick = { viewModel.openAutostartSettings() }
            )

            if (uiState.oemManufacturerName.isNotBlank()) {
                HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), thickness = 0.5.dp)
                PermissionToggleRow(
                    icon = Icons.Rounded.ManageAccounts,
                    title = "${uiState.oemManufacturerName} Power Manager",
                    description = "Open OEM battery whitelist to prevent the app from being killed",
                    isGranted = false,
                    showToggle = false,
                    onArrowClick = { viewModel.openOemBatterySettings() }
                )
            }
        }

        Spacer(modifier = Modifier.height(16.dp))

        // Danger Zone
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(12.dp))
                .border(1.dp, Destructive.copy(alpha = 0.4f), RoundedCornerShape(12.dp))
                .background(MaterialTheme.colorScheme.surface)
                .padding(16.dp)
        ) {
            Column {
                Text("Danger Zone", style = MaterialTheme.typography.titleMedium, color = Destructive)

                Spacer(modifier = Modifier.height(16.dp))

                ShadcnDestructiveOutlinedButton(text = "Clear All SMS Logs", onClick = { viewModel.showClearLogsDialog() }, modifier = Modifier.fillMaxWidth())

                Spacer(modifier = Modifier.height(8.dp))

                ShadcnDestructiveOutlinedButton(text = "Reset Settings", onClick = { viewModel.showResetDialog() }, modifier = Modifier.fillMaxWidth())
            }
        }

        Spacer(modifier = Modifier.height(16.dp))

        Text(
            text = "Blitz SMS Forwarder v${BuildConfig.VERSION_NAME}",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.5f),
            textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth()
        )

        Spacer(modifier = Modifier.height(24.dp))
    }

    if (uiState.showClearLogsDialog) {
        AlertDialog(
            onDismissRequest = { viewModel.dismissClearLogsDialog() },
            title = { Text("Clear SMS Logs", style = MaterialTheme.typography.titleMedium) },
            text = { Text("This will clear all forward status logs. SMS messages on your device will not be affected.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) },
            confirmButton = { TextButton(onClick = { viewModel.clearLogs() }) { Text("Clear", color = Destructive) } },
            dismissButton = { TextButton(onClick = { viewModel.dismissClearLogsDialog() }) { Text("Cancel", color = MaterialTheme.colorScheme.onSurfaceVariant) } },
            containerColor = MaterialTheme.colorScheme.surface,
            shape = RoundedCornerShape(12.dp)
        )
    }

    if (uiState.showResetDialog) {
        AlertDialog(
            onDismissRequest = { viewModel.dismissResetDialog() },
            title = { Text("Reset All Settings", style = MaterialTheme.typography.titleMedium) },
            text = { Text("This will reset all app settings to their defaults, including SIM numbers. This action cannot be undone.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) },
            confirmButton = { TextButton(onClick = { viewModel.resetSettings() }) { Text("Reset", color = Destructive) } },
            dismissButton = { TextButton(onClick = { viewModel.dismissResetDialog() }) { Text("Cancel", color = MaterialTheme.colorScheme.onSurfaceVariant) } },
            containerColor = MaterialTheme.colorScheme.surface,
            shape = RoundedCornerShape(12.dp)
        )
    }
}

@Composable
private fun SectionCard(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Box(
        modifier = modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(12.dp))
            .background(MaterialTheme.colorScheme.surface)
            .padding(16.dp)
    ) {
        Column { content() }
    }
}
