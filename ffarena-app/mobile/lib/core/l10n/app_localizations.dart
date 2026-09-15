import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart';

/// Application strings — English + Bangla (Phase 18 §68).
///
/// Strings are resolved from a plain map (no code generation) so the app
/// stays lightweight and testable. English is the fallback; Bangla (bn) is
/// the second supported locale. WCAG 2.2 AA: strings avoid relying on colour
/// alone and keep sentence case for readability.
class AppLocalizations {
  AppLocalizations(this.locale) : _isBn = locale.languageCode == 'bn';

  final Locale locale;
  final bool _isBn;

  static const supportedLocales = [Locale('en'), Locale('bn')];
  static const localizationsDelegates = [
    delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ];

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  static AppLocalizations of(BuildContext context) =>
      Localizations.of<AppLocalizations>(context, AppLocalizations)!;

  static AppLocalizations? maybeOf(BuildContext context) =>
      Localizations.of<AppLocalizations>(context, AppLocalizations);

  /// Universal accessor (fallback: English, then the key itself).
  String t(String key) => (_isBn ? _bn[key] : null) ?? _en[key] ?? key;

  // --- App / onboarding ---
  String get appName => t('appName');
  String get tagline => t('tagline');
  String get getStarted => t('getStarted');
  String get onboardingWelcomeTitle => t('onboardingWelcomeTitle');
  String get onboardingWelcomeBody => t('onboardingWelcomeBody');
  String get onboardingPlayTitle => t('onboardingPlayTitle');
  String get onboardingPlayBody => t('onboardingPlayBody');
  String get onboardingWinTitle => t('onboardingWinTitle');
  String get onboardingWinBody => t('onboardingWinBody');

  // --- Auth ---
  String get logIn => t('logIn');
  String get logout => t('logout');
  String get createAccount => t('createAccount');
  String get continueWithGoogle => t('continueWithGoogle');
  String get continueWithPhone => t('continueWithPhone');
  String get email => t('email');
  String get password => t('password');
  String get passwordConfirmation => t('passwordConfirmation');
  String get name => t('name');
  String get username => t('username');
  String get phone => t('phone');
  String get gameUid => t('gameUid');
  String get role => t('role');
  String get rolePlayer => t('rolePlayer');
  String get roleOrganizer => t('roleOrganizer');
  String get forgotPassword => t('forgotPassword');
  String get forgotPasswordBody => t('forgotPasswordBody');
  String get forgotPasswordNote => t('forgotPasswordNote');
  String get backToLogin => t('backToLogin');
  String get sendCode => t('sendCode');
  String get verify => t('verify');
  String get resendCode => t('resendCode');
  String get codeSentTo => t('codeSentTo');
  String get enterCode => t('enterCode');

  // --- Navigation ---
  String get home => t('home');
  String get tournaments => t('tournaments');
  String get matches => t('matches');
  String get leaderboard => t('leaderboard');
  String get profile => t('profile');
  String get wallet => t('wallet');
  String get notifications => t('notifications');
  String get settings => t('settings');
  String get support => t('support');
  String get myTeams => t('myTeams');
  String get live => t('live');
  String get viewAll => t('viewAll');

  // --- Tournaments / registration ---
  String get register => t('register');
  String get registered => t('registered');
  String get waitlisted => t('waitlisted');
  String get checkIn => t('checkIn');
  String get checkedIn => t('checkedIn');
  String get entryFee => t('entryFee');
  String get prizePool => t('prizePool');
  String get slotsLeft => t('slotsLeft');
  String get startsAt => t('startsAt');
  String get checkInOpens => t('checkInOpens');
  String get checkInCloses => t('checkInCloses');
  String get teamSize => t('teamSize');
  String get gameMode => t('gameMode');
  String get map => t('map');
  String get format => t('format');
  String get status => t('status');
  String get viewBracket => t('viewBracket');
  String get viewMatches => t('viewMatches');
  String get viewLeaderboard => t('viewLeaderboard');
  String get teamIsWaitlisted => t('teamIsWaitlisted');
  String get waitlistPositionLabel => t('waitlistPositionLabel');
  String get search => t('search');
  String get all => t('all');
  String get upcoming => t('upcoming');
  String get finished => t('finished');
  String get teamName => t('teamName');
  String get captainName => t('captainName');
  String get members => t('members');
  String get memberName => t('memberName');

  // --- Teams ---
  String get joinTeam => t('joinTeam');
  String get createTeam => t('createTeam');
  String get addMember => t('addMember');
  String get removeMember => t('removeMember');
  String get withdraw => t('withdraw');
  String get confirmWithdraw => t('confirmWithdraw');
  String get withdrawBody => t('withdrawBody');
  String get roster => t('roster');
  String get noTeams => t('noTeams');

