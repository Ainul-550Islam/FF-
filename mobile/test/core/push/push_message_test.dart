import 'package:ffarena_mobile/core/push/push_message.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses the safe server payload fields', () {
    const message = PushMessage(
      title: 'Match completed',
      body: 'A match finished.',
      data: {
        'notification_id': '123',
        'type': 'match.completed',
        'category': 'match',
        'entity_type': 'match',
        'entity_id': '9',
        'deep_link': 'ffarena://match/9',
      },
    );

    expect(message.notificationId, '123');
    expect(message.type, 'match.completed');
    expect(message.category, 'match');
    expect(message.entityType, 'match');
    expect(message.entityId, '9');
    expect(message.deepLink, 'ffarena://match/9');
  });

  test('missing optional fields decode to null (never crash)', () {
    const message = PushMessage(title: '', body: '', data: {});

    expect(message.notificationId, isNull);
    expect(message.type, isNull);
    expect(message.category, isNull);
    expect(message.entityType, isNull);
    expect(message.entityId, isNull);
    expect(message.deepLink, isNull);
  });

  test('never trusts non-string field values', () {
    const message = PushMessage(
      title: 'x',
      body: 'y',
      data: {'notification_id': 123, 'deep_link': 42},
    );

    // Numeric values are ignored — the parser only accepts strings.
    expect(message.notificationId, isNull);
    expect(message.deepLink, isNull);
  });
}
