# Flutter app setup, editing and release

This is the mobile client for the separate PHP API. Start with the
[package overview](../README.md) and [backend guide](../backend/README.md).
The Flutter app can run without a backend in **simulated demo mode**, but a
live release requires a reachable HTTPS API and your own service accounts.

## Requirements and supplied platforms

- Flutter and Dart SDKs, Android Studio/Android SDK with Java 17 for Android;
  macOS and Xcode for an iOS build. Run `flutter doctor -v` to check your host.
- `pubspec.yaml` declares Flutter >=3.27 and Dart >=3.6, but the **included
  `pubspec.lock`** currently resolves packages requiring **Flutter >=3.38.4
  and Dart >=3.11**. Use a matching newer SDK (Flutter 3.41.7 was used for a
  local Android release), or deliberately re-resolve/test dependencies if
  you need an older toolchain. Do not blindly run a major-version upgrade on
  an existing release.
- An Android project under `android/` and an experimental generated web
  preview under `web/` are present. No `ios/` project is included; generate
  it on a Mac with Xcode and configure iOS signing/permissions separately.
  Browser push is disabled (`PushService` returns on web); the web preview is
  not a tested production product.

From `app/`:

```bash
flutter pub get
flutter analyze
flutter test
flutter devices
flutter run -d YOUR_DEVICE_ID
```

Use `flutter devices` to find an Android device ID. The included tests cover
indicator behavior, formatting, JSON parsing, demo stats and one legal
widget; they do not simulate a real backend or a phone release. `flutter
analyze` may report deprecations/style notices on newer SDKs.

If the Android folder is missing, run the following from `app/`; the existing
Android project does not need to be regenerated to build it. On a Mac,
generate the missing iOS folder with the second command, then check your
bundle ID:

```bash
flutter create --org com.yourcompany --project-name forex_signals --platforms android .
flutter create --org com.yourcompany --project-name forex_signals --platforms ios .
```

Use an organization you control, and run only the command for the platform
you need. The supplied `setup.sh` is an older helper with the **seller-owned**
`--org com.forexsignalslive`; do not run it unchanged on a buyer rebrand. It also
sets minSdk 23, whereas this checked-in Android project uses Flutter's
minimum SDK. Its permission insertion only runs if INTERNET is absent, and
its package-visibility insertion only runs if no `<queries>` element exists.
Current Android manifest already contains INTERNET,
POST_NOTIFICATIONS, HTTPS/mailto/WhatsApp queries and the FCM channel ID.
Generated files can change when Flutter migrates them; after regeneration,
verify that signing and these manifest entries still exist. Do not copy
`android/local.properties` from someone else's machine as an SDK setup guide.

## Demo versus live mode

`lib/main.dart` selects `DemoApi` if `AppConfig.apiBaseUrl` is empty and
`RemoteApi` otherwise (`lib/core/config.dart`). The URL is normally supplied
at compile/run time:

```bash
flutter run -d YOUR_DEVICE_ID
flutter run -d YOUR_DEVICE_ID --dart-define=API_URL=https://api.example.com
```

The first command uses **fabricated prices and signals** generated locally in
`lib/data/demo_api.dart`; demo login accepts a valid email and a password of
at least eight characters, and demo verification/reset uses code `123456`.
There is no real email or broker connection in demo mode. Demo results must
not be sold or shown as real trading performance.

For live mode, deploy the backend first. If the API is directly at the
subdomain root, use `https://api.example.com`. If the API is at
`public_html/api/index.php`, use `https://example.com/api`. Do **not** append
`/health` to `API_URL`, and do not put a trailing path in the app that the
server did not deploy. Use HTTPS because the app sends passwords and JWTs;
the current Dart code does not enforce HTTPS for you. The same public base
URL belongs in server `config.php` as `base_url` for email links.

Live calls time out after 20 seconds. An unreachable API can still compile
into an APK; compilation does not prove DNS, certificate, login, cron or mail
works. Once deployed, test registration, verification code, login, signals,
results, prices and profile changes on a staging account. With a fresh
database, empty Signals/Results screens are normal until cron has produced
and tracked actual signals.

