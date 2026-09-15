// GENERATED FILE — do not edit by hand.
// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).
//
// All fields are nullable and decoded defensively so the mobile client
// tolerates additive API fields and missing optional fields (Phase 18 §56).
//
// ignore_for_file: non_constant_identifier_names, prefer_final_locals
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: unused_element, avoid_init_to_null

library;

class ApiClientModel {
  const ApiClientModel({
    this.id = null,
    this.name = null,
    this.description = null,
    this.status = null,
  });

  factory ApiClientModel.fromJson(Map<String, dynamic> json) {
    return ApiClientModel(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      description: _asString(json['description']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final String? name;
  final String? description;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (description != null) 'description': description,
      if (status != null) 'status': status,
    };
  }
}

class AppInfo {
  const AppInfo({
    this.name = null,
    this.apiVersion = null,
    this.minSupportedAppVersion = null,
    this.latestAppVersion = null,
    this.updateRequired = null,
    this.deepLinkScheme = null,
  });

  factory AppInfo.fromJson(Map<String, dynamic> json) {
    return AppInfo(
      name: _asString(json['name']),
      apiVersion: _asString(json['api_version']),
      minSupportedAppVersion: _asString(json['min_supported_app_version']),
      latestAppVersion: _asString(json['latest_app_version']),
      updateRequired: _asBool(json['update_required']),
      deepLinkScheme: _asString(json['deep_link_scheme']),
    );
  }

  final String? name;
  final String? apiVersion;
  final String? minSupportedAppVersion;
  final String? latestAppVersion;
  final bool? updateRequired;
  final String? deepLinkScheme;

  Map<String, dynamic> toJson() {
    return {
      if (name != null) 'name': name,
      if (apiVersion != null) 'api_version': apiVersion,
      if (minSupportedAppVersion != null)
        'min_supported_app_version': minSupportedAppVersion,
      if (latestAppVersion != null) 'latest_app_version': latestAppVersion,
      if (updateRequired != null) 'update_required': updateRequired,
      if (deepLinkScheme != null) 'deep_link_scheme': deepLinkScheme,
    };
  }
}

class AppMaintenance {
  const AppMaintenance({
    this.active = null,
    this.message = null,
  });

  factory AppMaintenance.fromJson(Map<String, dynamic> json) {
    return AppMaintenance(
      active: _asBool(json['active']),
      message: _asString(json['message']),
    );
  }

  final bool? active;
  final String? message;

  Map<String, dynamic> toJson() {
    return {
      if (active != null) 'active': active,
      if (message != null) 'message': message,
    };
  }
}

class AppMeta {
  const AppMeta({
    this.app = null,
    this.maintenance = null,
    this.push = null,
    this.urls = null,
    this.platform = null,
  });

  factory AppMeta.fromJson(Map<String, dynamic> json) {
    return AppMeta(
      app: (json['app'] is Map<String, dynamic>
          ? AppInfo.fromJson(json['app'] as Map<String, dynamic>)
          : null),
      maintenance: (json['maintenance'] is Map<String, dynamic>
          ? AppMaintenance.fromJson(json['maintenance'] as Map<String, dynamic>)
          : null),
      push: (json['push'] is Map<String, dynamic>
          ? PushCapabilities.fromJson(json['push'] as Map<String, dynamic>)
          : null),
      urls: (json['urls'] is Map<String, dynamic>
          ? AppUrls.fromJson(json['urls'] as Map<String, dynamic>)
          : null),
      platform: (json['platform'] is Map<String, dynamic>
          ? PlatformInfo.fromJson(json['platform'] as Map<String, dynamic>)
          : null),
    );
  }

  final AppInfo? app;
  final AppMaintenance? maintenance;
  final PushCapabilities? push;
  final AppUrls? urls;
  final PlatformInfo? platform;

  Map<String, dynamic> toJson() {
    return {
      if (app != null) 'app': app,
      if (maintenance != null) 'maintenance': maintenance,
      if (push != null) 'push': push,
      if (urls != null) 'urls': urls,
      if (platform != null) 'platform': platform,
    };
  }
}

class AppUrls {
  const AppUrls({
    this.support = null,
    this.privacy = null,
    this.terms = null,
    this.releaseNotes = null,
    this.webBase = null,
    this.store = null,
  });

