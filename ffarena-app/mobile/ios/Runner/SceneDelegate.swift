import Flutter
import UIKit

/// Phase 19 — forwards Universal Links and URL-scheme links to the shared
/// deep-link bridge (the app uses a scene-based lifecycle, so these arrive
/// on the scene delegate).
class SceneDelegate: FlutterSceneDelegate {
  override func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
    if let url = userActivity.webpageURL {
      DeepLinkChannel.shared.handle(url.absoluteString)
    }
  }

  override func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
    if let url = URLContexts.first?.url {
      DeepLinkChannel.shared.handle(url.absoluteString)
    }
  }
}
