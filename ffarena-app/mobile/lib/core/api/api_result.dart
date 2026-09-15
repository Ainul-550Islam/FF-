import 'generated/openapi_models.dart';

/// A decoded `/api/v1` success envelope: `{ "data": ..., "meta": {...} }`.
///
/// [data] is the raw `data` node (a Map, a List, or a scalar); [meta] holds
/// the optional pagination/count metadata.
class ApiEnvelopeData {
  const ApiEnvelopeData({required this.data, this.meta = const {}});

  final dynamic data;
  final Map<String, dynamic> meta;

  /// Convenience accessor for a decoded [Pagination] meta block.
  Pagination? get pagination {
    final p = meta['pagination'];
    if (p is Map<String, dynamic>) {
      return Pagination.fromJson(p);
    }

    return null;
  }

  bool get hasMore {
    final p = pagination;
    if (p == null || p.lastPage == null || p.currentPage == null) {
      return false;
    }

    return (p.currentPage! < p.lastPage!);
  }

  int? get nextPage {
    final p = pagination;
    if (p == null || p.currentPage == null) {
      return null;
    }

    return p.currentPage! + 1;
  }

  List<Map<String, dynamic>> get asList {
    if (data is List) {
      return (data as List)
          .whereType<Map<String, dynamic>>()
          .toList(growable: false);
    }

    return const [];
  }

  Map<String, dynamic>? get asMap {
    if (data is Map<String, dynamic>) {
      return data as Map<String, dynamic>;
    }

    return null;
  }
}