  factory AppUrls.fromJson(Map<String, dynamic> json) {
    return AppUrls(
      support: _asString(json['support']),
      privacy: _asString(json['privacy']),
      terms: _asString(json['terms']),
      releaseNotes: _asString(json['release_notes']),
      webBase: _asString(json['web_base']),
      store: _asString(json['store']),
    );
  }

  final String? support;
  final String? privacy;
  final String? terms;
  final String? releaseNotes;
  final String? webBase;
  final String? store;

  Map<String, dynamic> toJson() {
    return {
      if (support != null) 'support': support,
      if (privacy != null) 'privacy': privacy,
      if (terms != null) 'terms': terms,
      if (releaseNotes != null) 'release_notes': releaseNotes,
      if (webBase != null) 'web_base': webBase,
      if (store != null) 'store': store,
    };
  }
}

class AuthSession {
  const AuthSession({
    this.token = null,
    this.tokenExpiresAt = null,
    this.user = null,
  });

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    return AuthSession(
      token: _asString(json['token']),
      tokenExpiresAt: _asString(json['token_expires_at']),
      user: (json['user'] is Map<String, dynamic>
          ? Me.fromJson(json['user'] as Map<String, dynamic>)
          : null),
    );
  }

  final String? token;
  final String? tokenExpiresAt;
  final Me? user;

  Map<String, dynamic> toJson() {
    return {
      if (token != null) 'token': token,
      if (tokenExpiresAt != null) 'token_expires_at': tokenExpiresAt,
      if (user != null) 'user': user,
    };
  }
}

class Dispute {
  const Dispute({
    this.id = null,
    this.matchId = null,
    this.category = null,
    this.status = null,
    this.description = null,
    this.resolution = null,
  });

  factory Dispute.fromJson(Map<String, dynamic> json) {
    return Dispute(
      id: _asInt(json['id']),
      matchId: _asInt(json['match_id']),
      category: _asString(json['category']),
      status: _asString(json['status']),
      description: _asString(json['description']),
      resolution: _asString(json['resolution']),
    );
  }

  final int? id;
  final int? matchId;
  final String? category;
  final String? status;
  final String? description;
  final String? resolution;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (matchId != null) 'match_id': matchId,
      if (category != null) 'category': category,
      if (status != null) 'status': status,
      if (description != null) 'description': description,
      if (resolution != null) 'resolution': resolution,
    };
  }
}

class ApiEnvelope {
  const ApiEnvelope({
    this.data = null,
    this.meta = null,
  });

  factory ApiEnvelope.fromJson(Map<String, dynamic> json) {
    return ApiEnvelope(
      data: json['data'],
      meta: json['meta'],
    );
  }

  final dynamic data;
  final Map<String, dynamic>? meta;

  Map<String, dynamic> toJson() {
    return {
      if (data != null) 'data': data,
      if (meta != null) 'meta': meta,
    };
  }
}

class ApiErrorBody {
  const ApiErrorBody({
    this.code = null,
    this.message = null,
    this.details = null,
  });

  factory ApiErrorBody.fromJson(Map<String, dynamic> json) {
    return ApiErrorBody(
      code: _asString(json['code']),
      message: _asString(json['message']),
      details: json['details'],
    );
  }

  final String? code;
  final String? message;
  final Map<String, dynamic>? details;

  Map<String, dynamic> toJson() {
    return {
      if (code != null) 'code': code,
      if (message != null) 'message': message,
      if (details != null) 'details': details,
    };
  }
}

class ApiErrorEnvelope {
  const ApiErrorEnvelope({
    this.error = null,
  });

  factory ApiErrorEnvelope.fromJson(Map<String, dynamic> json) {
    return ApiErrorEnvelope(
      error: (json['error'] is Map<String, dynamic>
          ? ApiErrorBody.fromJson(json['error'] as Map<String, dynamic>)
          : null),
    );
  }

  final ApiErrorBody? error;

  Map<String, dynamic> toJson() {
    return {
      if (error != null) 'error': error,
    };
  }
}

class LedgerEntry {
  const LedgerEntry({
    this.id = null,
    this.direction = null,
    this.amountMinor = null,
    this.balanceAfterMinor = null,
    this.type = null,
  });

