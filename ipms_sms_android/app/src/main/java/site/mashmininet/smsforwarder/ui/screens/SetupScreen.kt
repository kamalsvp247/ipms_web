package site.mashmininet.smsforwarder.ui.screens

import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material.icons.filled.AccessAlarm
import androidx.compose.material.icons.filled.Accessibility
import androidx.compose.material.icons.filled.BatteryChargingFull
import androidx.compose.material.icons.filled.Bolt
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Message
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.RocketLaunch
import androidx.compose.material.icons.filled.Security
import androidx.compose.material.icons.filled.SimCard
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.viewmodel.compose.viewModel
import site.mashmininet.smsforwarder.ui.components.ShadcnButton
import site.mashmininet.smsforwarder.ui.components.ShadcnButtonVariant
import site.mashmininet.smsforwarder.ui.components.ShadcnTextField
import site.mashmininet.smsforwarder.ui.theme.Accent
import site.mashmininet.smsforwarder.ui.theme.Success
import site.mashmininet.smsforwarder.util.PermissionsHelper
import site.mashmininet.smsforwarder.ui.viewmodel.SetupViewModel

/**
 * First-launch guided setup. Walks the user through every permission the app needs,
 * captures the SIM owner numbers, then arms the forwarding service. Critical steps
 * (SMS access, SIM numbers) gate progression; the rest are strongly recommended but
 * skippable so the user is never hard-blocked on an OEM screen.
 */
