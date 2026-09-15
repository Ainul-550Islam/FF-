import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Phone OTP verification — step 2 of 2 (Phase 18 §14). Reuses the SAME
/// logical attempt via an Idempotency-Key only inside the repository; a
/// failed verify here simply requires the user to re-request a code.
class OtpVerifyScreen extends StatefulWidget {
  const OtpVerifyScreen({
    super.key,
    required this.services,
    required this.phone,
    required this.purpose,
  });

  final AppServices services;
  final String phone;
  final String purpose;

  @override
  State<OtpVerifyScreen> createState() => _OtpVerifyScreenState();
}

class _OtpVerifyScreenState extends State<OtpVerifyScreen> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final code = _code.text.trim();
    if (code.isEmpty) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.services.auth
          .verifyOtp(widget.phone, code, purpose: widget.purpose);
      final token = auth.token;
      final user = auth.user;
      if (token == null || user == null) {
        throw const ApiException(
            code: ApiException.invalidCode, message: 'Bad session.');
      }
      await widget.services.session.establish(
        token: token,
        user: user,
        tokenExpiresAt: auth.tokenExpiresAt,
      );
      // On successful login the FFApp routes to the shell automatically.
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
      appBar: AppBar(title: Text(l10n.enterCode)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(l10n.codeSentTo, textAlign: TextAlign.center),
                const SizedBox(height: 16),
                TextField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  autofillHints: const [AutofillHints.oneTimeCode],
                  textAlign: TextAlign.center,
                  style: const TextStyle(letterSpacing: 8, fontSize: 20),
                  decoration: InputDecoration(labelText: l10n.enterCode),
                  onSubmitted: (_) => _verify(),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _busy ? null : _verify,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.verify),
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
