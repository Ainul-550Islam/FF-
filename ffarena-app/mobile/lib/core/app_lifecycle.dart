import 'package:flutter/widgets.dart';

/// Observes app lifecycle transitions (Phase 19 §42/§45).
///
/// On resume the app re-checks the server release gate and re-syncs the push
/// token — a device that was backgrounded during a maintenance window or a
/// token rotation picks the new state up immediately.
class AppLifecycleObserver with WidgetsBindingObserver {
  AppLifecycleObserver({required this.onResume});

  final Future<void> Function() onResume;

  void attach() => WidgetsBinding.instance.addObserver(this);

  void detach() => WidgetsBinding.instance.removeObserver(this);

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      onResume();
    }
  }
}
