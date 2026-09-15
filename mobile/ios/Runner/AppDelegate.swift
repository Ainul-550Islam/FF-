import Flutter
import UIKit

/// Shared bridge that forwards OS deep links (ffarena://) and Universal
/// Links (https://) into the Flutter `ffarena.deeplink/channel` MethodChannel.
///
/// The Flutter side owns ALL routing, authorization and secret-safety logic;
/// this bridge only hands the raw URI over — it never parses or trusts it.
final class DeepLinkChannel {
  static let shared = DeepLinkChannel()

  private var channel: FlutterMethodChannel?
  private var pendingLink: String?

  func attach(messenger: FlutterBinaryMessenger) {
    guard channel == nil else { return }

    let ch = FlutterMethodChannel(
      name: "ffarena.deeplink/channel",
      binaryMessenger: messenger
    )
    ch.setMethodCallHandler { [weak self] call, result in
      if call.method == "initialLink" {
        result(self?.pendingLink)
        self?.pendingLink = nil
      } else {
        result(FlutterMethodNotImplemented)
      }
    }
    channel = ch
  }

  func handle(_ uri: String?) {
    guard let uri = uri, !uri.isEmpty else { return }

    if let channel = channel {
      channel.invokeMethod("onDeepLink", arguments: uri)
    } else {
      pendingLink = uri
    }
  }
}

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    DeepLinkChannel.shared.attach(messenger: engineBridge.applicationBinaryMessenger)
  }

  // Custom URL scheme (ffarena://...).
  override func application(
    _ app: UIApplication,
    open url: URL,
    options: [UIApplication.OpenURLOptionsKey: Any] = [:]
  ) -> Bool {
    DeepLinkChannel.shared.handle(url.absoluteString)
    return true
  }
}
