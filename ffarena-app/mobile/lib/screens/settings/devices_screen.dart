import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';

/// Device management (Phase 19 §9). Lists the caller's registered push
/// devices and lets them remove one. Never exposes another user's devices
/// and never shows token material (the API does not return it).
class DevicesScreen extends StatefulWidget {
  const DevicesScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<DevicesScreen> createState() => _DevicesScreenState();
}

class _DevicesScreenState extends State<DevicesScreen> {
  List<MobileDevice> _devices = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _loading = true;
    });
    try {
      final devices = await widget.services.devices.list();
      if (mounted) {
        setState(() => _devices = devices);
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.localized(l10n))));
      }
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  Future<void> _remove(int id) async {
    final l10n = AppLocalizations.of(context);
    try {
      await widget.services.devices.delete(id);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(l10n.deviceRevoked)));
        await _load();
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.localized(l10n))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.devices)),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _devices.isEmpty
              ? Center(child: Text(l10n.noDevices))
              : ListView.separated(
                  itemCount: _devices.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final device = _devices[index];
                    final subtitle = [
                      device.provider ?? '—',
                      if (device.deviceLabel != null &&
                          device.deviceLabel!.isNotEmpty)
                        device.deviceLabel!,
                      if (device.appVersion != null &&
                          device.appVersion!.isNotEmpty)
                        'v${device.appVersion}',
                      if (device.isActive == false) l10n.deviceInactive,
                    ].join(' · ');

                    return ListTile(
                      leading: Icon(
                        (device.platform ?? 'android') == 'ios'
                            ? Icons.phone_iphone
                            : Icons.phone_android,
                      ),
                      title: Text(device.platform ?? 'device'),
                      subtitle: Text(subtitle),
                      trailing: IconButton(
                        tooltip: l10n.removeDevice,
                        icon: const Icon(Icons.delete_outline),
                        onPressed: device.id == null
                            ? null
                            : () => _remove(device.id!),
                      ),
                    );
                  },
                ),
    );
  }
}