  // --- Matches / scores ---
  String get matchNo => t('matchNo');
  String get round => t('round');
  String get roomId => t('roomId');
  String get roomPass => t('roomPass');
  String get scheduled => t('scheduled');
  String get completed => t('completed');
  String get kills => t('kills');
  String get placement => t('placement');
  String get points => t('points');
  String get submitScore => t('submitScore');
  String get scoreSubmitted => t('scoreSubmitted');
  String get noScoresYet => t('noScoresYet');
  String get myUpcomingMatches => t('myUpcomingMatches');

  // --- Leaderboard ---
  String get rank => t('rank');
  String get team => t('team');
  String get standings => t('standings');

  // --- Wallet ---
  String get balance => t('balance');
  String get ledger => t('ledger');
  String get payouts => t('payouts');
  String get payout => t('payout');
  String get amount => t('amount');
  String get provider => t('provider');
  String get redirectingToPayment => t('redirectingToPayment');
  String get paymentPending => t('paymentPending');
  String get paymentPaid => t('paymentPaid');
  String get paymentFailed => t('paymentFailed');
  String get pollForStatus => t('pollForStatus');
  String get savedMethods => t('savedMethods');
  String get noSavedMethods => t('noSavedMethods');

  // --- Notifications ---
  String get noNotifications => t('noNotifications');
  String get markAllRead => t('markAllRead');
  String get unread => t('unread');

  // --- Support / disputes ---
  String get subject => t('subject');
  String get category => t('category');
  String get priority => t('priority');
  String get message => t('message');
  String get sendMessage => t('sendMessage');
  String get createTicket => t('createTicket');
  String get ticketCreated => t('ticketCreated');
  String get noTickets => t('noTickets');
  String get waitingForReply => t('waitingForReply');
  String get dispute => t('dispute');
  String get disputeWebOnly => t('disputeWebOnly');
  String get noDisputes => t('noDisputes');

  // --- Profile / settings ---
  String get accountSecurity => t('accountSecurity');
  String get securityStatus => t('securityStatus');
  String get signInMethods => t('signInMethods');
  String get emailVerified => t('emailVerified');
  String get emailNotVerified => t('emailNotVerified');
  String get hasPassword => t('hasPassword');
  String get noPassword => t('noPassword');
  String get accountStatus => t('accountStatus');
  String get active => t('active');
  String get sessions => t('sessions');
  String get revoke => t('revoke');
  String get revokeAll => t('revokeAll');
  String get revokeOthers => t('revokeOthers');
  String get currentSession => t('currentSession');
  String get thisDevice => t('thisDevice');
  String get lastActive => t('lastActive');
  String get privacy => t('privacy');
  String get privacyPublic => t('privacyPublic');
  String get privacyRegistered => t('privacyRegistered');
  String get privacyPrivate => t('privacyPrivate');
  String get publicProfile => t('publicProfile');
  String get editProfile => t('editProfile');
  String get bio => t('bio');
  String get country => t('country');
  String get region => t('region');
  String get avatar => t('avatar');
  String get save => t('save');
  String get saved => t('saved');
  String get cancel => t('cancel');
  String get confirm => t('confirm');
  String get retry => t('retry');
  String get loading => t('loading');
  String get empty => t('empty');
  String get version => t('version');
  String get language => t('language');
  String get english => t('english');
  String get bangla => t('bangla');
  String get pushStatus => t('pushStatus');
  String get pushDisabled => t('pushDisabled');
  String get pushEnabled => t('pushEnabled');
  String get notifPreferences => t('notifPreferences');
  String get pushPrefsIntro => t('pushPrefsIntro');
  String get prefTournament => t('prefTournament');
  String get prefMatch => t('prefMatch');
  String get prefTeam => t('prefTeam');
  String get prefPayment => t('prefPayment');
  String get prefPayout => t('prefPayout');
  String get prefDispute => t('prefDispute');
  String get prefSecurity => t('prefSecurity');
  String get prefSupport => t('prefSupport');
  String get prefSecurityLocked => t('prefSecurityLocked');
  String get devices => t('devices');
  String get noDevices => t('noDevices');
  String get deviceRevoked => t('deviceRevoked');
  String get removeDevice => t('removeDevice');
  String get deviceInactive => t('deviceInactive');
  String get maintenanceTitle => t('maintenanceTitle');
  String get updateRequiredTitle => t('updateRequiredTitle');
  String get updateRequiredBody => t('updateRequiredBody');
  String get updateAvailableTitle => t('updateAvailableTitle');
  String get updateAvailableBody => t('updateAvailableBody');
  String get openStore => t('openStore');
  String get checkAgain => t('checkAgain');
  String get enableInSettings => t('enableInSettings');
  String get permissionDeniedHint => t('permissionDeniedHint');
  String get about => t('about');
  String get terms => t('terms');
  String get privacyPolicy => t('privacyPolicy');
  String get contactSupport => t('contactSupport');
  String get connectedAccounts => t('connectedAccounts');
  String get google => t('google');
  String get phoneMethod => t('phoneMethod');
  String get emailMethod => t('emailMethod');
  String get linkPhone => t('linkPhone');
  String get phoneLinked => t('phoneLinked');
  String get phoneLinkBody => t('phoneLinkBody');
  String get verifyPhone => t('verifyPhone');
  String get changePasswordWebOnly => t('changePasswordWebOnly');
  String get changePasswordWebBody => t('changePasswordWebBody');
  String get deactivationWebOnly => t('deactivationWebOnly');
  String get deactivationWebBody => t('deactivationWebBody');

