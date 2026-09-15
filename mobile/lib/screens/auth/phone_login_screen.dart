import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/format/phone.dart';
import '../../core/l10n/app_localizations.dart';
import 'otp_verify_screen.dart';

/// Phone OTP login — step 1 of 2 (Phase 18 §14).
class PhoneLoginScreen extends StatefulWidget {
  const PhoneLoginScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PhoneLoginScreen> createState() => _PhoneLoginScreenState();
}

class _PhoneLoginScreenState extends State<PhoneLoginScreen> {
  final _phone = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final normalized = Phone.normalizeBd(_phone.text);
    if (normalized == null) {
      setState(() => _error = AppLocalizations.of(context).phone);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.auth.requestOtp(normalized, purpose: 'login');
      if (!mounted) {
        return;
      }
      Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => OtpVerifyScreen(
            services: widget.services,
            phone: normalized,
            purpose: 'login',
          ),
        ),
      );
    } on ApiException catch (e) {
      setState(() => _error = e.localized(AppLocalizations.of(context)));
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
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
      appBar: AppBar(title: Text(l10n.continueWithPhone)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: l10n.phone,
                    prefixText: '+880 ',
                  ),
                  onSubmitted: (_) => _send(),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _busy ? null : _send,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.sendCode),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