@Composable
fun SetupScreen(
    onSetupComplete: () -> Unit,
    viewModel: SetupViewModel = viewModel()
) {
    val uiState by viewModel.uiState.collectAsState()
    val context = LocalContext.current

    val totalSteps = 8

    val smsLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { viewModel.refresh() }

    val phoneLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        viewModel.refresh()
        viewModel.detectSimNumbers()
    }

    val notificationLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { viewModel.refresh() }

    LaunchedEffect(Unit) {
        viewModel.intentEvent.collect { intent ->
            runCatching { context.startActivity(intent) }
        }
    }

    // Re-check permissions every time the user returns from a system settings screen.
    LifecycleResumeEffect(Unit) {
        viewModel.refresh()
        onPauseOrDispose { }
    }

    LaunchedEffect(uiState.finished) {
        if (uiState.finished) onSetupComplete()
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .windowInsetsPadding(WindowInsets.statusBars)
            .padding(horizontal = 24.dp)
    ) {
        Spacer(Modifier.height(24.dp))

        // Progress dots
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            repeat(totalSteps) { index ->
                Box(
                    modifier = Modifier
                        .weight(1f)
                        .height(4.dp)
                        .clip(CircleShape)
                        .background(
                            if (index <= uiState.stepIndex) Accent
                            else MaterialTheme.colorScheme.outline
                        )
                )
            }
        }

        Spacer(Modifier.height(8.dp))
        Text(
            text = "Step ${uiState.stepIndex + 1} of $totalSteps",
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )

        Spacer(Modifier.height(16.dp))

        AnimatedContent(
            targetState = uiState.stepIndex,
            transitionSpec = {
                if (targetState > initialState) {
                    (slideInHorizontally(tween(250)) { it / 3 } + fadeIn(tween(250))) togetherWith
                        (slideOutHorizontally(tween(250)) { -it / 3 } + fadeOut(tween(250)))
                } else {
                    (slideInHorizontally(tween(250)) { -it / 3 } + fadeIn(tween(250))) togetherWith
                        (slideOutHorizontally(tween(250)) { it / 3 } + fadeOut(tween(250)))
                }
            },
            modifier = Modifier.weight(1f),
            label = "setup-step"
        ) { step ->
            Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                when (step) {
                    0 -> WelcomeStep()
                    1 -> PermissionStep(
                        icon = Icons.Filled.Message,
                        title = "SMS access",
                        body = "SMS Forwarder reads incoming text messages so it can forward one-time codes to your portal in real time. This is required for the app to do anything.",
                        granted = uiState.smsGranted,
                        required = true,
                        actionLabel = "Allow SMS access",
                        onAction = { smsLauncher.launch(PermissionsHelper.requiredSmsPermissions()) }
                    )
                    2 -> PermissionStep(
                        icon = Icons.Filled.Phone,
                        title = "Phone & SIM info",
                        body = "Used to detect which of your SIM cards received a message so the right owner number is attached when forwarding.",
                        granted = uiState.phoneGranted,
                        required = false,
                        actionLabel = "Allow phone access",
                        onAction = { phoneLauncher.launch(PermissionsHelper.phonePermissions()) }
                    )
                    3 -> SimNumbersStep(
                        sim1 = uiState.sim1Number,
                        sim2 = uiState.sim2Number,
                        onSim1Change = viewModel::updateSim1Number,
                        onSim2Change = viewModel::updateSim2Number,
                        onDetect = viewModel::detectSimNumbers
                    )
                    4 -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                            PermissionStep(
                                icon = Icons.Filled.Notifications,
                                title = "Notifications",
                                body = "The app shows a small ongoing notification so Android keeps the forwarding service alive. Please allow it.",
                                granted = uiState.notificationGranted,
                                required = false,
                                actionLabel = "Allow notifications",
                                onAction = { notificationLauncher.launch(android.Manifest.permission.POST_NOTIFICATIONS) }
                            )
                        } else {
                            PermissionStep(
                                icon = Icons.Filled.Notifications,
                                title = "Notifications",
                                body = "No action needed on this Android version. Tap Next to continue.",
                                granted = true,
                                required = false,
                                actionLabel = "Continue",
                                onAction = { viewModel.nextStep() }
                            )
                        }
                    }
                    5 -> BatteryStep(
                        batteryDisabled = uiState.batteryOptimizationDisabled,
                        exactAlarmAllowed = uiState.exactAlarmAllowed,
                        showExactAlarm = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S,
                        onBattery = viewModel::requestBatteryOptimization,
                        onExactAlarm = viewModel::requestExactAlarm
                    )
                    6 -> ProtectionStep(
                        deviceAdminEnabled = uiState.deviceAdminEnabled,
                        accessibilityEnabled = uiState.accessibilityEnabled,
                        oemName = uiState.oemManufacturerName,
                        onDeviceAdmin = viewModel::requestDeviceAdmin,
                        onAccessibility = viewModel::openAccessibilitySettings,
                        onOem = viewModel::openOemSettings
                    )
                    7 -> FinishStep(
                        smsGranted = uiState.smsGranted,
                        sim1 = uiState.sim1Number,
                        sim2 = uiState.sim2Number
                    )
                }
            }
        }

        // ── Navigation buttons ──
        val isLastStep = uiState.stepIndex == totalSteps - 1
        val canAdvance = when (uiState.stepIndex) {
            1 -> uiState.smsGranted // SMS is mandatory
            3 -> uiState.sim1Number.isNotBlank() || uiState.sim2Number.isNotBlank()
            else -> true
        }

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .windowInsetsPadding(WindowInsets.navigationBars)
                .padding(vertical = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            if (uiState.stepIndex > 0) {
                ShadcnButton(
                    text = "Back",
                    onClick = viewModel::previousStep,
                    variant = ShadcnButtonVariant.OUTLINED,
                    modifier = Modifier.weight(1f)
                )
            }
            ShadcnButton(
                text = if (isLastStep) "Finish & start" else "Next",
                onClick = { if (isLastStep) viewModel.finishSetup() else viewModel.nextStep() },
                variant = ShadcnButtonVariant.ACCENT,
                enabled = canAdvance,
                modifier = Modifier.weight(if (uiState.stepIndex > 0) 1f else 1f)
            )
        }
    }
}

