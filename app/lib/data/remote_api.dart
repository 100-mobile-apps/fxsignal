import '../models/gold_test.dart';
import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../models/models.dart';
import '../models/money.dart';
import 'api.dart';

class RemoteApi extends Api {
  final String baseUrl;
  final http.Client _client;

  RemoteApi(String baseUrl, {http.Client? client})
      : baseUrl = baseUrl.replaceAll(RegExp(r'/+$'), ''),
        _client = client ?? http.Client();

  Future<Map<String, dynamic>> _req(String method, String path,
      {Map<String, dynamic>? body, Map<String, String>? query}) async {
    var uri = Uri.parse('$baseUrl$path').replace(queryParameters: query);
    final headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };
    final encoded = body == null ? null : jsonEncode(body);
    late http.Response res;
    try {
      for (var attempt = 0; attempt < 2; attempt++) {
        final request = http.Request(method, uri)
          ..headers.addAll(headers)
          ..followRedirects = false;
        if (encoded != null) request.body = encoded;
        final streamed = await _client.send(request).timeout(const Duration(seconds: 20));
        res = await http.Response.fromStream(streamed).timeout(const Duration(seconds: 20));
        if (res.statusCode < 300 || res.statusCode >= 400) break;

        final location = res.headers['location'];
        final target = location == null ? null : uri.resolve(location);
        final sameEndpoint = target != null &&
            target.scheme == 'https' &&
            target.host == uri.host &&
            target.port == uri.port &&
            target.userInfo.isEmpty &&
            target.query == uri.query &&
            target.fragment.isEmpty &&
            target.path.replaceAll(RegExp(r'/+$'), '') == uri.path.replaceAll(RegExp(r'/+$'), '');
        if (attempt == 0 && (res.statusCode == 307 || res.statusCode == 308) &&
            sameEndpoint && target != uri) {
          uri = target;
          continue;
        }
        final destination = target == null ? 'an unspecified address' : '${target.host}${target.path}';
        throw ApiException('The server redirected the request to $destination '
            '(HTTP ${res.statusCode}). Check the API URL or hosting security rules.', res.statusCode);
      }
    } on TimeoutException {
      throw const ApiException('The server took too long to respond. Please try again.');
    } on http.ClientException {
      throw const ApiException(
          'Could not reach the server. Check your internet connection and try again.');
    }

