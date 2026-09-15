package com.ffarena.ffarena_mobile

import android.content.Intent
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Phase 19 — forwards OS deep links (ffarena://) and Android App Links
 * (https://) into the Flutter `ffarena.deeplink/channel` MethodChannel.
 *
 * The Flutter side owns ALL routing, authorization and secret-safety logic;
 * this activity only hands the raw URI over — it never parses or trusts it.
 */
class MainActivity : FlutterActivity() {
    private var channel: MethodChannel? = null
    private var pendingLink: String? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Cold start: an App Link / deep link that launched the activity.
        handleLink(intent?.dataString)
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        channel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
        channel?.setMethodCallHandler { call, result ->
            when (call.method) {
                METHOD_INITIAL_LINK -> result.success(pendingLink)
                else -> result.notImplemented()
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        // Warm start: the activity is already running.
        handleLink(intent.dataString)
    }

    private fun handleLink(uri: String?) {
        if (uri.isNullOrEmpty()) return
        val activeChannel = channel
        if (activeChannel == null) {
            pendingLink = uri
            return
        }
        activeChannel.invokeMethod(METHOD_DEEP_LINK, uri)
    }

    companion object {
        private const val CHANNEL = "ffarena.deeplink/channel"
        private const val METHOD_INITIAL_LINK = "initialLink"
        private const val METHOD_DEEP_LINK = "onDeepLink"
    }
}
