import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/format/phone.dart';
import '../../core/l10n/app_localizations.dart';

/// Link a phone number to the signed-in account (Phase 18 §61). Uses the
/// documented OTP flow with `purpose=signup`, which links the verified phone
/// to the current user server-side.
class PhoneLinkScreen extends StatefulWidget {
  const PhoneLinkScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PhoneLinkScreen> createState() => _PhoneLinkScreenState();
}

class _PhoneLinkScreenState extends State<PhoneLinkScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  bool _codeRequested = false;
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _request() async {
    final normalized = Phone.normalizeBd(_phone.text);
    final l10n = AppLocalizations.of(context);
    if (normalized == null) {
      setState(() => _error = l10n.phone);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.auth.requestOtp(normalized, purpose: 'signup');
      setState(() => _codeRequested = true);
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _verify() async {
    final normalized = Phone.normalizeBd(_phone.text);
    final l10n = AppLocalizations.of(context);
    if (normalized == null) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.auth
          .verifyOtp(normalized, _code.text.trim(), purpose: 'signup');
      setState(() => _done = l10n.phoneLinked);
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
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
      appBar: AppBar(title: Text(l10n.linkPhone)),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.phoneLinkBody),
            const SizedBox(height: 16),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration:
                  InputDecoration(labelText: l10n.phone, prefixText: '+880 '),
            ),
            if (_codeRequested) ...[
              const SizedBox(height: 16),
              TextField(
                controller: _code,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: l10n.enterCode),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : (_codeRequested ? _verify : _request),
              child: _busy
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(_codeRequested ? l10n.verify : l10n.sendCode),
            ),
            if (_done != null) ...[
              const SizedBox(height: 16),
              Text(
                _done!,
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
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
