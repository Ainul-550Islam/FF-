import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../../config/app_config.dart';
import '../telemetry/crash_reporter.dart';
import 'api_exception.dart';
import 'api_result.dart';

/// The single HTTP gateway to the Laravel `/api/v1` platform (Phase 18 §3).
///
/// Responsibilities:
///   * attach the bearer token (read via [tokenProvider], never logged),
///   * send the right Accept/Content-Type and `Idempotency-Key` headers,
///   * unwrap the `{data, meta}` success envelope and the
///     `{error:{code,message,details}}` error envelope,
///   * map transport failures to typed [ApiException]s,
///   * report session-terminating responses to [onSessionTerminated].
class ApiClient {
  ApiClient({
    required String baseUrl,
    required this.tokenProvider,
    http.Client? httpClient,
    CrashReporter? crashReporter,
    this.requestTimeout = const Duration(seconds: 20),
  })  : _baseUrl = _normalize(baseUrl),
        _http = httpClient ?? http.Client(),
        _crash = crashReporter ?? const NoopCrashReporter();

  final String _baseUrl;
  final http.Client _http;
  final CrashReporter _crash;
  final Duration requestTimeout;

  /// Mutable so the session manager can bind the token source after wiring.
  String? Function() tokenProvider;

  /// Invoked once per response that carries a session-terminating error code.
  void Function(ApiException error)? onSessionTerminated;

  static String _normalize(String base) {
    var b = base.trim();
    while (b.endsWith('/')) {
      b = b.substring(0, b.length - 1);
    }
    return b;
  }

  Uri _uri(String path, [Map<String, String>? query]) {
    // Paths may be written relative (`/me`) or as the full documented
    // `/api/v1/...` form. When the base URL already carries the version
    // prefix, don't duplicate it.
    var p = path;
    if (p.startsWith('/api/v1') && _baseUrl.endsWith('/api/v1')) {
      p = p.substring('/api/v1'.length);
    }
    final uri = Uri.parse('$_baseUrl$p');
    if (query == null || query.isEmpty) {
      return uri;
    }
    return uri.replace(queryParameters: query);
  }

  Map<String, String> _headers({bool auth = true, String? idempotencyKey}) {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'ffarena-mobile',
      'X-Client-Version': AppConfig.instance.version,
    };
    if (auth) {
      final token = tokenProvider();
      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }
    if (idempotencyKey != null && idempotencyKey.isNotEmpty) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    return headers;
  }

  Future<ApiEnvelopeData> get(
    String path, {
    Map<String, String>? query,
    bool auth = true,
  }) =>
      _send('GET', path, query: query, auth: auth);

  Future<ApiEnvelopeData> post(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
    String? idempotencyKey,
  }) =>
      _send('POST', path,
          body: body, auth: auth, idempotencyKey: idempotencyKey);

  Future<ApiEnvelopeData> patch(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) =>
      _send('PATCH', path, body: body, auth: auth);

  Future<ApiEnvelopeData> put(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) =>
      _send('PUT', path, body: body, auth: auth);

  Future<ApiEnvelopeData> delete(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) =>
      _send('DELETE', path, body: body, auth: auth);

  Future<ApiEnvelopeData> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
    bool auth = true,
    String? idempotencyKey,
  }) async {
    final uri = _uri(path, query);
    final headers = _headers(auth: auth, idempotencyKey: idempotencyKey);
    final encoded = body == null ? null : jsonEncode(body);

    http.Response response;
    try {
      switch (method) {
        case 'GET':
          response =
              await _http.get(uri, headers: headers).timeout(requestTimeout);
        case 'POST':
          response = await _http
              .post(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        case 'PATCH':
          response = await _http
              .patch(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        case 'PUT':
          response = await _http
              .put(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        case 'DELETE':
          response = await _http
              .delete(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        default:
          throw StateError('Unsupported HTTP method: $method');
      }
    } on TimeoutException {
      _crash.record('api_timeout', 'request timed out', null);
      throw const ApiException(
        code: ApiException.timeout,
        message: 'The server took too long to respond.',
      );
    } on SocketException {
      throw const ApiException(
        code: ApiException.offline,
        message: 'No network connection.',
      );
    } on http.ClientException {
      throw const ApiException(
        code: ApiException.offline,
        message: 'Network request failed.',
      );
    }

    return _decode(response);
  }

  ApiEnvelopeData _decode(http.Response response) {
    final Map<String, dynamic>? body;
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map<String, dynamic>) {
        body = decoded;
      } else {
        body = null;
      }
    } on FormatException {
      // Non-JSON success (unlikely for this API) is treated as empty.
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return const ApiEnvelopeData(data: null);
      }
      throw ApiException(
        code: ApiException.serverError,
        message: 'Unexpected response.',
        statusCode: response.statusCode,
      );
    }

    final status = response.statusCode;

    if (status >= 200 && status < 300) {
      // 204 No Content has no body.
      if (body == null) {
        return const ApiEnvelopeData(data: null);
      }
      return ApiEnvelopeData(
        data: body['data'],
        meta: body['meta'] is Map<String, dynamic>
            ? body['meta'] as Map<String, dynamic>
            : const {},
      );
    }

    final error = ApiException.fromBody(body, statusCode: status);
    if (error.isSessionTerminating) {
      onSessionTerminated?.call(error);
    }
    throw error;
  }

  void dispose() {
    _http.close();
  }
}
