#!/usr/bin/env bash
# Creates the Android/iOS folders and applies the settings this app needs.
set -e
cd "$(dirname "$0")"
flutter create --org com.forexsignalslive --project-name forex_signals --platforms android,ios .

M=android/app/src/main/AndroidManifest.xml
grep -q "android.permission.INTERNET" "$M" || \
  perl -0pi -e 's|(<manifest[^>]*>)|$1\n    <uses-permission android:name="android.permission.INTERNET"/>\n    <uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>|' "$M"
perl -pi -e 's|android:label="forex_signals"|android:label="Forex Signals"|' "$M"
# Default notification channel used by the server's FCM messages
grep -q "default_notification_channel_id" "$M" || \
  perl -0pi -e 's|(<application[^>]*>)|$1\n        <meta-data android:name="com.google.firebase.messaging.default_notification_channel_id" android:value="signals"/>|' "$M"

# Let the app open links (WhatsApp, LinkedIn, Buy Me a Coffee, email) on Android 11+
grep -q "<queries>" "$M" || \
  perl -0pi -e 's|(</manifest>)|    <queries>\n        <intent><action android:name="android.intent.action.VIEW"/><data android:scheme="https"/></intent>\n        <intent><action android:name="android.intent.action.SENDTO"/><data android:scheme="mailto"/></intent>\n        <package android:name="com.whatsapp"/>\n        <package android:name="com.whatsapp.w4b"/>\n    </queries>\n$1|' "$M"

# flutter_secure_storage needs minSdk 23
for f in android/app/build.gradle.kts android/app/build.gradle; do
  [ -f "$f" ] && perl -pi -e 's/minSdk\s*=\s*flutter\.minSdkVersion/minSdk = 23/; s/minSdkVersion\s+flutter\.minSdkVersion/minSdkVersion 23/' "$f"
done

flutter pub get
echo
echo "Done."
echo "  Demo mode:   flutter run"
echo "  Live server: flutter run --dart-define=API_URL=https://yourdomain.com/api"