    Map<String, dynamic> data;
    try {
      data = (jsonDecode(res.body) as Map).cast<String, dynamic>();
    } catch (_) {
      throw ApiException('Unexpected server response (${res.statusCode}).', res.statusCode);
    }
    if (res.statusCode >= 400) {
      throw ApiException(data['error'] as String? ?? 'Request failed', res.statusCode);
    }
    return data;
  }

  AuthResult _auth(Map<String, dynamic> d) {
    final r = AuthResult(d['token'] as String,
        AppUser.fromJson((d['user'] as Map).cast<String, dynamic>()));
    token = r.token;
    return r;
  }

  AppUser _user(Map<String, dynamic> d) =>
      AppUser.fromJson((d['user'] as Map).cast<String, dynamic>());

  @override
  Future<AuthResult> login(String email, String password) async =>
      _auth(await _req('POST', '/auth/login',
          body: {'email': email, 'password': password}));

  @override
  Future<AuthResult> register(String name, String email, String password) async =>
      _auth(await _req('POST', '/auth/register', body: {
        'name': name,
        'email': email,
        'password': password,
        'accepted_terms': true,
      }));

  @override
  Future<String> forgotPassword(String email) async =>
      (await _req('POST', '/auth/forgot', body: {'email': email}))['message']
          as String? ??
      'Check your email for a 6-digit code.';

  @override
  Future<AuthResult> resetPassword(String email, String code, String password) async =>
      _auth(await _req('POST', '/auth/reset',
          body: {'email': email, 'code': code, 'password': password}));

  @override
  Future<AppUser> verifyEmail(String code) async =>
      _user(await _req('POST', '/auth/verify', body: {'code': code}));

  @override
  Future<String> resendVerification() async =>
      (await _req('POST', '/auth/verify/resend'))['message'] as String? ??
      'We sent you a new code.';

  @override
  Future<AppUser> me() async => _user(await _req('GET', '/me'));

  @override
  Future<AppUser> updateName(String name) async =>
      _user(await _req('PUT', '/me', body: {'name': name}));

  @override
  Future<String> changePassword(String current, String next) async {
    final d = await _req('PUT', '/me/password',
        body: {'current_password': current, 'new_password': next});
    token = d['token'] as String;
    return token!;
  }

  @override
  Future<void> deleteAccount(String password) async {
    await _req('DELETE', '/me', body: {'password': password});
  }

  @override
  Future<AppUser> updateNotifications(NotificationPrefs prefs) async =>
      _user(await _req('PUT', '/me/notifications', body: prefs.toJson()));

  @override
  Future<AppUser> updateWatchlist(List<String> symbols) async =>
      _user(await _req('PUT', '/me/watchlist', body: {'symbols': symbols}));

  @override
  Future<SignalPage> signals(
      {required bool running, int page = 1, DateTime? from, DateTime? to, bool allMarkets = false}) async {
    final d = await _req('GET', '/signals', query: {
      if (allMarkets) 'markets': 'all',
      'status': running ? 'running' : 'closed',
      'page': '$page',
      if (from != null) 'from': from.toUtc().toIso8601String(),
      if (to != null) 'to': to.toUtc().toIso8601String(),
    });
    return SignalPage(
      (d['items'] as List)
          .map((e) => TradeSignal.fromJson((e as Map).cast<String, dynamic>()))
          .toList(),
      d['has_more'] as bool? ?? false,
      d['markets'] is Map ? MarketsScope.fromJson((d['markets'] as Map).cast<String, dynamic>()) : null,
    );
  }

  @override
  Future<TradeSignal> signal(int id) async => TradeSignal.fromJson(
      ((await _req('GET', '/signals/$id'))['signal'] as Map)
          .cast<String, dynamic>());

  @override
  Future<ReportsData> reports(String period) async =>
      ReportsData.fromJson(await _req('GET', '/reports', query: {'period': period}));

  @override
  Future<Stats> stats(int days, {bool allMarkets = false}) async => Stats.fromJson(await _req('GET', '/stats',
      query: {'days': '$days', if (allMarkets) 'markets': 'all'}));

  @override
  Future<ResultsData> results({required double account, required double risk, String? strategy, String? pairs}) async =>
      ResultsData.fromJson(await _req('GET', '/results', query: {
        'account': '$account',
        'risk': '$risk',
        if (strategy != null) 'strategy': strategy,
        if (pairs != null) 'pairs': pairs,
      }));

  @override
  Future<StrategiesData> strategies() async =>
      StrategiesData.fromJson(await _req('GET', '/strategies'));

  @override
  Future<AppUser> updatePreferences(
          {String? strategy, bool setStrategy = false, double? accountSize, double? riskPct}) async =>
      _user(await _req('PUT', '/me/preferences', body: {
        if (setStrategy) 'strategy': strategy,
        if (accountSize != null) 'account_size': accountSize,
        if (riskPct != null) 'risk_pct': riskPct,
      }));

  @override
  Future<GoldTestData> goldTest() async => GoldTestData.fromJson(await _req('GET', '/gold-test'));

  @override
  Future<BotKey> botKey({bool rotate = false}) async =>
      BotKey.fromJson(await _req(rotate ? 'POST' : 'GET', '/me/ea-key'));

  @override
  Future<Map<String, bool>> promo() async {
    final d = await _req('GET', '/health');
    final p = (d['promo'] as Map?)?.cast<String, dynamic>() ?? const {};
    return {for (final e in p.entries) e.key: e.value == true};
  }

  Future<bool> pushEnabled() async =>
      (await _req('GET', '/health'))['push_enabled'] == true;

  @override
  Future<List<PriceQuote>> prices() async =>
      ((await _req('GET', '/market/prices'))['items'] as List)
          .map((e) => PriceQuote.fromJson((e as Map).cast<String, dynamic>()))
          .toList();
}