  // --- Security events ---
  String get securityEventTitle => t('securityEventTitle');
  String get sessionExpiredBody => t('sessionExpiredBody');
  String get tokenRevokedBody => t('tokenRevokedBody');
  String get accountInactiveTitle => t('accountInactiveTitle');
  String get accountInactiveBody => t('accountInactiveBody');
  String get loginAgain => t('loginAgain');
  String get ok => t('ok');

  // --- Errors / offline ---
  String get offlineBanner => t('offlineBanner');
  String get staleBanner => t('staleBanner');
  String get errorOffline => t('errorOffline');
  String get errorTimeout => t('errorTimeout');
  String get errorRateLimited => t('errorRateLimited');
  String get errorInvalidCredentials => t('errorInvalidCredentials');
  String get errorInvalidCode => t('errorInvalidCode');
  String get errorNoAccount => t('errorNoAccount');
  String get errorGoogleSignIn => t('errorGoogleSignIn');
  String get errorAccountInactive => t('errorAccountInactive');
  String get errorSessionExpired => t('errorSessionExpired');
  String get errorServer => t('errorServer');
  String get errorGeneric => t('errorGeneric');
  String get errorUnexpected => t('errorUnexpected');

  /// Formats a BDT amount in minor units using the current locale.
  String formatMoney(num amountMinor) {
    final taka = amountMinor / 100;
    final nf = NumberFormat.currency(
      locale: locale.toString(),
      symbol: '৳',
      decimalDigits: 2,
    );
    return nf.format(taka);
  }

