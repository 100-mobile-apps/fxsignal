import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/core/config.dart';
import 'package:forex_signals/data/api.dart';
import 'package:forex_signals/data/remote_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('reads server-side push availability for fallback scheduling', () async {
    final client = MockClient((request) async =>
        http.Response('{"push_enabled":false}', 200));
    addTearDown(client.close);
    expect(await RemoteApi('https://api.example.com', client: client).pushEnabled(), false);
  });

  test('retries a same-origin 307 to a canonical path once', () async {
    final paths = <String>[];
    final client = MockClient((request) async {
      paths.add(request.url.path);
      if (paths.length == 1) {
        return http.Response('Redirect', 307, headers: {'location': '/health/'});
      }
      return http.Response('{"promo":{"coffee":false}}', 200);
    });
    addTearDown(client.close);

    final promo = await RemoteApi('https://api.example.com', client: client).promo();
    expect(paths, ['/health', '/health/']);
    expect(promo['coffee'], false);
  });

  test('never forwards login credentials to a redirect on another origin', () async {
    var requests = 0;
    final client = MockClient((request) async {
      requests++;
      expect(request.url.host, 'api.example.com');
      return http.Response('<html>Redirect</html>', 307,
          headers: {'location': 'https://example.net/login?token=secret'});
    });
    addTearDown(client.close);

    await expectLater(
      RemoteApi('https://api.example.com', client: client)
          .login('test@example.com', 'PrivatePassword123'),
      throwsA(isA<ApiException>().having(
          (e) => e.message, 'safe redirect diagnostic',
          allOf(contains('example.net/login'), isNot(contains('secret'))))),
    );
    expect(requests, 1);
  });

  test('preserves login POST only across a same-origin canonical 307', () async {
    final paths = <String>[];
    final client = MockClient((request) async {
      paths.add(request.url.path);
      expect(request.method, 'POST');
      expect(request.body, contains('PrivatePassword123'));
      if (paths.length == 1) {
        return http.Response('Redirect', 307,
            headers: {'location': '/auth/login/'});
      }
      return http.Response('{"error":"Incorrect email or password."}', 401,
          headers: {'content-type': 'application/json'});
    });
    addTearDown(client.close);

    await expectLater(
      RemoteApi('https://api.example.com', client: client)
          .login('test@example.com', 'PrivatePassword123'),
      throwsA(isA<ApiException>().having((e) => e.status, 'login status', 401)),
    );
    expect(paths, ['/auth/login', '/auth/login/']);
  });

  test('does not replay a login to an unrelated path on the same host', () async {
    var requests = 0;
    final client = MockClient((request) async {
      requests++;
      return http.Response('Redirect', 307,
          headers: {'location': '/security-challenge'});
    });
    addTearDown(client.close);

    await expectLater(
      RemoteApi('https://api.example.com', client: client)
          .login('test@example.com', 'PrivatePassword123'),
      throwsA(isA<ApiException>().having(
          (e) => e.message, 'challenge redirect', contains('api.example.com/security-challenge'))),
    );
    expect(requests, 1);
  });

  test('sends the app build so the server can tell new apps from old ones', () async {
    String? build;
    final client = MockClient((request) async {
      build = request.headers['X-App-Build'];
      return http.Response('{"promo":{}}', 200);
    });
    addTearDown(client.close);
    await RemoteApi('https://api.example.com', client: client).promo();
    expect(build, '${AppConfig.build}');
  });

  test('parses a locked live signal without levels', () async {
    final client = MockClient((request) async => http.Response(
        '{"items":[{"id":7,"pair":"EUR/USD","timeframe":"4h","direction":"BUY","entry":null,'
        '"stop_loss":null,"tp1":null,"tp2":null,"confidence":78,"reasons":[],"status":"running",'
        '"result":null,"tp1_hit_at":null,"opened_at":"2026-11-10T08:00:00Z","expires_at":null,'
        '"closed_at":null,"close_price":null,"pips":null,"current_price":1.085,"live_pips":null,'
        '"updated_at":null,"locked":true}],"page":1,"has_more":false}', 200));
    addTearDown(client.close);
    final page = await RemoteApi('https://api.example.com', client: client).signals(running: true);
    expect(page.items.single.locked, true);
    expect(page.items.single.entry, 0);
    expect(page.items.single.direction, 'BUY');
  });

  test('sends the purchase to the server for verification', () async {
    Map<String, dynamic>? sent;
    final client = MockClient((request) async {
      expect(request.url.path, '/billing/verify');
      sent = (jsonDecode(request.body) as Map).cast<String, dynamic>();
      return http.Response(
          '{"user":{"id":1,"name":"A","email":"a@b.co","premium":true,"premium_plan":"yearly",'
          '"premium_until":"2027-11-10T00:00:00Z","billing_account_id":"abc"}}', 200);
    });
    addTearDown(client.close);
    final u = await RemoteApi('https://api.example.com', client: client)
        .verifyPurchase(AppConfig.yearlyProduct, 'token-123');
    expect(sent, {'product_id': 'fx_premium_yearly', 'purchase_token': 'token-123'});
    expect(u.premium, true);
    expect(u.premiumPlan, 'yearly');
  });
}
