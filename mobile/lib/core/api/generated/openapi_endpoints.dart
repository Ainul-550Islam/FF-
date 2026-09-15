// GENERATED FILE — do not edit by hand.
// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).
//
// ignore_for_file: constant_identifier_names

library;

/// A documented /api/v1 endpoint (path template, method, required scope, tag).
class ApiEndpoint {
  const ApiEndpoint({
    required this.method,
    required this.path,
    required this.tag,
    this.scope,
  });

  final String method;
  final String path;
  final String tag;
  final String? scope;

  String resolve([Map<String, Object> params = const {}]) {
    var p = path;
    params.forEach((k, v) {
      p = p.replaceAll('{$k}', v.toString());
    });
    return p;
  }
}

/// Endpoint constants derived from the OpenAPI contract.
class OpenApiEndpoints {
  const OpenApiEndpoints._();

  static const get_api_v1_admin_webhooks_endpoints = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/endpoints',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const post_api_v1_admin_webhooks_endpoints = ApiEndpoint(
    method: 'post',
    path: '/api/v1/admin/webhooks/endpoints',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_admin_webhooks_endpoints__endpoint_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_admin_webhooks_endpoints__endpoint__deliveries =
      ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}/deliveries',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const post_api_v1_admin_webhooks_endpoints__endpoint__rotate_secret =
      ApiEndpoint(
    method: 'post',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const post_api_v1_admin_webhooks_endpoints__endpoint__toggle =
      ApiEndpoint(
    method: 'post',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}/toggle',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_admin_webhooks_events = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/events',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_app_meta = ApiEndpoint(
    method: 'get',
    path: '/api/v1/app/meta',
    tag: 'General',
    scope: null,
  );

  static const post_api_v1_auth_google = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/google',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_login = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/login',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_otp_request = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/otp/request',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_otp_verify = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/otp/verify',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_register = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/register',
    tag: 'Auth',
    scope: null,
  );

  static const get_api_v1_disputes__dispute_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/disputes/{dispute}',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_leaderboards = ApiEndpoint(
    method: 'get',
    path: '/api/v1/leaderboards',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_leaderboards__tournament_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/leaderboards/{tournament}',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_matches__match_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/matches/{match}',
    tag: 'Matches',
    scope: null,
  );

  static const post_api_v1_matches__match__scores = ApiEndpoint(
    method: 'post',
    path: '/api/v1/matches/{match}/scores',
    tag: 'Matches',
    scope: null,
  );

  static const get_api_v1_me = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_clients = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/clients',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_clients = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/clients',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_clients__client_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/clients/{client}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_devices = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/devices',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_devices = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/devices',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_devices__device_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/devices/{device}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_disputes = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/disputes',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_live = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/live',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const get_api_v1_me_notification_preferences = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/notification-preferences',
    tag: 'Me',
    scope: null,
  );

  static const patch_api_v1_me_notification_preferences = ApiEndpoint(
    method: 'patch',
    path: '/api/v1/me/notification-preferences',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_notifications = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/notifications',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const post_api_v1_me_notifications_read_all = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/notifications/read-all',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const get_api_v1_me_notifications_unread_count = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/notifications/unread-count',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const post_api_v1_me_notifications__notification__read = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/notifications/{notification}/read',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const get_api_v1_me_payouts = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/payouts',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const patch_api_v1_me_profile = ApiEndpoint(
    method: 'patch',
    path: '/api/v1/me/profile',
    tag: 'Me',
    scope: null,
  );

  static const put_api_v1_me_profile = ApiEndpoint(
    method: 'put',
    path: '/api/v1/me/profile',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_security = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/security',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_sessions = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/sessions',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_sessions_revoke_all = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/sessions/revoke-all',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_sessions_revoke_others = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/sessions/revoke-others',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_sessions__session_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/sessions/{session}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_support = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/support',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const post_api_v1_me_support = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/support',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_support__ticket_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/support/{ticket}',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_support__ticket__messages = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/support/{ticket}/messages',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const post_api_v1_me_support__ticket__messages = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/support/{ticket}/messages',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_teams = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/teams',
    tag: 'Teams',
    scope: null,
  );

  static const get_api_v1_me_tokens = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/tokens',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_tokens = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/tokens',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_tokens__tokenId_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/tokens/{tokenId}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_wallet = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/wallet',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_me_wallet_ledger = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/wallet/ledger',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const post_api_v1_payments = ApiEndpoint(
    method: 'post',
    path: '/api/v1/payments',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_payments_methods = ApiEndpoint(
    method: 'get',
    path: '/api/v1/payments/methods',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_payments__payment_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/payments/{payment}',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_players__user_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/players/{user}',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_players__user__ranking = ApiEndpoint(
    method: 'get',
    path: '/api/v1/players/{user}/ranking',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_teams__team_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/teams/{team}',
    tag: 'Teams',
    scope: null,
  );

  static const patch_api_v1_teams__team_ = ApiEndpoint(
    method: 'patch',
    path: '/api/v1/teams/{team}',
    tag: 'Teams',
    scope: null,
  );

  static const get_api_v1_teams__team__roster = ApiEndpoint(
    method: 'get',
    path: '/api/v1/teams/{team}/roster',
    tag: 'Teams',
    scope: null,
  );

  static const post_api_v1_teams__team__roster = ApiEndpoint(
    method: 'post',
    path: '/api/v1/teams/{team}/roster',
    tag: 'Teams',
    scope: null,
  );

  static const delete_api_v1_teams__team__roster__member_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/teams/{team}/roster/{member}',
    tag: 'Teams',
    scope: null,
  );

  static const post_api_v1_teams__team__withdraw = ApiEndpoint(
    method: 'post',
    path: '/api/v1/teams/{team}/withdraw',
    tag: 'Teams',
    scope: null,
  );

  static const get_api_v1_tournaments = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__bracket = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/bracket',
    tag: 'Tournaments',
    scope: null,
  );

  static const post_api_v1_tournaments__tournament__check_in = ApiEndpoint(
    method: 'post',
    path: '/api/v1/tournaments/{tournament}/check-in',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__leaderboard = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/leaderboard',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__live = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/live',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__matches = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/matches',
    tag: 'Tournaments',
    scope: null,
  );

  static const post_api_v1_tournaments__tournament__registrations = ApiEndpoint(
    method: 'post',
    path: '/api/v1/tournaments/{tournament}/registrations',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__waitlist = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/waitlist',
    tag: 'Tournaments',
    scope: null,
  );

  static const post_api_v1_webhooks_inbound__provider_ = ApiEndpoint(
    method: 'post',
    path: '/api/v1/webhooks/inbound/{provider}',
    tag: 'Inbound Webhooks',
    scope: null,
  );
}
