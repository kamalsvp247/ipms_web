package site.mashmininet.smsforwarder.ui.theme

import android.app.Activity
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

private val BlitzLightColorScheme = lightColorScheme(
    primary              = Primary,
    onPrimary            = PrimaryForeground,
    secondary            = Primary,
    onSecondary          = PrimaryForeground,
    tertiary             = Primary,
    onTertiary           = PrimaryForeground,
    background           = Background,
    onBackground         = TextPrimary,
    surface              = SurfaceCard,
    onSurface            = TextPrimary,
    surfaceVariant       = Surface,
    onSurfaceVariant     = TextMuted,
    outline              = Border,
    outlineVariant       = Border,
    error                = Destructive,
    onError              = DestructiveForeground,
    inverseSurface       = Primary,
    inverseOnSurface     = PrimaryForeground,
    surfaceContainerHigh = Surface,
    surfaceContainer     = Surface,
    surfaceContainerLow  = Background,
    surfaceTint          = Color.Transparent
)

@Composable
fun MashminiSMSForwarderTheme(
    content: @Composable () -> Unit
) {
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as Activity).window
            window.statusBarColor = Background.toArgb()
            window.navigationBarColor = Background.toArgb()
            WindowCompat.getInsetsController(window, view).apply {
                isAppearanceLightStatusBars = true
                isAppearanceLightNavigationBars = true
            }
        }
    }

    MaterialTheme(
        colorScheme = BlitzLightColorScheme,
        typography = AppTypography,
        content = content
    )
}
