import 'package:ffarena_mobile/core/push/notification_dedup.dart';
import 'package:ffarena_mobile/core/push/push_message.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  PushMessage msg(String id) =>
      PushMessage(title: 't', body: 'b', data: {'notification_id': id});

  test('the first occurrence is kept, later ones are duplicates', () {
    final dedup = NotificationDedup();

    expect(dedup.isDuplicate(msg('42')), isFalse);
    expect(dedup.isDuplicate(msg('42')), isTrue);
    expect(dedup.isDuplicate(msg('43')), isFalse);
  });

  test('messages without a notification id are never deduplicated', () {
    final dedup = NotificationDedup();
    const noId = PushMessage(title: 't', body: 'b', data: {});

    expect(dedup.isDuplicate(noId), isFalse);
    expect(dedup.isDuplicate(noId), isFalse);
  });

  test('the dedup set stays bounded', () {
    final dedup = NotificationDedup(capacity: 3);

    for (var i = 0; i < 10; i++) {
      dedup.isDuplicate(msg('$i'));
    }

    // Oldest entries were evicted; the newest are still deduplicated.
    expect(dedup.isDuplicate(msg('9')), isTrue);
    expect(dedup.isDuplicate(msg('0')), isFalse);
  });
}