  static const Map<String, String> _en = {
    'appName': 'FF Arena',
    'tagline': 'Compete. Track. Win.',
    'getStarted': 'Get started',
    'onboardingWelcomeTitle': 'Welcome to FF Arena',
    'onboardingWelcomeBody':
        'The official mobile companion for FF Arena tournaments in Bangladesh.',
    'onboardingPlayTitle': 'Compete',
    'onboardingPlayBody':
        'Register your squad, pay the entry fee, and check in for live matches.',
    'onboardingWinTitle': 'Track & win',
    'onboardingWinBody':
        'Follow standings, submit scores, and withdraw your winnings.',
    'logIn': 'Log in',
    'logout': 'Log out',
    'createAccount': 'Create account',
    'continueWithGoogle': 'Continue with Google',
    'continueWithPhone': 'Continue with phone',
    'email': 'Email',
    'password': 'Password',
    'passwordConfirmation': 'Confirm password',
    'name': 'Full name',
    'username': 'Username',
    'phone': 'Phone',
    'gameUid': 'Free Fire UID',
    'role': 'Role',
    'rolePlayer': 'Player',
    'roleOrganizer': 'Organizer',
    'forgotPassword': 'Forgot password?',
    'forgotPasswordBody':
        'Password reset is available on the web. Open ffarena in your browser and use "Forgot password".',
    'forgotPasswordNote': 'For your security we do not reset passwords in-app.',
    'backToLogin': 'Back to log in',
    'sendCode': 'Send code',
    'verify': 'Verify',
    'resendCode': 'Resend code',
    'codeSentTo': 'We sent a verification code to your phone.',
    'enterCode': 'Enter the code',
    'home': 'Home',
    'tournaments': 'Tournaments',
    'matches': 'Matches',
    'leaderboard': 'Leaderboard',
    'profile': 'Profile',
    'wallet': 'Wallet',
    'notifications': 'Notifications',
    'settings': 'Settings',
    'support': 'Support',
    'myTeams': 'My teams',
    'live': 'Live',
    'viewAll': 'View all',
    'register': 'Register',
    'registered': 'Registered',
    'waitlisted': 'Waitlisted',
    'checkIn': 'Check in',
    'checkedIn': 'Checked in',
    'entryFee': 'Entry fee',
    'prizePool': 'Prize pool',
    'slotsLeft': 'Slots left',
    'startsAt': 'Starts',
    'checkInOpens': 'Check-in opens',
    'checkInCloses': 'Check-in closes',
    'teamSize': 'Team size',
    'gameMode': 'Mode',
    'map': 'Map',
    'format': 'Format',
    'status': 'Status',
    'viewBracket': 'Bracket',
    'viewMatches': 'Matches',
    'viewLeaderboard': 'Standings',
    'teamIsWaitlisted': 'Your team is on the waitlist.',
    'waitlistPositionLabel': 'Waitlist position',
    'search': 'Search',
    'all': 'All',
    'upcoming': 'Upcoming',
    'finished': 'Finished',
    'teamName': 'Team name',
    'captainName': 'Captain name',
    'members': 'Members',
    'memberName': 'Player name',
    'joinTeam': 'Join a team',
    'createTeam': 'Create a team',
    'addMember': 'Add member',
    'removeMember': 'Remove',
    'withdraw': 'Withdraw',
    'confirmWithdraw': 'Withdraw team?',
    'withdrawBody':
        'Withdrawing removes your team from this tournament. Entry-fee refunds follow the tournament refund policy.',
    'roster': 'Roster',
    'noTeams': 'You are not part of any team yet.',
    'matchNo': 'Match',
    'round': 'Round',
    'roomId': 'Room ID',
    'roomPass': 'Room password',
    'scheduled': 'Scheduled',
    'completed': 'Completed',
    'kills': 'Kills',
    'placement': 'Placement',
    'points': 'Points',
    'submitScore': 'Submit score',
    'scoreSubmitted': 'Score submitted',
    'noScoresYet': 'No scores submitted yet.',
    'myUpcomingMatches': 'My upcoming matches',
    'rank': 'Rank',
    'team': 'Team',
    'standings': 'Standings',
    'balance': 'Balance',
    'ledger': 'Ledger',
    'payouts': 'Payouts',
    'payout': 'Payout',
    'amount': 'Amount',
    'provider': 'Provider',
    'redirectingToPayment': 'Opening your payment provider…',
    'paymentPending':
        'Payment is being processed. We will confirm once the provider reports back.',
    'paymentPaid': 'Payment confirmed.',
    'paymentFailed': 'Payment was not completed.',
    'pollForStatus': 'Checking payment status…',
    'savedMethods': 'Saved methods',
    'noSavedMethods': 'No saved payment methods.',
    'noNotifications': 'No notifications yet.',
    'markAllRead': 'Mark all read',
    'unread': 'Unread',
    'subject': 'Subject',
    'category': 'Category',
    'priority': 'Priority',
    'message': 'Message',
    'sendMessage': 'Send',
    'createTicket': 'New ticket',
    'ticketCreated': 'Ticket created. We will reply soon.',
    'noTickets': 'No support tickets.',
    'waitingForReply': 'Waiting for a reply…',
    'dispute': 'Dispute',
    'disputeWebOnly':
        'Disputes are filed from the web match page. You can track your filed disputes here.',
    'noDisputes': 'No disputes.',
    'accountSecurity': 'Account security',
    'securityStatus': 'Security status',
    'signInMethods': 'Sign-in methods',
    'emailVerified': 'Email verified',
    'emailNotVerified': 'Email not verified',
    'hasPassword': 'Password set',
    'noPassword': 'No password',
    'accountStatus': 'Account status',
    'active': 'Active',
    'sessions': 'Active sessions',
    'revoke': 'Revoke',
    'revokeAll': 'Log out everywhere',
    'revokeOthers': 'Log out other devices',
    'currentSession': 'This device',
    'thisDevice': 'This device',
    'lastActive': 'Last active',
    'privacy': 'Privacy',
    'privacyPublic': 'Public — anyone can view your profile',
    'privacyRegistered': 'Registered users only',
    'privacyPrivate': 'Private — hidden from everyone',
    'publicProfile': 'Public profile',
    'editProfile': 'Edit profile',
    'bio': 'Bio',
    'country': 'Country',
    'region': 'Region',
    'avatar': 'Avatar URL',
    'save': 'Save',
    'saved': 'Saved',
    'cancel': 'Cancel',
    'confirm': 'Confirm',
    'retry': 'Retry',
    'loading': 'Loading…',
    'empty': 'Nothing here yet.',
    'version': 'Version',
    'language': 'Language',
    'english': 'English',
    'bangla': 'বাংলা',
    'pushStatus': 'Push notifications',
    'pushDisabled': 'Push notifications are not configured for this build.',
    'pushEnabled': 'Push notifications are enabled.',
    'notifPreferences': 'Notification preferences',
    'pushPrefsIntro':
        'Choose which push notifications you receive. In-app and email notifications are not affected.',
    'prefTournament': 'Tournaments',
    'prefMatch': 'Matches',
    'prefTeam': 'Teams',
    'prefPayment': 'Payments',
    'prefPayout': 'Payouts',
    'prefDispute': 'Disputes',
    'prefSecurity': 'Security alerts',
    'prefSupport': 'Support',
    'prefSecurityLocked':
        'Security alerts are always sent and cannot be turned off.',
    'devices': 'Devices',
    'noDevices': 'No devices registered.',
    'deviceRevoked': 'Device removed.',
    'removeDevice': 'Remove device',
    'deviceInactive': 'Inactive',
    'maintenanceTitle': 'Under maintenance',
    'updateRequiredTitle': 'Update required',
    'updateRequiredBody':
        'This version is no longer supported. Please update the app to continue.',
    'updateAvailableTitle': 'Update available',
    'updateAvailableBody':
        'A newer version is available. You can keep using this version.',
    'openStore': 'Update',
    'checkAgain': 'Check again',
    'enableInSettings': 'Enable in settings',
    'permissionDeniedHint':
        'Notifications are off. You can enable them in system settings.',
    'about': 'About',
    'terms': 'Terms of service',
    'privacyPolicy': 'Privacy policy',
    'contactSupport': 'Contact support',
    'connectedAccounts': 'Connected accounts',
    'google': 'Google',
    'phoneMethod': 'Phone',
    'emailMethod': 'Email',
    'linkPhone': 'Link phone number',
    'phoneLinked': 'Phone number linked.',
    'phoneLinkBody': 'Link a phone number so you can log in with it.',
    'verifyPhone': 'Verify phone',
    'changePasswordWebOnly': 'Change password',
    'changePasswordWebBody':
        'Password changes are available on the web. Open FF Arena in your browser to change or reset your password.',
    'deactivationWebOnly': 'Account lifecycle',
    'deactivationWebBody':
        'Account deactivation and deletion requests are handled on the web for your safety.',
    'securityEventTitle': 'Session ended',
    'sessionExpiredBody': 'Your session expired. Please log in again.',
    'tokenRevokedBody': 'You were signed out on this device.',
    'accountInactiveTitle': 'Account unavailable',
    'accountInactiveBody':
        'This account has been deactivated. Contact support if you believe this is a mistake.',
    'loginAgain': 'Log in again',
    'ok': 'OK',
    'offlineBanner': 'You are offline — showing saved data.',
    'staleBanner': 'Showing saved data. Pull to refresh when online.',
    'errorOffline': 'You are offline. Check your connection.',
    'errorTimeout': 'The server took too long to respond.',
    'errorRateLimited': 'Too many attempts. Please wait a moment.',
    'errorInvalidCredentials': 'That email or password is not correct.',
    'errorInvalidCode': 'That code is not valid.',
    'errorNoAccount': 'No account is linked to this phone number.',
    'errorGoogleSignIn': 'Could not complete Google sign-in. Please try again.',
    'errorAccountInactive': 'This account has been deactivated.',
    'errorSessionExpired': 'Your session expired. Please log in again.',
    'errorServer': 'The server had a problem. Please try again.',
    'errorGeneric': 'Something went wrong. Please try again.',
    'errorUnexpected': 'An unexpected error occurred.',
  };