@Composable
private fun WelcomeStep() {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
        Spacer(Modifier.height(24.dp))
        Box(
            modifier = Modifier
                .size(88.dp)
                .clip(RoundedCornerShape(24.dp))
                .background(MaterialTheme.colorScheme.surface),
            contentAlignment = Alignment.Center
        ) {
            Icon(Icons.Filled.Bolt, contentDescription = null, tint = Accent, modifier = Modifier.size(44.dp))
        }
        Spacer(Modifier.height(24.dp))
        Text(
            text = "Welcome to SMS Forwarder",
            style = MaterialTheme.typography.headlineMedium,
            color = MaterialTheme.colorScheme.onBackground,
            textAlign = TextAlign.Center
        )
        Spacer(Modifier.height(12.dp))
        Text(
            text = "This quick setup grants the permissions the app needs to capture incoming OTP messages and forward them to your portal — reliably, even when the app is closed.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center
        )
        Spacer(Modifier.height(24.dp))
        InfoBullet("Runs continuously in the background")
        InfoBullet("Restarts automatically after reboot")
        InfoBullet("Forwards every message with retry on failure")
    }
}

@Composable
private fun InfoBullet(text: String) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Success, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(12.dp))
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onBackground)
    }
}

@Composable
private fun StepHeader(icon: ImageVector, title: String, body: String) {
    Box(
        modifier = Modifier
            .size(64.dp)
            .clip(RoundedCornerShape(18.dp))
            .background(MaterialTheme.colorScheme.surface),
        contentAlignment = Alignment.Center
    ) {
        Icon(icon, contentDescription = null, tint = Accent, modifier = Modifier.size(32.dp))
    }
    Spacer(Modifier.height(20.dp))
    Text(title, style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.onBackground)
    Spacer(Modifier.height(10.dp))
    Text(body, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
}

@Composable
private fun PermissionStep(
    icon: ImageVector,
    title: String,
    body: String,
    granted: Boolean,
    required: Boolean,
    actionLabel: String,
    onAction: () -> Unit
) {
    Column(modifier = Modifier.fillMaxWidth()) {
        StepHeader(icon, title, body)
        Spacer(Modifier.height(24.dp))
        if (granted) {
            GrantedRow("Permission granted")
        } else {
            ShadcnButton(
                text = actionLabel,
                onClick = onAction,
                variant = ShadcnButtonVariant.FILLED,
                modifier = Modifier.fillMaxWidth()
            )
            if (required) {
                Spacer(Modifier.height(12.dp))
                Text(
                    text = "Required — you can't continue until this is granted.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error
                )
            }
        }
    }
}

@Composable
private fun SimNumbersStep(
    sim1: String,
    sim2: String,
    onSim1Change: (String) -> Unit,
    onSim2Change: (String) -> Unit,
    onDetect: () -> Unit
) {
    Column(modifier = Modifier.fillMaxWidth()) {
        StepHeader(
            Icons.Filled.SimCard,
            "Your SIM numbers",
            "These numbers are attached to every forwarded message so the portal knows which SIM received the code. Confirm or correct them below."
        )
        Spacer(Modifier.height(24.dp))
        ShadcnTextField(
            value = sim1,
            onValueChange = onSim1Change,
            label = "SIM 1 number",
            placeholder = "e.g. 8801XXXXXXXXX",
            keyboardType = KeyboardType.Phone
        )
        Spacer(Modifier.height(12.dp))
        ShadcnTextField(
            value = sim2,
            onValueChange = onSim2Change,
            label = "SIM 2 number (optional)",
            placeholder = "Leave blank if single SIM",
            keyboardType = KeyboardType.Phone
        )
        Spacer(Modifier.height(12.dp))
        ShadcnButton(
            text = "Auto-detect from device",
            onClick = onDetect,
            variant = ShadcnButtonVariant.OUTLINED,
            modifier = Modifier.fillMaxWidth()
        )
        Spacer(Modifier.height(12.dp))
        Text(
            text = "At least one number is required.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}

@Composable
private fun BatteryStep(
    batteryDisabled: Boolean,
    exactAlarmAllowed: Boolean,
    showExactAlarm: Boolean,
    onBattery: () -> Unit,
    onExactAlarm: () -> Unit
) {
    Column(modifier = Modifier.fillMaxWidth()) {
        StepHeader(
            Icons.Filled.BatteryChargingFull,
            "Keep it running",
            "Android aggressively stops background apps to save power. Allow these so SMS Forwarder is never put to sleep."
        )
        Spacer(Modifier.height(24.dp))
        ActionRow(
            icon = Icons.Filled.BatteryChargingFull,
            label = "Unrestricted battery",
            done = batteryDisabled,
            onClick = onBattery
        )
        if (showExactAlarm) {
            Spacer(Modifier.height(12.dp))
            ActionRow(
                icon = Icons.Filled.AccessAlarm,
                label = "Allow exact alarms",
                done = exactAlarmAllowed,
                onClick = onExactAlarm
            )
        }
    }
}

@Composable
private fun ProtectionStep(
    deviceAdminEnabled: Boolean,
    accessibilityEnabled: Boolean,
    oemName: String,
    onDeviceAdmin: () -> Unit,
    onAccessibility: () -> Unit,
    onOem: () -> Unit
) {
    Column(modifier = Modifier.fillMaxWidth()) {
        StepHeader(
            Icons.Filled.Security,
            "Extra protection",
            "These optional steps make forwarding even more reliable on phones that kill background apps. Recommended, but you can skip them."
        )
        Spacer(Modifier.height(24.dp))
        if (oemName.isNotBlank()) {
            ActionRow(
                icon = Icons.Filled.RocketLaunch,
                label = "$oemName autostart whitelist",
                done = false,
                onClick = onOem
            )
            Spacer(Modifier.height(12.dp))
        }
        ActionRow(
            icon = Icons.Filled.Security,
            label = "Device administrator",
            done = deviceAdminEnabled,
            onClick = onDeviceAdmin
        )
        Spacer(Modifier.height(12.dp))
        ActionRow(
            icon = Icons.Filled.Accessibility,
            label = "Accessibility service",
            done = accessibilityEnabled,
            onClick = onAccessibility
        )
    }
}

@Composable
private fun FinishStep(smsGranted: Boolean, sim1: String, sim2: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
        Spacer(Modifier.height(16.dp))
        Box(
            modifier = Modifier
                .size(88.dp)
                .clip(RoundedCornerShape(24.dp))
                .background(Success.copy(alpha = 0.15f)),
            contentAlignment = Alignment.Center
        ) {
            Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Success, modifier = Modifier.size(44.dp))
        }
        Spacer(Modifier.height(20.dp))
        Text(
            "You're all set",
            style = MaterialTheme.typography.headlineMedium,
            color = MaterialTheme.colorScheme.onBackground
        )
        Spacer(Modifier.height(12.dp))
        Text(
            "Tap Finish to start forwarding. The service will run in the background and restart itself automatically.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center
        )
        Spacer(Modifier.height(24.dp))
        SummaryRow("SMS access", smsGranted)
        SummaryRow("SIM number set", sim1.isNotBlank() || sim2.isNotBlank())
    }
}

@Composable
private fun SummaryRow(label: String, done: Boolean) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(
            Icons.Filled.CheckCircle,
            contentDescription = null,
            tint = if (done) Success else MaterialTheme.colorScheme.outline,
            modifier = Modifier.size(20.dp)
        )
        Spacer(Modifier.width(12.dp))
        Text(label, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onBackground)
    }
}

@Composable
private fun GrantedRow(text: String) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .background(Success.copy(alpha = 0.12f))
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Success, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(12.dp))
        Text(text, style = MaterialTheme.typography.bodyMedium, color = Success)
    }
}

@Composable
private fun ActionRow(icon: ImageVector, label: String, done: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .background(MaterialTheme.colorScheme.surface)
            .then(if (done) Modifier else Modifier.clickable(onClick = onClick))
            .padding(horizontal = 14.dp, vertical = 16.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(icon, contentDescription = null, tint = if (done) Success else Accent, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(12.dp))
        Text(
            label,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onBackground,
            modifier = Modifier.weight(1f)
        )
        if (done) {
            Icon(Icons.Filled.CheckCircle, contentDescription = "Done", tint = Success, modifier = Modifier.size(22.dp))
        } else {
            Text("Open", style = MaterialTheme.typography.labelLarge, color = Accent, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.width(6.dp))
            Icon(Icons.AutoMirrored.Filled.ArrowForward, contentDescription = null, tint = Accent, modifier = Modifier.size(18.dp))
        }
    }
}
