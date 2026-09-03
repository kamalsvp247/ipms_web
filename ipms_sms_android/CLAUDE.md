# SMS Forwarder — Project Memory

## Project Overview
Android app that intercepts every incoming SMS and POSTs it to the IPMS portal's
OTP ingest endpoint. The portal parses IVACBD messages, stores them in
`otp_codes`, and feeds them to the booking bot race.

- **Package**: `site.mashmininet.smsforwarder`
- **Language**: Kotlin
- **Min SDK**: 26 (Android 8.0)
- **Target/Compile SDK**: 36
- **UI Framework**: Jetpack Compose + Material 3
- **Architecture**: MVVM + Repository Pattern
- **Storage**: Jetpack DataStore Preferences
- **Networking**: Retrofit2 + OkHttp
- **Design**: shadcn/ui-inspired dark-first theme (indigo accent #6366F1)

## Portal API

- **Endpoint**: `POST https://ipms.senda.fit/otp`  — hardcoded in `data/api/RetrofitClient.kt`, not user-configurable
- **Request body**: `{ "phone": "<sim owner number>", "msg": "<sms body>" }`
- `phone` is the SIM owner's number from settings (SIM1 or SIM2, picked by the slot
  that received the SMS)
- `msg` is the raw SMS body — no client-side filtering; the portal detects IVACBD
- **Response**: `{ id, phone, otp_code, is_ivacbd, fetched_at }` — success is signalled
  by HTTP 2xx; the client does not inspect the body
- **Retry**: 3 attempts with exponential backoff (1s → 2s → 4s) on HTTP/network failure
- **Skip condition**: SIM owner number for the receiving slot is blank → status FAILED, no POST

## Build System
- **AGP**: 8.13.2 (uses `compileSdk { version = release(36) }` syntax)
- **Kotlin**: 2.0.21 with Compose compiler plugin
- **Version Catalog**: `gradle/libs.versions.toml`
- **Build command**: `./gradlew assembleDebug` (Linux) / `.\gradlew.bat assembleDebug` (Windows)

## Key Architecture Decisions
- **Manual DI** via companion object singletons (no Hilt)
- **Fixed `@POST("otp")`** in Retrofit — endpoint is hardcoded, base URL `https://ipms.senda.fit/`
- **ContentObserver** wrapped in `callbackFlow` for live SMS inbox updates; uses scoped coroutine to prevent leaks
- **Dual-path forwarding**: manifest SmsReceiver enqueues WorkManager (guaranteed delivery); dynamic receiver in service does immediate in-process call; `SmsRepository.inProgressIds` deduplicates concurrent triggers
- **HTTP classification**: 4xx (except 429) → permanent failure, no retry; 429/5xx/network error → retry (3 in-process, then WorkManager with exponential backoff)
- **Forward log** capped at 500 entries to prevent DataStore overflow
- **"Never kill" service strategy**: START_STICKY + indefinite WakeLock + AlarmManager restart + WorkManager watchdog (15min) + Accessibility Service + Device Admin
- **AlarmManager API guard**: API 31+ → `canScheduleExactAlarms()` → `setExactAndAllowWhileIdle()`; API 23-30 → `setAndAllowWhileIdle()`; older → `set()`
- **SIM slot detection**: multi-key fallback (`slot`, `phone`, `simId`, `sim_slot`, `android.telephony.extra.SLOT_INDEX`) for OEM compatibility
- **OEM power management**: `SettingsViewModel.resolveOemPowerManagerIntent()` probes known component names for Xiaomi, OPPO, Realme, Vivo, Huawei, Samsung, OnePlus, Asus, Meizu

## Project Structure
```
app/src/main/java/site/mashmininet/smsforwarder/
├── MainActivity.kt
├── SmsForwarderApp.kt            (Application + WorkManager watchdog)
├── navigation/AppNavigation.kt   (Bottom nav: Messages + Settings)
├── ui/
│   ├── theme/ (Color, Type, Theme)
│   ├── components/ (ShadcnButton, ShadcnTextField, SmsCard, StatusBadge, PermissionToggleRow)
│   ├── screens/ (OTPScreen, SettingsScreen)
│   └── viewmodel/ (SmsViewModel, SettingsViewModel)
├── data/
│   ├── model/ (SmsMessage, SmsForwardRequest, SmsForwardResponse)
│   ├── repository/ (SmsRepository, PreferencesRepository)
│   └── api/ (ApiService, RetrofitClient)
├── service/ (SmsForegroundService, SmsAccessibilityService)
├── receiver/ (SmsReceiver, SmsBootReceiver, ServiceRestartReceiver)
├── worker/ (SmsForwardWorker)
└── admin/ (MyDeviceAdminReceiver)
```

## Screens
1. **OTP/Messages Screen** — SMS inbox with SIM filter chips, message cards, shimmer loading, bottom sheet detail view
2. **Settings Screen** — 3 sections: SIM Configuration, App Permissions (9 items + OEM row if manufacturer detected), Danger Zone

## Important Notes
- `buildConfig = true` must be enabled in `buildFeatures` for `BuildConfig.DEBUG` to resolve
- The SMS receiver has priority 999 in AndroidManifest; dynamic receiver in service has priority 998
- The foreground service type is `dataSync`
- Default theme is dark mode
- Do not reintroduce a user-configurable API URL — the portal endpoint is fixed
- `SCHEDULE_EXACT_ALARM` and `USE_EXACT_ALARM` are declared in the manifest for AlarmManager on API 31+
- WakeLock is indefinite (no timeout) — released in `onDestroy()` only
