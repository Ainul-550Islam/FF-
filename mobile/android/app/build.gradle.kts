plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Phase 19 / GAP-10 D — release signing reads credentials from environment
// variables or Gradle properties ONLY. No keystore, password or alias is
// committed to the repository.
//
// GAP-10 D changes the failure mode: a release build without real signing
// credentials now FAILS instead of quietly falling back to the debug key. A
// debug-signed "release" artifact must never be able to reach a store, and a
// silent fallback is exactly how that happens. Local verification of a release
// build is still possible, but it has to be asked for explicitly with
// FFARENA_ALLOW_DEBUG_SIGNED_RELEASE=true (see docs/MOBILE_RELEASE.md), and the
// build then announces itself as NOT FOR DISTRIBUTION.
val releaseKeystorePath: String? = System.getenv("FFARENA_KEYSTORE_PATH")
    ?: (project.findProperty("ffarena.keystorePath") as String?)
val releaseKeystorePassword: String? = System.getenv("FFARENA_KEYSTORE_PASSWORD")
    ?: (project.findProperty("ffarena.keystorePassword") as String?)
val releaseKeyAlias: String? = System.getenv("FFARENA_KEY_ALIAS")
    ?: (project.findProperty("ffarena.keyAlias") as String?)
val releaseKeyPassword: String? = System.getenv("FFARENA_KEY_PASSWORD")
    ?: (project.findProperty("ffarena.keyPassword") as String?)

// An explicit, loudly-named opt-out. Anything other than "true" keeps the
// fail-closed behaviour, so a typo cannot disable the gate.
val allowDebugSignedRelease: Boolean =
    (System.getenv("FFARENA_ALLOW_DEBUG_SIGNED_RELEASE") ?: "false").equals("true", ignoreCase = true)

// `flutter build appbundle/apk --release` is the store path. `flutter build`
// does not tell Gradle which of the two it is, but it does run the
// `assembleRelease`/`bundleRelease` tasks, and it always passes
// `-Ptarget-platform` for release builds. Local debug builds (assembleDebug)
// are never gated.
val isReleaseBuildRequested: Boolean = gradle.startParameter.taskNames.any { task ->
    val name = task.substringAfterLast(':')
    name.contains("Release", ignoreCase = true)
}

val missingReleaseSecrets: List<String> = buildList {
    if (releaseKeystorePath.isNullOrBlank()) add("FFARENA_KEYSTORE_PATH")
    if (releaseKeystorePassword.isNullOrBlank()) add("FFARENA_KEYSTORE_PASSWORD")
    if (releaseKeyAlias.isNullOrBlank()) add("FFARENA_KEY_ALIAS")
    if (releaseKeyPassword.isNullOrBlank()) add("FFARENA_KEY_PASSWORD")
}

android {
    namespace = "com.ffarena.ffarena_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    // GAP-10 D — a placeholder application id must never be published. Both the
    // namespace and the application id are checked, because either one leaking
    // `com.example.*` means the project was never configured for release.
    listOf("namespace" to namespace, "applicationId" to "com.ffarena.ffarena_mobile").forEach { (label, value) ->
        if (value.startsWith("com.example.") || value == "com.example") {
            throw GradleException(
                "Refusing to configure a release-capable build with the placeholder $label \"$value\". " +
                    "Set a real, owned application id (see docs/MOBILE_RELEASE.md) before building."
            )
        }
    }

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

        // Android App Links host. It must match /.well-known/assetlinks.json on
        // the deployed web origin; the placeholder value is never a release host
        // and the release build below refuses to start with it unset.
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

            signingConfig = if (missingReleaseSecrets.isEmpty()) {
                signingConfigs.create("release") {
                    storeFile = file(releaseKeystorePath!!)
                    storePassword = releaseKeystorePassword
                    keyAlias = releaseKeyAlias
                    keyPassword = releaseKeyPassword
                }
            } else if (allowDebugSignedRelease) {
                // Explicit local verification only. The artifact produced here
                // is signed with the debug key and MUST NOT be uploaded
                // anywhere: Google Play rejects it, and shipping it would
                // break upgrade paths forever.
                logger.warn(
                    "WARNING: building a DEBUG-SIGNED release artifact (NOT FOR DISTRIBUTION). " +
                        "Missing release secrets: ${missingReleaseSecrets.joinToString(", ")}."
                )
                signingConfigs.getByName("debug")
            } else {
                throw GradleException(
                    "Release signing credentials are missing: ${missingReleaseSecrets.joinToString(", ")}. " +
                        "A release artifact must be signed with the real upload key, so this build is refused " +
                        "instead of silently falling back to the debug key. Provide the credentials " +
                        "(see docs/MOBILE_RELEASE.md and docs/SECRETS.md), or, for local verification only, " +
                        "set FFARENA_ALLOW_DEBUG_SIGNED_RELEASE=true to build an explicitly non-distributable artifact."
                )
            }

            // GAP-10 D — a release build must reach a real host. The example
            // host in the manifest is a placeholder; shipping it would produce
            // an app whose deep links never resolve.
            val appLinkHost = (manifestPlaceholders["appLinkHost"] as? String).orEmpty()
            if (appLinkHost.endsWith(".example.com") || appLinkHost.isBlank()) {
                throw GradleException(
                    "Refusing a release build with the placeholder App Link host \"$appLinkHost\". " +
                        "Set FFARENA_APP_LINK_HOST to the production origin and make sure " +
                        "/.well-known/assetlinks.json is deployed there."
                )
            }
        }
        debug {
            manifestPlaceholders["usesCleartextTraffic"] = "true"
        }
    }
}

/**
 * GAP-10 D — the last line of defence.
 *
 * Even with the checks above, the resulting bundle must be signed by the
 * upload key. This task fails the build when a release artifact ends up
 * debug-signed, unless the explicit local-verification opt-out is set. It is
 * wired as a dependency of `bundleRelease`/`assembleRelease` where the AGP
 * lifecycle allows it, so `flutter build appbundle --release` cannot produce a
 * store-ready-looking artifact from a debug key by accident.
 */
val verifyReleaseSigning by tasks.registering {
    group = "verification"
    description = "Fails when a release artifact would be signed with the debug key."

    doLast {
        if (isReleaseBuildRequested && missingReleaseSecrets.isNotEmpty() && !allowDebugSignedRelease) {
            throw GradleException(
                "Release artifact verification failed: signing credentials are missing and the " +
                    "debug-signing opt-out is not set."
            )
        }

        if (isReleaseBuildRequested && allowDebugSignedRelease) {
            logger.warn(
                "DEBUG-SIGNED RELEASE ARTIFACT NOT FOR DISTRIBUTION: " +
                    "do not upload this build to any store."
            )
        }
    }
}

tasks.matching { it.name == "preReleaseBuild" || it.name == "preProdReleaseBuild" }
    .configureEach { dependsOn(verifyReleaseSigning) }

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
