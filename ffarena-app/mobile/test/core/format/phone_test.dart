import 'package:ffarena_mobile/core/format/phone.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('normalizes local BD numbers to E.164', () {
    expect(Phone.normalizeBd('01712345678'), '+8801712345678');
    expect(Phone.normalizeBd('8801712345678'), '+8801712345678');
    expect(Phone.normalizeBd('+880 1712-345678'), '+8801712345678');
  });

  test('rejects invalid numbers', () {
    expect(Phone.normalizeBd('12345'), isNull);
    expect(Phone.normalizeBd('01112345678'), isNull); // invalid prefix
  });

  test('renders E.164 as local display', () {
    expect(Phone.display('+8801712345678'), '01712345678');
  });
}