  factory LedgerEntry.fromJson(Map<String, dynamic> json) {
    return LedgerEntry(
      id: _asInt(json['id']),
      direction: _asString(json['direction']),
      amountMinor: _asInt(json['amount_minor']),
      balanceAfterMinor: _asInt(json['balance_after_minor']),
      type: _asString(json['type']),
    );
  }

  final int? id;
  final String? direction;
  final int? amountMinor;
  final int? balanceAfterMinor;
  final String? type;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (direction != null) 'direction': direction,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (balanceAfterMinor != null) 'balance_after_minor': balanceAfterMinor,
      if (type != null) 'type': type,
    };
  }
}

class LiveEvent {
  const LiveEvent({
    this.id = null,
    this.type = null,
    this.tournamentId = null,
    this.payload = null,
    this.createdAt = null,
  });

  factory LiveEvent.fromJson(Map<String, dynamic> json) {
    return LiveEvent(
      id: _asInt(json['id']),
      type: _asString(json['type']),
      tournamentId: _asInt(json['tournament_id']),
      payload: json['payload'],
      createdAt: _asString(json['created_at']),
    );
  }

  final int? id;
  final String? type;
  final int? tournamentId;
  final Map<String, dynamic>? payload;
  final String? createdAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (type != null) 'type': type,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (payload != null) 'payload': payload,
      if (createdAt != null) 'created_at': createdAt,
    };
  }
}

class MatchModel {
  const MatchModel({
    this.id = null,
    this.tournamentId = null,
    this.round = null,
    this.matchNo = null,
    this.bracket = null,
    this.status = null,
    this.scheduledAt = null,
    this.completedAt = null,
    this.roomId = null,
    this.roomPass = null,
    this.team1 = null,
    this.team2 = null,
    this.winner = null,
    this.scores = null,
  });

  factory MatchModel.fromJson(Map<String, dynamic> json) {
    return MatchModel(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      round: _asInt(json['round']),
      matchNo: _asInt(json['match_no']),
      bracket: _asString(json['bracket']),
      status: _asString(json['status']),
      scheduledAt: _asString(json['scheduled_at']),
      completedAt: _asString(json['completed_at']),
      roomId: _asString(json['room_id']),
      roomPass: _asString(json['room_pass']),
      team1: json['team1'],
      team2: json['team2'],
      winner: json['winner'],
      scores: _asList(json['scores']),
    );
  }

  final int? id;
  final int? tournamentId;
  final int? round;
  final int? matchNo;
  final String? bracket;
  final String? status;
  final String? scheduledAt;
  final String? completedAt;
  final String? roomId;
  final String? roomPass;
  final Map<String, dynamic>? team1;
  final Map<String, dynamic>? team2;
  final Map<String, dynamic>? winner;
  final List<dynamic>? scores;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (round != null) 'round': round,
      if (matchNo != null) 'match_no': matchNo,
      if (bracket != null) 'bracket': bracket,
      if (status != null) 'status': status,
      if (scheduledAt != null) 'scheduled_at': scheduledAt,
      if (completedAt != null) 'completed_at': completedAt,
      if (roomId != null) 'room_id': roomId,
      if (roomPass != null) 'room_pass': roomPass,
      if (team1 != null) 'team1': team1,
      if (team2 != null) 'team2': team2,
      if (winner != null) 'winner': winner,
      if (scores != null) 'scores': scores,
    };
  }
}

class Me {
  const Me({
    this.id = null,
    this.name = null,
    this.username = null,
    this.email = null,
    this.emailVerified = null,
    this.avatar = null,
    this.bio = null,
    this.country = null,
    this.region = null,
    this.role = null,
    this.privacy = null,
    this.joinedAt = null,
  });

  factory Me.fromJson(Map<String, dynamic> json) {
    return Me(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      username: _asString(json['username']),
      email: _asString(json['email']),
      emailVerified: _asBool(json['email_verified']),
      avatar: _asString(json['avatar']),
      bio: _asString(json['bio']),
      country: _asString(json['country']),
      region: _asString(json['region']),
      role: _asString(json['role']),
      privacy: _asString(json['privacy']),
      joinedAt: _asString(json['joined_at']),
    );
  }

