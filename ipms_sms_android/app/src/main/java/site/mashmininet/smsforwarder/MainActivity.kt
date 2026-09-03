package site.mashmininet.smsforwarder

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import kotlinx.coroutines.flow.first
import site.mashmininet.smsforwarder.data.repository.PreferencesRepository
import site.mashmininet.smsforwarder.navigation.AppNavigation
import site.mashmininet.smsforwarder.service.SmsForegroundService
import site.mashmininet.smsforwarder.ui.screens.SetupScreen
import site.mashmininet.smsforwarder.ui.theme.MashminiSMSForwarderTheme
import site.mashmininet.smsforwarder.util.ServiceLauncher

/**
 * Single-activity host. Routes to the first-launch setup wizard until setup is
 * complete, then to the main app. Ensures the forwarding service is running
 * whenever the app is opened and the user has enabled it.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MashminiSMSForwarderTheme {
                RootContent()
            }
        }
    }

    override fun onResume() {
        super.onResume()
        // Self-heal: if setup is done and the service died while we were away, revive it.
        val prefs = PreferencesRepository.getInstance(applicationContext)
        if (!SmsForegroundService.isRunning) {
            kotlinx.coroutines.runBlocking {
                if (prefs.getSetupCompleteSync()) ServiceLauncher.start(applicationContext)
            }
        }
    }
}

@Composable
private fun RootContent() {
    val context = LocalContext.current
    val prefs = remember(context) { PreferencesRepository.getInstance(context) }

    // Tri-state: null while the persisted flag loads, then true/false.
    val persistedSetupComplete by produceState<Boolean?>(initialValue = null, prefs) {
        value = prefs.setupComplete.first()
    }

    // Survives config changes so finishing the wizard sticks during the transition.
    var justFinished by rememberSaveable { mutableStateOf(false) }

    when {
        justFinished || persistedSetupComplete == true -> AppNavigation()
        persistedSetupComplete == false -> SetupScreen(onSetupComplete = { justFinished = true })
        else -> { /* brief loading frame while the flag resolves — render nothing */ }
    }
}