## What buyers can edit

| Change | Edit/check |
| --- | --- |
| App title, support email, version label, displayed pair list | `lib/core/config.dart`; also `pubspec.yaml` for Android/iOS versionCode/versionName. Keep server `pairs` aligned with the app. |
| Colors, type and reusable visuals | `lib/core/theme.dart`, `lib/widgets/` and `lib/screens/`. |
| Startup/onboarding copy | `lib/screens/onboarding_screen.dart`, `lib/screens/auth_screens.dart`. |
| API request/response contract | `lib/data/api.dart`, `lib/data/remote_api.dart`, `lib/models/models.dart`, and the corresponding PHP handlers. |
| Offline demonstration values | `lib/data/demo_api.dart` and `lib/engine/engine.dart`; changes here do not alter server-generated live signals. |
| In-app company name, WhatsApp, LinkedIn, email, coffee and exit prompt | `lib/services/developer.dart`, `lib/widgets/developer_widgets.dart` and relevant screens; remove or replace the seller's links. |
| Terms, privacy and risk text | `lib/screens/legal_screen.dart`; publish a matching privacy-policy URL on your own site and have local counsel review the claims. |
| Android package identity | `android/app/build.gradle.kts` `applicationId`/`namespace`, Kotlin `MainActivity.kt` package/folder, Firebase app registration, and any store listing. Change **before** publishing; changes later break the update identity. |
| Android display label, notification permission and launcher icons | `android/app/src/main/AndroidManifest.xml` and `android/app/src/main/res/mipmap-*/ic_launcher.png`. |
| Android native launch background | `android/app/src/main/res/drawable*/launch_background.xml`. |
| Email footer brand and server promo switches | Backend `src/config.php` keys `app_name`, `developer`, `promo` and `src/EmailTemplates.php`. `developer` in PHP does **not** replace links compiled into the app. |

The Android mipmap launcher images now match the in-app candlestick mark and
`../store-assets/play-icon.png`. A **previously built** AAB still has the old
icon; rebuild and upload the next version after changing these source images.
The generated web icons remain the stock Flutter logo and need replacement
if you distribute a web version. Use artwork you have rights to use before
selling or publishing a rebrand.
Search all source, listing copy and notifications for the seller's company,
contact links and domain. The in-app `Developer.promo` flags may be fetched
from `/health` to hide/show links, but their target URLs remain compiled into
`lib/services/developer.dart`. The app and server each have a pair list:
adding a market requires the backend provider to support it, a matching Dart
pair, appropriate pip/price formatting, and demo data if you use demo mode.
Changing the server timeframe alone does not turn demo history into live
history. App version increments need a rebuild and a new signed release.

## Account and screen flow

`lib/main.dart` initializes state providers and selects splash, first-run
onboarding, login or the authenticated home shell. The home tabs are Signals
(active/history and detail chart), Results (statistics and period reports),
Markets (stored prices/watchlist), Tools (pip and position-size calculators)
and Profile (name/password, notification settings, legal/help, logout and
account deletion). Screens live under `lib/screens/`; stores in `lib/state/`;
the API interface and both implementations under `lib/data/`.

Live auth uses bearer JWTs. The token is kept in `flutter_secure_storage`;
onboarding state and cached profile use `shared_preferences`. Login is
possible before email verification, but only verified users are eligible
for backend alert/report emails. The app may show a cached profile when an
existing session encounters some offline failures, so do not mistake a
visible shell for fresh backend prices. All users currently receive the same
shared signals: this package does **not** implement a paid subscription,
admin dashboard, private feed or broker execution account.

## Optional Firebase push

Email alerts and the Android local polling fallback do not require Firebase;
timely **server-initiated FCM push** does. Create your
own Firebase project, register the actual Android application ID (and iOS
bundle ID when applicable), and configure the app with FlutterFire on a
supported toolchain:

```bash
dart pub global activate flutterfire_cli
flutterfire configure
```