  final int? id;
  final String? name;
  final String? username;
  final String? email;
  final bool? emailVerified;
  final String? avatar;
  final String? bio;
  final String? country;
  final String? region;
  final String? role;
  final String? privacy;
  final String? joinedAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (username != null) 'username': username,
      if (email != null) 'email': email,
      if (emailVerified != null) 'email_verified': emailVerified,
      if (avatar != null) 'avatar': avatar,
      if (bio != null) 'bio': bio,
      if (country != null) 'country': country,
      if (region != null) 'region': region,
      if (role != null) 'role': role,
      if (privacy != null) 'privacy': privacy,
      if (joinedAt != null) 'joined_at': joinedAt,
    };
  }
}

class MobileDevice {
  const MobileDevice({
    this.id = null,
    this.platform = null,
    this.provider = null,
    this.deviceLabel = null,
    this.appVersion = null,
    this.environment = null,
    this.isActive = null,
    this.lastSeenAt = null,
    this.createdAt = null,
  });

  factory MobileDevice.fromJson(Map<String, dynamic> json) {
    return MobileDevice(
      id: _asInt(json['id']),
      platform: _asString(json['platform']),
      provider: _asString(json['provider']),
      deviceLabel: _asString(json['device_label']),
      appVersion: _asString(json['app_version']),
      environment: _asString(json['environment']),
      isActive: _asBool(json['is_active']),
      lastSeenAt: _asString(json['last_seen_at']),
      createdAt: _asString(json['created_at']),
    );
  }

  final int? id;
  final String? platform;
  final String? provider;
  final String? deviceLabel;
  final String? appVersion;
  final String? environment;
  final bool? isActive;
  final String? lastSeenAt;
  final String? createdAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (platform != null) 'platform': platform,
      if (provider != null) 'provider': provider,
      if (deviceLabel != null) 'device_label': deviceLabel,
      if (appVersion != null) 'app_version': appVersion,
      if (environment != null) 'environment': environment,
      if (isActive != null) 'is_active': isActive,
      if (lastSeenAt != null) 'last_seen_at': lastSeenAt,
      if (createdAt != null) 'created_at': createdAt,
    };
  }
}

class NotificationModel {
  const NotificationModel({
    this.id = null,
    this.type = null,
    this.title = null,
    this.body = null,
    this.read = null,
  });

  factory NotificationModel.fromJson(Map<String, dynamic> json) {
    return NotificationModel(
      id: _asInt(json['id']),
      type: _asString(json['type']),
      title: _asString(json['title']),
      body: _asString(json['body']),
      read: _asBool(json['read']),
    );
  }

  final int? id;
  final String? type;
  final String? title;
  final String? body;
  final bool? read;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (type != null) 'type': type,
      if (title != null) 'title': title,
      if (body != null) 'body': body,
      if (read != null) 'read': read,
    };
  }
}

class NotificationPreference {
  const NotificationPreference({
    this.tournament = null,
    this.match = null,
    this.team = null,
    this.payment = null,
    this.payout = null,
    this.dispute = null,
    this.security = null,
    this.support = null,
  });

  factory NotificationPreference.fromJson(Map<String, dynamic> json) {
    return NotificationPreference(
      tournament: _asBool(json['tournament']),
      match: _asBool(json['match']),
      team: _asBool(json['team']),
      payment: _asBool(json['payment']),
      payout: _asBool(json['payout']),
      dispute: _asBool(json['dispute']),
      security: _asBool(json['security']),
      support: _asBool(json['support']),
    );
  }

  final bool? tournament;
  final bool? match;
  final bool? team;
  final bool? payment;
  final bool? payout;
  final bool? dispute;
  final bool? security;
  final bool? support;

  Map<String, dynamic> toJson() {
    return {
      if (tournament != null) 'tournament': tournament,
      if (match != null) 'match': match,
      if (team != null) 'team': team,
      if (payment != null) 'payment': payment,
      if (payout != null) 'payout': payout,
      if (dispute != null) 'dispute': dispute,
      if (security != null) 'security': security,
      if (support != null) 'support': support,
    };
  }
}

