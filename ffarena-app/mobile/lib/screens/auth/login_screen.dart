import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../../app.dart';
import '../../config/app_config.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import 'forgot_password_screen.dart';
import 'phone_login_screen.dart';
import 'register_screen.dart';

/// Email + password login (Phase 18 §5/§14). A thin client over the
/// documented `/auth/*` endpoints — the server stays authoritative.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    final email = _email.text.trim();
    final password = _password.text;
    if (email.isEmpty || password.isEmpty) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.services.auth.login(email, password);
      await _adopt(auth);
    } on ApiException catch (e) {
      _showError(e);
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _google() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final google = GoogleSignIn(
        serverClientId: AppConfig.instance.googleSignInClientId,
      );
      final account = await google.signIn();
      if (account == null) {
        setState(() => _busy = false);
        return; // User cancelled.
      }
      final authInfo = await account.authentication;
      final idToken = authInfo.idToken;
      if (idToken == null) {
        throw const ApiException(
            code: ApiException.invalidIdToken, message: 'Missing id token.');
      }
      final auth = await widget.services.auth.google(idToken);
      await _adopt(auth);
      await google.signOut();
    } on ApiException catch (e) {
      _showError(e);
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGoogleSignIn);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  /// Adopts a server-issued session (token + user) into the SessionManager.
  Future<void> _adopt(dynamic auth) async {
    final token = auth.token as String?;
    final user = auth.user;
    if (token == null || token.isEmpty || user == null) {
      throw const ApiException(
          code: ApiException.invalidCredentials, message: 'Bad session.');
    }
    await widget.services.session.establish(
      token: token,
      user: user,
      tokenExpiresAt: auth.tokenExpiresAt as String?,
    );
  }

  void _showError(ApiException e) {
    setState(() => _error = e.localized(AppLocalizations.of(context)));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final config = AppConfig.instance;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    l10n.appName,
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .headlineMedium
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    l10n.tagline,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 32),
                  TextField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.email],
                    decoration: InputDecoration(
                      labelText: l10n.email,
                      prefixIcon: const Icon(Icons.mail_outline),
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    autofillHints: const [AutofillHints.password],
                    decoration: InputDecoration(
                      labelText: l10n.password,
                      prefixIcon: const Icon(Icons.lock_outline),
                    ),
                    onSubmitted: (_) => _login(),
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => const ForgotPasswordScreen(),
                        ),
                      ),
                      child: Text(l10n.forgotPassword),
                    ),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: _busy ? null : _login,
                    child: _busy
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(l10n.logIn),
                  ),
                  if (config.isGoogleSignInConfigured) ...[
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : _google,
                      icon: const Icon(Icons.g_mobiledata, size: 24),
                      label: Text(l10n.continueWithGoogle),
                    ),
                  ],
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: _busy
                        ? null
                        : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) =>
                                    PhoneLoginScreen(services: widget.services),
                              ),
                            ),
                    icon: const Icon(Icons.phone_outlined),
                    label: Text(l10n.continueWithPhone),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) =>
                                    RegisterScreen(services: widget.services),
                              ),
                            ),
                    child: Text(l10n.createAccount),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 8),
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
      ),
    );
  }
}