Inspect the generated `google-services.json` (Android), optional
`GoogleService-Info.plist` (iOS), `firebase_options.dart` and native plugin
integration. This checkout does not include those files or a Google Services
Gradle plugin; install/apply the required Google Services integration for
your FlutterFire setup, rather than assuming the CLI command alone enables
push. If your FlutterFire setup uses generated Dart options, update
`PushService.init()` in `lib/services/push_service.dart` to initialize with
those options where needed. Verify that Android creates the `signals`
notification channel referenced by its manifest and the server's FCM
payload. Test a real topic message on each target device before advertising
push. On iOS,
enable Push Notifications and Background Modes/Remote notifications in
Xcode, configure APNs in Firebase, and test on hardware. The backend also
needs a private service account **from the same Firebase project**; see its
guide. Never put that private JSON file in the app or a public archive.

If `Firebase.initializeApp()` fails, `PushService` catches that initialization
error and disables push; later messaging/permission failures are not all
isolated, so test startup and login with push configured and with network
errors. With it enabled, the app asks for
permission and subscribes to per-pair signal/result topics and a reports
topic according to user settings. FCM topics are shared and must not be used
as authorization for premium/private trading data.

## Android background signal fallback and history filtering

If client Firebase initialization fails, or the server reports push disabled,
`lib/services/background_signal_service.dart` schedules one Android WorkManager
task after login and notification permission. About every 20 minutes (subject
to OS scheduling), a background isolate reads the secured session token,
fetches `/me`, running signals and the newest closed-signals page, respects
the user's pair, signal and result preferences, and records Open, TP1 and
Closed notification events separately per signal. A signal that opened and
closed between polls is labeled closed, not presented as a new trade entry.
The first successful poll establishes a baseline without re-alerting old
signals. The task is cancelled on logout, when both signal and result alerts
are switched off, and
when FCM is configured on both app and server. It does **not** replace the
backend cron, fetch Twelve Data directly, or issue broker orders.

WorkManager is best-effort: Android may delay work for battery/network
reasons; force-stopping the app blocks it until relaunch; a burst exceeding
the newest closed-signals page (20 items) can still be missed. FCM remains the recommended
path for time-sensitive alerts. This fallback currently targets **Android**;
there is no iOS app project or native iOS background-task setup in this
package. `android/app/build.gradle.kts` enables core-library desugaring for
`flutter_local_notifications`; the manifest already has POST_NOTIFICATIONS.
Use a monochrome drawable status icon instead of the launcher icon before
shipping if your devices render the notification icon poorly. Re-test Android
13+ permission denial, terminated/restarted processes and FCM deduplication
on real hardware. Review your Play Console Data safety answers when changing
SDKs or background collection.

History in `lib/screens/signals_screen.dart` collapses **small negative
expired** signals below 0.5 of their original stop-loss distance. An
accessible toggle restores all results, and Load more remains available
when an entire page is collapsed. Actual stop-loss hits and results with
unknown risk are never hidden. This is a display-only filter: backend data,
pagination and aggregate win/loss statistics are unchanged. It cannot
improve historical performance or correct legacy TP1 accounting.

## Android signing and builds

**Make your own key before your first release.** Do not reuse the seller's
keystore, a debug key or an unsigned APK. Back up the keystore, alias and
passwords securely: losing them can prevent updates under the same app
identity. Create a private key outside the project with Java `keytool`, for
example (the command prompts for its password and certificate details):

```bash
keytool -genkeypair -v -keystore "$HOME/my-upload-key.jks" -storetype PKCS12 -keyalg RSA -keysize 3072 -validity 10000 -alias upload
```

The keystore destination's parent directory must already exist. Use the
**same absolute path** below in `FX_RELEASE_STORE_FILE`, and quote paths
containing spaces. On Windows, `keytool.exe` usually ships with Android
Studio's bundled JDK. If `keytool` is not on PATH in PowerShell, invoke it
with its full path and create the **same filename** used in the build example:

```powershell
& 'C:\Program Files\Android\Android Studio\jbr\bin\keytool.exe' -genkeypair -v -keystore "$env:USERPROFILE\my-upload-key.jks" -storetype PKCS12 -keyalg RSA -keysize 3072 -validity 10000 -alias upload
```

The Gradle release build reads **four environment variables**, not a
`key.properties` file:

| Variable | Meaning |
| --- | --- |
| `FX_RELEASE_STORE_FILE` | Absolute path to the private `.jks` keystore. |
| `FX_RELEASE_STORE_PASSWORD` | Password unlocking the keystore. |
| `FX_RELEASE_KEY_ALIAS` | The alias passed to `keytool -alias` (for example `upload`); not a password. |
| `FX_RELEASE_KEY_PASSWORD` | Password unlocking that key; PKCS12 often uses the same value as the store password. |

Set them in the **same shell that starts Flutter**, not in
`android/build.gradle.kts`, your source files or a marketplace archive.
The current `android/app/build.gradle.kts` intentionally refuses release
tasks if any variable is missing. For Windows PowerShell with a single
store/key password:

```powershell
$env:FX_RELEASE_STORE_FILE = "$env:USERPROFILE\my-upload-key.jks"
$env:FX_RELEASE_KEY_ALIAS = 'upload'
$secure = Read-Host 'Keystore password' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:FX_RELEASE_STORE_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    $env:FX_RELEASE_KEY_PASSWORD = $env:FX_RELEASE_STORE_PASSWORD
    flutter build apk --release --dart-define=API_URL=https://api.example.com
    # For Google Play, uncomment this line while the same signing variables are set:
    # flutter build appbundle --release --dart-define=API_URL=https://api.example.com
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    $env:FX_RELEASE_STORE_PASSWORD = $null
    $env:FX_RELEASE_KEY_PASSWORD = $null
}
```

If the key password differs, prompt for it separately instead of copying the
store password. Do not paste a real password in your command history. For
Google Play, run the commented `flutter build appbundle` line **inside the
same `try` block** (or repeat the password prompt/setup in a new shell);
the `finally` block removes the password variables afterward.

The APK is normally `build/app/outputs/flutter-apk/app-release.apk`; an AAB
is normally `build/app/outputs/bundle/release/app-release.aab`. Verify the
final certificate and test on a device before distributing, for example
from `app/` with the Android SDK command
`apksigner verify --verbose --print-certs build/app/outputs/flutter-apk/app-release.apk`.
Check an AAB's signing separately before uploading it. The
current `android/app/build.gradle.kts` gets minimum/target SDK defaults from
Flutter; a previous local build produced minSdk 24 and targetSdk 36. Check
the generated manifest/Play requirements on **your** toolchain rather than
assuming the older `setup.sh` comment about minSdk 23 is the compiled value.
For iOS, create `ios/`, set its bundle identity, signing team, Firebase/APNs
configuration and privacy declarations in Xcode; an Android APK does not
build or sign the iOS app.

## Release checklist and troubleshooting

- Run `flutter pub get`, `flutter analyze`, and `flutter test` on the SDK you
  will use to release. Build a signed APK/AAB with the exact live HTTPS URL.
- Verify the backend's `/health`, account registration/email, and protected
  API routes on the actual domain; DNS and TLS must already work. Install on
  a real Android device and exercise login, reports, markets and deletion.
- Replace all seller branding, stock icons, support details, privacy policy,
  terms and risk disclosures. Configure and test Firebase separately if
  push notifications are an advertised feature.
- If release signing fails, check all four `FX_RELEASE_*` names, keystore
  path, alias and password in the **same terminal** as `flutter build`.
- If the app shows simulated results, `API_URL` was empty for that build.
  If login fails with a connection error, check DNS, HTTPS, the exact base
  path and server health. If `/health` works but login fails, inspect the
  response status, account/password, database, JWT secret and PHP logs.
  Check SMTP separately for verification, resend and password-reset email.
- Never advertise current demo results or the historical synthetic backend
  test output as verified live investment performance. Review the backend
  tracker/accounting issues and applicable financial regulations first.
