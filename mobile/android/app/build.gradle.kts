plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Phase 19 — release signing reads credentials from environment variables or
// Gradle properties ONLY. No keystore, password or alias is committed to the
// repository. When the credentials are absent the release build falls back to
// debug signing for LOCAL VERIFICATION ONLY — never for store uploads.
val releaseKeystorePath: String? = System.getenv("FFARENA_KEYSTORE_PATH")
    ?: (project.findProperty("ffarena.keystorePath") as String?)
val releaseKeystorePassword: String? = System.getenv("FFARENA_KEYSTORE_PASSWORD")
    ?: (project.findProperty("ffarena.keystorePassword") as String?)
val releaseKeyAlias: String? = System.getenv("FFARENA_KEY_ALIAS")
    ?: (project.findProperty("ffarena.keyAlias") as String?)
val releaseKeyPassword: String? = System.getenv("FFARENA_KEY_PASSWORD")
    ?: (project.findProperty("ffarena.keyPassword") as String?)

android {
    namespace = "com.ffarena.ffarena_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.ffarena.ffarena_mobile"
        // firebase_messaging requires API 21+; flutter.minSdkVersion satisfies
        // this on current Flutter toolchains.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName

        // Android App Links host. Replace with the production web origin
        // before release; it must match /.well-known/assetlinks.json.
        manifestPlaceholders["appLinkHost"] =
            System.getenv("FFARENA_APP_LINK_HOST") ?: "ffarena.example.com"
    }

    // Phase 19 — release channels. dev/staging get a distinct application id
    // so they can be installed alongside the production build.
    flavorDimensions += "env"
    productFlavors {
        create("dev") {
            dimension = "env"
            applicationIdSuffix = ".dev"
        }
        create("staging") {
            dimension = "env"
            applicationIdSuffix = ".staging"
        }
        create("prod") {
            dimension = "env"
        }
    }

    buildTypes {
        release {
            // Cleartext HTTP is disabled in release builds.
            manifestPlaceholders["usesCleartextTraffic"] = "false"

            signingConfig = if (
                releaseKeystorePath != null &&
                releaseKeystorePassword != null &&
                releaseKeyAlias != null &&
                releaseKeyPassword != null
            ) {
                signingConfigs.create("release") {
                    storeFile = file(releaseKeystorePath)
                    storePassword = releaseKeystorePassword
                    keyAlias = releaseKeyAlias
                    keyPassword = releaseKeyPassword
                }
            } else {
                // Honest fallback for local verification only.
                signingConfigs.getByName("debug")
            }
        }
        debug {
            manifestPlaceholders["usesCleartextTraffic"] = "true"
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