class Pagination {
  const Pagination({
    this.currentPage = null,
    this.lastPage = null,
    this.perPage = null,
    this.total = null,
  });

  factory Pagination.fromJson(Map<String, dynamic> json) {
    return Pagination(
      currentPage: _asInt(json['current_page']),
      lastPage: _asInt(json['last_page']),
      perPage: _asInt(json['per_page']),
      total: _asInt(json['total']),
    );
  }

  final int? currentPage;
  final int? lastPage;
  final int? perPage;
  final int? total;

  Map<String, dynamic> toJson() {
    return {
      if (currentPage != null) 'current_page': currentPage,
      if (lastPage != null) 'last_page': lastPage,
      if (perPage != null) 'per_page': perPage,
      if (total != null) 'total': total,
    };
  }
}

class Payment {
  const Payment({
    this.id = null,
    this.tournamentId = null,
    this.teamId = null,
    this.amount = null,
    this.amountMinor = null,
    this.currency = null,
    this.provider = null,
    this.status = null,
  });

  factory Payment.fromJson(Map<String, dynamic> json) {
    return Payment(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      teamId: _asInt(json['team_id']),
      amount: _asString(json['amount']),
      amountMinor: _asInt(json['amount_minor']),
      currency: _asString(json['currency']),
      provider: _asString(json['provider']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final int? tournamentId;
  final int? teamId;
  final String? amount;
  final int? amountMinor;
  final String? currency;
  final String? provider;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (teamId != null) 'team_id': teamId,
      if (amount != null) 'amount': amount,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (currency != null) 'currency': currency,
      if (provider != null) 'provider': provider,
      if (status != null) 'status': status,
    };
  }
}

class Payout {
  const Payout({
    this.id = null,
    this.tournamentId = null,
    this.rank = null,
    this.amountMinor = null,
    this.currency = null,
    this.status = null,
  });

  factory Payout.fromJson(Map<String, dynamic> json) {
    return Payout(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      rank: _asInt(json['rank']),
      amountMinor: _asInt(json['amount_minor']),
      currency: _asString(json['currency']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final int? tournamentId;
  final int? rank;
  final int? amountMinor;
  final String? currency;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (rank != null) 'rank': rank,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (currency != null) 'currency': currency,
      if (status != null) 'status': status,
    };
  }
}

class PlatformInfo {
  const PlatformInfo({
    this.currency = null,
    this.timezone = null,
    this.locale = null,
  });

  factory PlatformInfo.fromJson(Map<String, dynamic> json) {
    return PlatformInfo(
      currency: _asString(json['currency']),
      timezone: _asString(json['timezone']),
      locale: _asString(json['locale']),
    );
  }

  final String? currency;
  final String? timezone;
  final String? locale;

  Map<String, dynamic> toJson() {
    return {
      if (currency != null) 'currency': currency,
      if (timezone != null) 'timezone': timezone,
      if (locale != null) 'locale': locale,
    };
  }
}

class PushCapabilities {
  const PushCapabilities({
    this.fcmEnabled = null,
    this.apnsEnabled = null,
  });

  factory PushCapabilities.fromJson(Map<String, dynamic> json) {
    return PushCapabilities(
      fcmEnabled: _asBool(json['fcm_enabled']),
      apnsEnabled: _asBool(json['apns_enabled']),
    );
  }

  final bool? fcmEnabled;
  final bool? apnsEnabled;

  Map<String, dynamic> toJson() {
    return {
      if (fcmEnabled != null) 'fcm_enabled': fcmEnabled,
      if (apnsEnabled != null) 'apns_enabled': apnsEnabled,
    };
  }
}

class Score {
  const Score({
    this.id = null,
    this.teamId = null,
    this.kills = null,
    this.placement = null,
    this.placementPoints = null,
    this.killPoints = null,
    this.points = null,
    this.status = null,
  });