  static const Map<String, String> _bn = {
    'appName': 'এফএফ এরিনা',
    'tagline': 'প্রতিযোগিতা করুন। ফলো করুন। জিতুন।',
    'getStarted': 'শুরু করুন',
    'onboardingWelcomeTitle': 'এফএফ এরিনায় স্বাগতম',
    'onboardingWelcomeBody':
        'বাংলাদেশের এফএফ এরিনা টুর্নামেন্টের অফিসিয়াল মোবাইল অ্যাপ।',
    'onboardingPlayTitle': 'প্রতিযোগিতা',
    'onboardingPlayBody':
        'আপনার স্কোয়াড রেজিস্টার করুন, এন্ট্রি ফি দিন এবং লাইভ ম্যাচে চেক-ইন করুন।',
    'onboardingWinTitle': 'ফলো করুন ও জিতুন',
    'onboardingWinBody':
        'স্ট্যান্ডিং দেখুন, স্কোর জমা দিন এবং পুরস্কার তুলে নিন।',
    'logIn': 'লগ ইন',
    'logout': 'লগ আউট',
    'createAccount': 'অ্যাকাউন্ট তৈরি করুন',
    'continueWithGoogle': 'গুগল দিয়ে চালিয়ে যান',
    'continueWithPhone': 'ফোন দিয়ে চালিয়ে যান',
    'email': 'ইমেইল',
    'password': 'পাসওয়ার্ড',
    'passwordConfirmation': 'পাসওয়ার্ড নিশ্চিত করুন',
    'name': 'পুরো নাম',
    'username': 'ইউজারনেম',
    'phone': 'ফোন',
    'gameUid': 'ফ্রি ফায়ার UID',
    'role': 'ভূমিকা',
    'rolePlayer': 'প্লেয়ার',
    'roleOrganizer': 'অর্গানাইজার',
    'forgotPassword': 'পাসওয়ার্ড ভুলে গেছেন?',
    'forgotPasswordBody':
        'পাসওয়ার্ড রিসেট ওয়েবে পাওয়া যায়। ব্রাউজারে এফএফ এরিনা খুলে "পাসওয়ার্ড ভুলে গেছেন" ব্যবহার করুন।',
    'forgotPasswordNote':
        'নিরাপত্তার জন্য আমরা অ্যাপে পাসওয়ার্ড রিসেট করি না।',
    'backToLogin': 'লগ ইন-এ ফিরে যান',
    'sendCode': 'কোড পাঠান',
    'verify': 'যাচাই করুন',
    'resendCode': 'কোড আবার পাঠান',
    'codeSentTo': 'আপনার ফোনে একটি যাচাই কোড পাঠানো হয়েছে।',
    'enterCode': 'কোডটি লিখুন',
    'home': 'হোম',
    'tournaments': 'টুর্নামেন্ট',
    'matches': 'ম্যাচ',
    'leaderboard': 'লিডারবোর্ড',
    'profile': 'প্রোফাইল',
    'wallet': 'ওয়ালেট',
    'notifications': 'বিজ্ঞপ্তি',
    'settings': 'সেটিংস',
    'support': 'সহায়তা',
    'myTeams': 'আমার দল',
    'live': 'লাইভ',
    'viewAll': 'সব দেখুন',
    'register': 'রেজিস্টার',
    'registered': 'রেজিস্টার্ড',
    'waitlisted': 'অপেক্ষমাণ',
    'checkIn': 'চেক-ইন',
    'checkedIn': 'চেক-ইন হয়েছে',
    'entryFee': 'এন্ট্রি ফি',
    'prizePool': 'পুরস্কার',
    'slotsLeft': 'আসন বাকি',
    'startsAt': 'শুরু',
    'checkInOpens': 'চেক-ইন শুরু',
    'checkInCloses': 'চেক-ইন শেষ',
    'teamSize': 'দলের সদস্য',
    'gameMode': 'মোড',
    'map': 'ম্যাপ',
    'format': 'ফরম্যাট',
    'status': 'স্ট্যাটাস',
    'viewBracket': 'ব্র্যাকেট',
    'viewMatches': 'ম্যাচ',
    'viewLeaderboard': 'স্ট্যান্ডিং',
    'teamIsWaitlisted': 'আপনার দল অপেক্ষমাণ তালিকায় আছে।',
    'waitlistPositionLabel': 'অপেক্ষমাণ অবস্থান',
    'search': 'খুঁজুন',
    'all': 'সব',
    'upcoming': 'আসন্ন',
    'finished': 'সমাপ্ত',
    'teamName': 'দলের নাম',
    'captainName': 'অধিনায়কের নাম',
    'members': 'সদস্য',
    'memberName': 'খেলোয়াড়ের নাম',
    'joinTeam': 'দলে যোগ দিন',
    'createTeam': 'দল তৈরি করুন',
    'addMember': 'সদস্য যোগ করুন',
    'removeMember': 'সরান',
    'withdraw': 'প্রত্যাহার',
    'confirmWithdraw': 'দল প্রত্যাহার করবেন?',
    'withdrawBody':
        'প্রত্যাহার করলে আপনার দল এই টুর্নামেন্ট থেকে বাদ যাবে। এন্ট্রি ফি রিফান্ড টুর্নামেন্টের রিফান্ড নীতি অনুযায়ী হবে।',
    'roster': 'রোস্টার',
    'noTeams': 'আপনি এখনো কোনো দলে নেই।',
    'matchNo': 'ম্যাচ',
    'round': 'রাউন্ড',
    'roomId': 'রুম আইডি',
    'roomPass': 'রুম পাসওয়ার্ড',
    'scheduled': 'নির্ধারিত',
    'completed': 'সমাপ্ত',
    'kills': 'কিল',
    'placement': 'প্লেসমেন্ট',
    'points': 'পয়েন্ট',
    'submitScore': 'স্কোর জমা দিন',
    'scoreSubmitted': 'স্কোর জমা হয়েছে',
    'noScoresYet': 'এখনো কোনো স্কোর জমা হয়নি।',
    'myUpcomingMatches': 'আমার আসন্ন ম্যাচ',
    'rank': 'র‍্যাঙ্ক',
    'team': 'দল',
    'standings': 'স্ট্যান্ডিং',
    'balance': 'ব্যালেন্স',
    'ledger': 'লেজার',
    'payouts': 'পেআউট',
    'payout': 'পেআউট',
    'amount': 'পরিমাণ',
    'provider': 'প্রোভাইডার',
    'redirectingToPayment': 'আপনার পেমেন্ট প্রোভাইডার খোলা হচ্ছে…',
    'paymentPending':
        'পেমেন্ট প্রক্রিয়াধীন। প্রোভাইডার রিপোর্ট দিলেই আমরা নিশ্চিত করব।',
    'paymentPaid': 'পেমেন্ট নিশ্চিত হয়েছে।',
    'paymentFailed': 'পেমেন্ট সম্পন্ন হয়নি।',
    'pollForStatus': 'পেমেন্ট স্ট্যাটাস যাচাই হচ্ছে…',
    'savedMethods': 'সংরক্ষিত মাধ্যম',
    'noSavedMethods': 'কোনো সংরক্ষিত পেমেন্ট মাধ্যম নেই।',
    'noNotifications': 'এখনো কোনো বিজ্ঞপ্তি নেই।',
    'markAllRead': 'সব পড়া হয়েছে',
    'unread': 'অপঠিত',
    'subject': 'বিষয়',
    'category': 'ক্যাটাগরি',
    'priority': 'অগ্রাধিকার',
    'message': 'বার্তা',
    'sendMessage': 'পাঠান',
    'createTicket': 'নতুন টিকিট',
    'ticketCreated': 'টিকিট তৈরি হয়েছে। আমরা শীঘ্রই উত্তর দেব।',
    'noTickets': 'কোনো সাপোর্ট টিকিট নেই।',
    'waitingForReply': 'উত্তরের অপেক্ষায়…',
    'dispute': 'বিরোধ',
    'disputeWebOnly':
        'বিরোধ ওয়েব ম্যাচ পেজ থেকে দায়ের করা হয়। এখানে আপনার দায়েরকৃত বিরোধ দেখতে পারবেন।',
    'noDisputes': 'কোনো বিরোধ নেই।',
    'accountSecurity': 'অ্যাকাউন্ট নিরাপত্তা',
    'securityStatus': 'নিরাপত্তা স্ট্যাটাস',
    'signInMethods': 'সাইন-ইন মাধ্যম',
    'emailVerified': 'ইমেইল যাচাইকৃত',
    'emailNotVerified': 'ইমেইল যাচাই হয়নি',
    'hasPassword': 'পাসওয়ার্ড সেট আছে',
    'noPassword': 'পাসওয়ার্ড নেই',
    'accountStatus': 'অ্যাকাউন্ট স্ট্যাটাস',
    'active': 'সক্রিয়',
    'sessions': 'সক্রিয় সেশন',
    'revoke': 'প্রত্যাহার',
    'revokeAll': 'সব জায়গা থেকে লগ আউট',
    'revokeOthers': 'অন্য ডিভাইস লগ আউট',
    'currentSession': 'এই ডিভাইস',
    'thisDevice': 'এই ডিভাইস',
    'lastActive': 'সর্বশেষ সক্রিয়',
    'privacy': 'প্রাইভেসি',
    'privacyPublic': 'পাবলিক — যে কেউ প্রোফাইল দেখতে পারবে',
    'privacyRegistered': 'শুধু রেজিস্টার্ড ব্যবহারকারী',
    'privacyPrivate': 'প্রাইভেট — সবার থেকে লুকানো',
    'publicProfile': 'পাবলিক প্রোফাইল',
    'editProfile': 'প্রোফাইল সম্পাদনা',
    'bio': 'বায়ো',
    'country': 'দেশ',
    'region': 'অঞ্চল',
    'avatar': 'অ্যাভাটার URL',
    'save': 'সংরক্ষণ',
    'saved': 'সংরক্ষিত',
    'cancel': 'বাতিল',
    'confirm': 'নিশ্চিত',
    'retry': 'আবার চেষ্টা করুন',
    'loading': 'লোড হচ্ছে…',
    'empty': 'এখানে কিছু নেই।',
    'version': 'সংস্করণ',
    'language': 'ভাষা',
    'english': 'English',
    'bangla': 'বাংলা',
    'pushStatus': 'পুশ বিজ্ঞপ্তি',
    'pushDisabled': 'এই বিল্ডে পুশ বিজ্ঞপ্তি কনফিগার করা হয়নি।',
    'pushEnabled': 'পুশ বিজ্ঞপ্তি চালু আছে।',
    'notifPreferences': 'বিজ্ঞপ্তি পছন্দসমূহ',
    'pushPrefsIntro':
        'কোন পুশ বিজ্ঞপ্তি পাবেন তা বেছে নিন। ইন-অ্যাপ ও ইমেইল বিজ্ঞপ্তি অপরিবর্তিত থাকবে।',
    'prefTournament': 'টুর্নামেন্ট',
    'prefMatch': 'ম্যাচ',
    'prefTeam': 'দল',
    'prefPayment': 'পেমেন্ট',
    'prefPayout': 'পেআউট',
    'prefDispute': 'বিতর্ক',
    'prefSecurity': 'নিরাপত্তা সতর্কতা',
    'prefSupport': 'সাপোর্ট',
    'prefSecurityLocked':
        'নিরাপত্তা সতর্কতা সবসময় পাঠানো হয়, বন্ধ করা যায় না।',
    'devices': 'ডিভাইস',
    'noDevices': 'কোনো ডিভাইস নিবন্ধিত নেই।',
    'deviceRevoked': 'ডিভাইস সরানো হয়েছে।',
    'removeDevice': 'ডিভাইস সরান',
    'deviceInactive': 'নিষ্ক্রিয়',
    'maintenanceTitle': 'রক্ষণাবেক্ষণ চলছে',
    'updateRequiredTitle': 'আপডেট প্রয়োজন',
    'updateRequiredBody':
        'এই সংস্করণটি আর সমর্থিত নয়। চালিয়ে যেতে অ্যাপটি আপডেট করুন।',
    'updateAvailableTitle': 'আপডেট উপলব্ধ',
    'updateAvailableBody':
        'নতুন সংস্করণ পাওয়া যাচ্ছে। আপনি এই সংস্করণটি ব্যবহার চালিয়ে যেতে পারেন।',
    'openStore': 'আপডেট',
    'checkAgain': 'আবার দেখুন',
    'enableInSettings': 'সেটিংসে চালু করুন',
    'permissionDeniedHint':
        'বিজ্ঞপ্তি বন্ধ আছে। সিস্টেম সেটিংস থেকে চালু করতে পারেন।',
    'about': 'সম্পর্কে',
    'terms': 'ব্যবহারের শর্তাবলী',
    'privacyPolicy': 'গোপনীয়তা নীতি',
    'contactSupport': 'সাপোর্টে যোগাযোগ',
    'connectedAccounts': 'সংযুক্ত অ্যাকাউন্ট',
    'google': 'গুগল',
    'phoneMethod': 'ফোন',
    'emailMethod': 'ইমেইল',
    'linkPhone': 'ফোন নম্বর যুক্ত করুন',
    'phoneLinked': 'ফোন নম্বর যুক্ত হয়েছে।',
    'phoneLinkBody':
        'একটি ফোন নম্বর যুক্ত করুন যাতে এটি দিয়ে লগ ইন করতে পারেন।',
    'verifyPhone': 'ফোন যাচাই করুন',
    'changePasswordWebOnly': 'পাসওয়ার্ড পরিবর্তন',
    'changePasswordWebBody':
        'পাসওয়ার্ড পরিবর্তন ওয়েবে করা যায়। পাসওয়ার্ড বদলাতে বা রিসেট করতে ব্রাউজারে এফএফ এরিনা খুলুন।',
    'deactivationWebOnly': 'অ্যাকাউন্ট লাইফসাইকেল',
    'deactivationWebBody':
        'নিরাপত্তার জন্য অ্যাকাউন্ট নিষ্ক্রিয় ও মুছে ফেলার অনুরোধ ওয়েবে করা হয়।',
    'securityEventTitle': 'সেশন শেষ',
    'sessionExpiredBody': 'আপনার সেশন মেয়াদোত্তীর্ণ। আবার লগ ইন করুন।',
    'tokenRevokedBody': 'এই ডিভাইসে আপনাকে সাইন আউট করা হয়েছে।',
    'accountInactiveTitle': 'অ্যাকাউন্ট অনুপলব্ধ',
    'accountInactiveBody':
        'এই অ্যাকাউন্টটি নিষ্ক্রিয় করা হয়েছে। ভুল হলে সাপোর্টে যোগাযোগ করুন।',
    'loginAgain': 'আবার লগ ইন করুন',
    'ok': 'ঠিক আছে',
    'offlineBanner': 'আপনি অফলাইনে আছেন — সংরক্ষিত ডেটা দেখানো হচ্ছে।',
    'staleBanner': 'সংরক্ষিত ডেটা দেখানো হচ্ছে।',
    'errorOffline': 'আপনি অফলাইনে আছেন। সংযোগ পরীক্ষা করুন।',
    'errorTimeout': 'সার্ভার সাড়া দিতে দেরি করছে।',
    'errorRateLimited': 'অনেকবার চেষ্টা হয়েছে। একটু অপেক্ষা করুন।',
    'errorInvalidCredentials': 'ইমেইল বা পাসওয়ার্ড সঠিক নয়।',
    'errorInvalidCode': 'কোডটি সঠিক নয়।',
    'errorNoAccount': 'এই ফোন নম্বরে কোনো অ্যাকাউন্ট নেই।',
    'errorGoogleSignIn': 'গুগল সাইন-ইন সম্পন্ন হয়নি। আবার চেষ্টা করুন।',
    'errorAccountInactive': 'এই অ্যাকাউন্টটি নিষ্ক্রিয় করা হয়েছে।',
    'errorSessionExpired': 'সেশন মেয়াদোত্তীর্ণ। আবার লগ ইন করুন।',
    'errorServer': 'সার্ভারে সমস্যা হয়েছে। আবার চেষ্টা করুন।',
    'errorGeneric': 'কিছু ভুল হয়েছে। আবার চেষ্টা করুন।',
    'errorUnexpected': 'একটি অপ্রত্যাশিত ত্রুটি ঘটেছে।',
  };
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  bool isSupported(Locale locale) =>
      locale.languageCode == 'en' || locale.languageCode == 'bn';

  // Synchronous load so `AppLocalizations.of(context)` resolves on the very
  // first build (no null flash, and tests don't need a settle pass).
  @override
  Future<AppLocalizations> load(Locale locale) =>
      SynchronousFuture(AppLocalizations(locale));

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}
