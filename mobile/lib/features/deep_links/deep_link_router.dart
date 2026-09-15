import 'package:flutter/foundation.dart';

/// Deep-link routing (Phase 18 §64, extended Phase 19 §15/§20).
///
/// Supported scheme links (`ffarena` by default):
///
///   ffarena://tournament/{id}
///   ffarena://match/{id}
///   ffarena://profile/{id}
///   ffarena://leaderboard/{id}
///   ffarena://support/{id}
///   ffarena://dispute/{id}
///   ffarena://payment/{id}
///   ffarena://payout/{id}
///   ffarena://security
///
/// Supported web links (App Links / Universal Links — only when the host
/// matches the server-configured web base origin):
///
///   https://{web_base}/tournaments/{id}
///   https://{web_base}/matches/{id}
///   https://{web_base}/players/{id}
///   https://{web_base}/leaderboards/{id}
///
/// Every deep link requires authentication and an authorized server response
/// before the target screen renders; the link itself never carries secrets,
/// room passwords, payment secrets or tokens. Unknown/deprecated links are
/// ignored safely (never crash).
enum DeepLinkTarget {
  tournament,
  match,
  profile,
  leaderboard,
  support,
  dispute,
  payment,
  payout,
  security;

  static DeepLinkTarget? fromPath(String segment) {
    switch (segment) {
      case 'tournament':
        return DeepLinkTarget.tournament;
      case 'match':
        return DeepLinkTarget.match;
      case 'profile':
        return DeepLinkTarget.profile;
      case 'leaderboard':
        return DeepLinkTarget.leaderboard;
      case 'support':
        return DeepLinkTarget.support;
      case 'dispute':
        return DeepLinkTarget.dispute;
      case 'payment':
        return DeepLinkTarget.payment;
      case 'payout':
        return DeepLinkTarget.payout;
      case 'security':
        return DeepLinkTarget.security;
      default:
        return null;
    }
  }
}

class DeepLink {
  const DeepLink({required this.target, this.id, this.raw});

  final DeepLinkTarget target;

  /// The numeric entity id (null for id-less targets like `security`).
  final int? id;

  /// The sanitized raw link (never contains a query string or fragment).
  final String? raw;
}

/// Parses a `scheme://host/path/{id}` deep link. Returns null for anything
/// that is not a recognized FF Arena link, and NEVER parses embedded
/// credentials or secrets out of the URI.
DeepLink? parseDeepLink(Uri uri, {String scheme = 'ffarena'}) {
  if (uri.scheme != scheme) {
    return null;
  }

  // `ffarena://tournament/{id}` puts the target in the host and the id in
  // the first path segment. Normalize both into a single segment list so
  // `ffarena://tournament/42` and equivalent forms parse identically.
  final segments = <String>[
    if (uri.host.isNotEmpty) uri.host,
    ...uri.pathSegments.where((s) => s.isNotEmpty),
  ];

  if (segments.isEmpty) {
    return null;
  }

  final target = DeepLinkTarget.fromPath(segments[0]);
  if (target == null) {
    return null;
  }

  // `security` is id-less.
  if (target == DeepLinkTarget.security) {
    return DeepLink(target: target, raw: _sanitized(uri));
  }

  if (segments.length != 2) {
    return null;
  }

  final id = int.tryParse(segments[1]);
  if (id == null) {
    return null;
  }

  return DeepLink(target: target, id: id, raw: _sanitized(uri));
}

/// Parses a web (App Link / Universal Link) URI into a deep link, but only
/// when the host matches the server-configured web base origin. Returns null
/// for any other host or path (the caller falls back to the browser).
DeepLink? parseWebLink(Uri uri, {String? webBase}) {
  if (uri.scheme != 'https' && uri.scheme != 'http') {
    return null;
  }

  if (webBase == null || webBase.isEmpty) {
    return null;
  }

  final base = Uri.tryParse(webBase);
  if (base == null || uri.host != base.host) {
    return null;
  }

  final segments = uri.pathSegments.where((s) => s.isNotEmpty).toList();
  if (segments.length < 2) {
    return null;
  }

  final target = switch (segments[0]) {
    'tournaments' => DeepLinkTarget.tournament,
    'matches' => DeepLinkTarget.match,
    'players' => DeepLinkTarget.profile,
    'leaderboards' => DeepLinkTarget.leaderboard,
    _ => null,
  };

  if (target == null) {
    return null;
  }

  final id = int.tryParse(segments[1]);
  if (id == null) {
    return null;
  }

  return DeepLink(target: target, id: id, raw: _sanitized(uri));
}

String _sanitized(Uri uri) {
  // Rebuild WITHOUT query string or fragment so an attacker can never
  // smuggle secrets/payment tokens into a deep link.
  final scheme = uri.scheme;
  final host = uri.host;
  final path = uri.path;
  if (scheme.isEmpty) {
    return '$host$path';
  }
  return '$scheme://$host$path';
}

/// The app's root navigator callback for deep links. Set by the app shell so
/// the router has no widget dependency and can be unit tested.
typedef DeepLinkHandler = void Function(DeepLink link);

class DeepLinkRouter {
  DeepLinkRouter({required String scheme}) : _scheme = scheme;

  final String _scheme;
  DeepLinkHandler? handler;

  /// The server web base origin (set after app meta loads). Web links are
  /// only parsed when this matches.
  String? webBase;

  /// Holds the most recent link that arrived before a [handler] was
  /// registered (e.g. a cold-start App Link that lands while the session is
  /// still restoring or before login completes). Delivered by
  /// [flushPending] once the authenticated shell is ready.
  DeepLink? _pending;

  void registerHandler(DeepLinkHandler h) {
    handler = h;
  }

  /// Clears the current handler (e.g. when the authenticated shell is torn
  /// down on logout). Links that arrive while no handler is registered are
  /// queued and delivered by [flushPending] after the next login.
  void unregisterHandler() {
    handler = null;
  }

  /// Delivers a queued pre-handler link (if any). Call after the
  /// authenticated shell has mounted so navigation has a valid context.
  void flushPending() {
    final pending = _pending;
    if (pending == null) {
      return;
    }
    _pending = null;
    handler?.call(pending);
  }

  /// Entry point for the OS cold-start / warm-start URI. Tries the scheme
  /// first, then the web form. When no handler is registered yet, the link
  /// is queued (latest wins) instead of dropped.
  void route(String rawUri) {
    final uri = Uri.tryParse(rawUri);
    if (uri == null) {
      return;
    }

    final link = parseDeepLink(uri, scheme: _scheme) ??
        parseWebLink(uri, webBase: webBase);

    if (link == null) {
      debugPrint('[deeplink] ignored unrecognized link');
      return;
    }

    if (handler == null) {
      _pending = link;
      return;
    }

    handler!.call(link);
  }
}