  factory Score.fromJson(Map<String, dynamic> json) {
    return Score(
      id: _asInt(json['id']),
      teamId: _asInt(json['team_id']),
      kills: _asInt(json['kills']),
      placement: _asInt(json['placement']),
      placementPoints: _asInt(json['placement_points']),
      killPoints: _asInt(json['kill_points']),
      points: _asInt(json['points']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final int? teamId;
  final int? kills;
  final int? placement;
  final int? placementPoints;
  final int? killPoints;
  final int? points;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (teamId != null) 'team_id': teamId,
      if (kills != null) 'kills': kills,
      if (placement != null) 'placement': placement,
      if (placementPoints != null) 'placement_points': placementPoints,
      if (killPoints != null) 'kill_points': killPoints,
      if (points != null) 'points': points,
      if (status != null) 'status': status,
    };
  }
}

class ApiSession {
  const ApiSession({
    this.id = null,
    this.deviceLabel = null,
    this.lastActivity = null,
    this.isCurrent = null,
  });

  factory ApiSession.fromJson(Map<String, dynamic> json) {
    return ApiSession(
      id: _asString(json['id']),
      deviceLabel: _asString(json['device_label']),
      lastActivity: _asString(json['last_activity']),
      isCurrent: _asBool(json['is_current']),
    );
  }

  final String? id;
  final String? deviceLabel;
  final String? lastActivity;
  final bool? isCurrent;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (deviceLabel != null) 'device_label': deviceLabel,
      if (lastActivity != null) 'last_activity': lastActivity,
      if (isCurrent != null) 'is_current': isCurrent,
    };
  }
}

class StandingRow {
  const StandingRow({
    this.rank = null,
    this.teamId = null,
    this.teamName = null,
    this.matchesPlayed = null,
    this.kills = null,
    this.placementPoints = null,
    this.killPoints = null,
    this.points = null,
    this.bestPlacement = null,
  });

  factory StandingRow.fromJson(Map<String, dynamic> json) {
    return StandingRow(
      rank: _asInt(json['rank']),
      teamId: _asInt(json['team_id']),
      teamName: _asString(json['team_name']),
      matchesPlayed: _asInt(json['matches_played']),
      kills: _asInt(json['kills']),
      placementPoints: _asInt(json['placement_points']),
      killPoints: _asInt(json['kill_points']),
      points: _asInt(json['points']),
      bestPlacement: _asInt(json['best_placement']),
    );
  }

  final int? rank;
  final int? teamId;
  final String? teamName;
  final int? matchesPlayed;
  final int? kills;
  final int? placementPoints;
  final int? killPoints;
  final int? points;
  final int? bestPlacement;

  Map<String, dynamic> toJson() {
    return {
      if (rank != null) 'rank': rank,
      if (teamId != null) 'team_id': teamId,
      if (teamName != null) 'team_name': teamName,
      if (matchesPlayed != null) 'matches_played': matchesPlayed,
      if (kills != null) 'kills': kills,
      if (placementPoints != null) 'placement_points': placementPoints,
      if (killPoints != null) 'kill_points': killPoints,
      if (points != null) 'points': points,
      if (bestPlacement != null) 'best_placement': bestPlacement,
    };
  }
}

class SupportMessage {
  const SupportMessage({
    this.id = null,
    this.ticketId = null,
    this.body = null,
  });

  factory SupportMessage.fromJson(Map<String, dynamic> json) {
    return SupportMessage(
      id: _asInt(json['id']),
      ticketId: _asInt(json['ticket_id']),
      body: _asString(json['body']),
    );
  }

  final int? id;
  final int? ticketId;
  final String? body;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (ticketId != null) 'ticket_id': ticketId,
      if (body != null) 'body': body,
    };
  }
}

class SupportTicket {
  const SupportTicket({
    this.id = null,
    this.subject = null,
    this.category = null,
    this.priority = null,
    this.status = null,
  });

  factory SupportTicket.fromJson(Map<String, dynamic> json) {
    return SupportTicket(
      id: _asInt(json['id']),
      subject: _asString(json['subject']),
      category: _asString(json['category']),
      priority: _asString(json['priority']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final String? subject;
  final String? category;
  final String? priority;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (subject != null) 'subject': subject,
      if (category != null) 'category': category,
      if (priority != null) 'priority': priority,
      if (status != null) 'status': status,
    };
  }
}

class Team {
  const Team({
    this.id = null,
    this.tournamentId = null,
    this.name = null,
    this.captainName = null,
    this.gameUid = null,
    this.status = null,
    this.waitlistPosition = null,
  });

