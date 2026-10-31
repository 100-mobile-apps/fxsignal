plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

val releaseStoreFile = System.getenv("FX_RELEASE_STORE_FILE")
val releaseStorePassword = System.getenv("FX_RELEASE_STORE_PASSWORD")
val releaseKeyAlias = System.getenv("FX_RELEASE_KEY_ALIAS")
val releaseKeyPassword = System.getenv("FX_RELEASE_KEY_PASSWORD")
val releaseSigning = listOf(releaseStoreFile, releaseStorePassword, releaseKeyAlias, releaseKeyPassword)
    .all { !it.isNullOrBlank() }
if (gradle.startParameter.taskNames.any { it.contains("Release", ignoreCase = true) }) {
    require(releaseSigning) {
        "Release signing requires FX_RELEASE_STORE_FILE, FX_RELEASE_STORE_PASSWORD, " +
            "FX_RELEASE_KEY_ALIAS and FX_RELEASE_KEY_PASSWORD."
    }
}

android {
    namespace = "com.forexsignalslive.forex_signals"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    signingConfigs {
        if (releaseSigning) {
            create("release") {
                storeFile = file(releaseStoreFile!!)
                storePassword = releaseStorePassword
                keyAlias = releaseKeyAlias
                keyPassword = releaseKeyPassword
            }
        }
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.forexsignalslive.forex_signals"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            if (releaseSigning) signingConfig = signingConfigs.getByName("release")
        }
    }
}

flutter {
    source = "../.."
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}
