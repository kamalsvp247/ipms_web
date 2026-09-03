package site.mashmininet.smsforwarder.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Circle
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import site.mashmininet.smsforwarder.data.model.ForwardStatus
import site.mashmininet.smsforwarder.ui.theme.Destructive
import site.mashmininet.smsforwarder.ui.theme.DestructiveBackground
import site.mashmininet.smsforwarder.ui.theme.Success
import site.mashmininet.smsforwarder.ui.theme.SuccessBackground
import site.mashmininet.smsforwarder.ui.theme.Warning
import site.mashmininet.smsforwarder.ui.theme.WarningBackground

@Composable
fun StatusBadge(status: ForwardStatus, modifier: Modifier = Modifier) {
    val (label, icon, textColor, bgColor) = when (status) {
        ForwardStatus.DELIVERED -> StatusBadgeStyle("Delivered", Icons.Rounded.CheckCircle, Success,     SuccessBackground)
        ForwardStatus.PENDING   -> StatusBadgeStyle("Pending",   Icons.Rounded.HourglassTop, Warning,   WarningBackground)
        ForwardStatus.FAILED    -> StatusBadgeStyle("Failed",    Icons.Rounded.Cancel,       Destructive, DestructiveBackground)
    }

    Row(
        modifier = modifier
            .clip(RoundedCornerShape(6.dp))
            .background(bgColor)
            .padding(horizontal = 8.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp)
    ) {
        Icon(imageVector = icon, contentDescription = null, tint = textColor, modifier = Modifier.size(12.dp))
        Text(text = label, color = textColor, fontSize = 11.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.3.sp)
    }
}

private data class StatusBadgeStyle(
    val label: String,
    val icon: ImageVector,
    val textColor: Color,
    val bgColor: Color
)

@Composable
fun SimBadge(simSlot: Int, modifier: Modifier = Modifier) {
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(6.dp))
            .background(MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.3f))
            .padding(horizontal = 8.dp, vertical = 3.dp),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = "SIM ${simSlot + 1}",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            fontSize = 11.sp,
            fontWeight = FontWeight.Medium
        )
    }
}

@Composable
fun ServiceStatusBadge(isRunning: Boolean, modifier: Modifier = Modifier) {
    val dotColor = if (isRunning) Success else Destructive
    val statusText = if (isRunning) "Active" else "Stopped"

    Row(
        modifier = modifier
            .clip(RoundedCornerShape(6.dp))
            .background(if (isRunning) SuccessBackground else DestructiveBackground)
            .padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(5.dp)
    ) {
        Icon(imageVector = Icons.Rounded.Circle, contentDescription = null, tint = dotColor, modifier = Modifier.size(8.dp))
        Text(text = statusText, color = dotColor, fontSize = 12.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.3.sp)
    }
}