  factory Team.fromJson(Map<String, dynamic> json) {
    return Team(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      name: _asString(json['name']),
      captainName: _asString(json['captain_name']),
      gameUid: _asString(json['game_uid']),
      status: _asString(json['status']),
      waitlistPosition: _asInt(json['waitlist_position']),
    );
  }

  final int? id;
  final int? tournamentId;
  final String? name;
  final String? captainName;
  final String? gameUid;
  final String? status;
  final int? waitlistPosition;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (name != null) 'name': name,
      if (captainName != null) 'captain_name': captainName,
      if (gameUid != null) 'game_uid': gameUid,
      if (status != null) 'status': status,
      if (waitlistPosition != null) 'waitlist_position': waitlistPosition,
    };
  }
}

class Token {
  const Token({
    this.id = null,
    this.name = null,
    this.abilities = null,
    this.lastUsedAt = null,
    this.expiresAt = null,
  });

  factory Token.fromJson(Map<String, dynamic> json) {
    return Token(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      abilities: _asList(json['abilities']),
      lastUsedAt: _asString(json['last_used_at']),
      expiresAt: _asString(json['expires_at']),
    );
  }

  final int? id;
  final String? name;
  final List<dynamic>? abilities;
  final String? lastUsedAt;
  final String? expiresAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (abilities != null) 'abilities': abilities,
      if (lastUsedAt != null) 'last_used_at': lastUsedAt,
      if (expiresAt != null) 'expires_at': expiresAt,
    };
  }
}

class Tournament {
  const Tournament({
    this.id = null,
    this.slug = null,
    this.name = null,
    this.gameMode = null,
    this.map = null,
    this.format = null,
    this.status = null,
    this.entryFee = null,
    this.entryFeeMinor = null,
    this.currency = null,
    this.prizePool = null,
    this.teamSlots = null,
    this.teamSize = null,
    this.startsAt = null,
    this.checkInStartsAt = null,
    this.checkInEndsAt = null,
    this.slotsLeft = null,
    this.isFull = null,
    this.acceptsRegistration = null,
    this.confirmedTeamsCount = null,
    this.organizer = null,
  });

  factory Tournament.fromJson(Map<String, dynamic> json) {
    return Tournament(
      id: _asInt(json['id']),
      slug: _asString(json['slug']),
      name: _asString(json['name']),
      gameMode: _asString(json['game_mode']),
      map: _asString(json['map']),
      format: _asString(json['format']),
      status: _asString(json['status']),
      entryFee: _asString(json['entry_fee']),
      entryFeeMinor: _asInt(json['entry_fee_minor']),
      currency: _asString(json['currency']),
      prizePool: _asString(json['prize_pool']),
      teamSlots: _asInt(json['team_slots']),
      teamSize: _asInt(json['team_size']),
      startsAt: _asString(json['starts_at']),
      checkInStartsAt: _asString(json['check_in_starts_at']),
      checkInEndsAt: _asString(json['check_in_ends_at']),
      slotsLeft: _asInt(json['slots_left']),
      isFull: _asBool(json['is_full']),
      acceptsRegistration: _asBool(json['accepts_registration']),
      confirmedTeamsCount: _asInt(json['confirmed_teams_count']),
      organizer: json['organizer'],
    );
  }

  final int? id;
  final String? slug;
  final String? name;
  final String? gameMode;
  final String? map;
  final String? format;
  final String? status;
  final String? entryFee;
  final int? entryFeeMinor;
  final String? currency;
  final String? prizePool;
  final int? teamSlots;
  final int? teamSize;
  final String? startsAt;
  final String? checkInStartsAt;
  final String? checkInEndsAt;
  final int? slotsLeft;
  final bool? isFull;
  final bool? acceptsRegistration;
  final int? confirmedTeamsCount;
  final Map<String, dynamic>? organizer;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (slug != null) 'slug': slug,
      if (name != null) 'name': name,
      if (gameMode != null) 'game_mode': gameMode,
      if (map != null) 'map': map,
      if (format != null) 'format': format,
      if (status != null) 'status': status,
      if (entryFee != null) 'entry_fee': entryFee,
      if (entryFeeMinor != null) 'entry_fee_minor': entryFeeMinor,
      if (currency != null) 'currency': currency,
      if (prizePool != null) 'prize_pool': prizePool,
      if (teamSlots != null) 'team_slots': teamSlots,
      if (teamSize != null) 'team_size': teamSize,
      if (startsAt != null) 'starts_at': startsAt,
      if (checkInStartsAt != null) 'check_in_starts_at': checkInStartsAt,
      if (checkInEndsAt != null) 'check_in_ends_at': checkInEndsAt,
      if (slotsLeft != null) 'slots_left': slotsLeft,
      if (isFull != null) 'is_full': isFull,
      if (acceptsRegistration != null)
        'accepts_registration': acceptsRegistration,
      if (confirmedTeamsCount != null)
        'confirmed_teams_count': confirmedTeamsCount,
      if (organizer != null) 'organizer': organizer,
    };
  }
}

