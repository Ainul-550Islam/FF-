import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Edit profile (Phase 18 §59): only the documented, non-sensitive fields.
class ProfileEditScreen extends StatefulWidget {
  const ProfileEditScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<ProfileEditScreen> createState() => _ProfileEditScreenState();
}

class _ProfileEditScreenState extends State<ProfileEditScreen> {
  late final TextEditingController _name;
  late final TextEditingController _bio;
  late final TextEditingController _country;
  late final TextEditingController _region;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final me = widget.services.session.me;
    _name = TextEditingController(text: me?.name ?? '');
    _bio = TextEditingController(text: me?.bio ?? '');
    _country = TextEditingController(text: me?.country ?? '');
    _region = TextEditingController(text: me?.region ?? '');
  }

  @override
  void dispose() {
    _name.dispose();
    _bio.dispose();
    _country.dispose();
    _region.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final me = await widget.services.profile.update(
        name: _name.text.trim(),
        bio: _bio.text.trim(),
        country: _country.text.trim(),
        region: _region.text.trim(),
      );
      // Refresh the session's cached user.
      await widget.services.session.refreshUser(me);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(l10n.saved)));
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.editProfile)),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: InputDecoration(labelText: l10n.name),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _bio,
              maxLines: 3,
              decoration: InputDecoration(labelText: l10n.bio),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _country,
              decoration: InputDecoration(labelText: l10n.country),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _region,
              decoration: InputDecoration(labelText: l10n.region),
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _save,
              child: _busy
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(l10n.save),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