class UserProfile {
  const UserProfile({
    this.id = null,
    this.name = null,
    this.username = null,
    this.visible = null,
    this.privacy = null,
  });

  factory UserProfile.fromJson(Map<String, dynamic> json) {
    return UserProfile(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      username: _asString(json['username']),
      visible: _asBool(json['visible']),
      privacy: _asString(json['privacy']),
    );
  }

  final int? id;
  final String? name;
  final String? username;
  final bool? visible;
  final String? privacy;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (username != null) 'username': username,
      if (visible != null) 'visible': visible,
      if (privacy != null) 'privacy': privacy,
    };
  }
}

class Wallet {
  const Wallet({
    this.id = null,
    this.balance = null,
    this.balanceMinor = null,
    this.currency = null,
    this.status = null,
  });

  factory Wallet.fromJson(Map<String, dynamic> json) {
    return Wallet(
      id: _asInt(json['id']),
      balance: _asString(json['balance']),
      balanceMinor: _asInt(json['balance_minor']),
      currency: _asString(json['currency']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final String? balance;
  final int? balanceMinor;
  final String? currency;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (balance != null) 'balance': balance,
      if (balanceMinor != null) 'balance_minor': balanceMinor,
      if (currency != null) 'currency': currency,
      if (status != null) 'status': status,
    };
  }
}

class WebhookDelivery {
  const WebhookDelivery({
    this.id = null,
    this.event = null,
    this.deliveryId = null,
    this.status = null,
    this.attempts = null,
  });

  factory WebhookDelivery.fromJson(Map<String, dynamic> json) {
    return WebhookDelivery(
      id: _asInt(json['id']),
      event: _asString(json['event']),
      deliveryId: _asString(json['delivery_id']),
      status: _asString(json['status']),
      attempts: _asInt(json['attempts']),
    );
  }

  final int? id;
  final String? event;
  final String? deliveryId;
  final String? status;
  final int? attempts;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (event != null) 'event': event,
      if (deliveryId != null) 'delivery_id': deliveryId,
      if (status != null) 'status': status,
      if (attempts != null) 'attempts': attempts,
    };
  }
}

class WebhookEndpoint {
  const WebhookEndpoint({
    this.id = null,
    this.url = null,
    this.status = null,
    this.events = null,
    this.consecutiveFailures = null,
  });

  factory WebhookEndpoint.fromJson(Map<String, dynamic> json) {
    return WebhookEndpoint(
      id: _asInt(json['id']),
      url: _asString(json['url']),
      status: _asString(json['status']),
      events: _asList(json['events']),
      consecutiveFailures: _asInt(json['consecutive_failures']),
    );
  }

  final int? id;
  final String? url;
  final String? status;
  final List<dynamic>? events;
  final int? consecutiveFailures;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (url != null) 'url': url,
      if (status != null) 'status': status,
      if (events != null) 'events': events,
      if (consecutiveFailures != null)
        'consecutive_failures': consecutiveFailures,
    };
  }
}

/// Defensive JSON scalar helpers.
int? _asInt(dynamic v) => v is int
    ? v
    : (v is num ? v.toInt() : (v is String ? int.tryParse(v) : null));
num? _asNum(dynamic v) => v is num ? v : null;
bool? _asBool(dynamic v) => v is bool ? v : null;
String? _asString(dynamic v) => v is String ? v : null;
List<dynamic>? _asList(dynamic v) => v is List ? v : null;
