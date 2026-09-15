<?php

/**
 * G3 — coverage summary + threshold gate.
 *
 * Parses the PHPUnit Clover report (storage/coverage/clover.xml) and prints:
 *
 *   1. global line coverage,
 *   2. per-domain coverage for the critical domains (payments, wallet/ledger,
 *      payouts, security/anti-fraud, auth, registration, scoring, disputes,
 *      API, audit) so a weak critical area is visible even when the global
 *      number looks fine,
 *   3. a hard pass/fail against the configured minimum global line coverage.
 *
 * Exit codes:
 *   0 — coverage met or above threshold (or --no-fail)
 *   1 — clover report missing
 *   2 — global coverage below threshold
 *   3 — a critical domain regressed below its floor
 *
 * Thresholds come from environment variables (see scripts/ci/coverage.sh for
 * defaults):
 *   COVERAGE_MIN_LINE         global minimum line coverage (percent)
 *   COVERAGE_MIN_CRITICAL     minimum line coverage per critical domain
 *
 * NOTE: PCOV reports LINE coverage only — branch/function coverage is not
 * available from the PCOV driver and is therefore reported as "n/a" rather
 * than invented.
 */

declare(strict_types=1);

$cloverPath = $argv[1] ?? 'storage/coverage/clover.xml';
$noFail = in_array('--no-fail', $argv, true);
$minLine = (float) (getenv('COVERAGE_MIN_LINE') ?: 0);
$minCritical = (float) (getenv('COVERAGE_MIN_CRITICAL') ?: 0);

if (! is_file($cloverPath)) {
    fwrite(STDERR, "ERROR: clover report not found at {$cloverPath}. Run coverage first.\n");
    exit(1);
}

$xml = @simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, "ERROR: could not parse {$cloverPath}.\n");
    exit(1);
}

/**
 * Map a file path to a critical domain (first match wins).
 */
function domainFor(string $path): ?string
{
    $rules = [
        'payments' => ['/Services/Payment', '/Gateways/', '/Models/Payment', '/Models/Refund', '/Http/Controllers/CheckoutController', '/Http/Controllers/WebhookController', '/Http/Controllers/PaymentGatewayCallbackController', '/Support/Money.php', '/Support/PaymentCallbackState.php', '/Support/GatewayHttp.php'],
        'wallet-ledger' => ['/Services/WalletService', '/Models/Wallet.php', '/Models/LedgerEntry.php'],
        'payouts' => ['/Services/Payout', '/Models/Payout', '/Models/FinancialSettlement', '/Models/Prize', '/Models/Settlement'],
        'security-anti-fraud' => ['/Services/FraudRiskService', '/Services/RestrictionService', '/Services/IpIntelligenceService', '/Services/DeviceFingerprintService', '/Services/IdentityVerificationService', '/Models/Restriction', '/Models/Risk'],
        'auth' => ['/Http/Controllers/AuthController', '/Http/Controllers/Api/V1/AuthController', '/Http/Controllers/AccountSecurityController', '/Services/PhoneOtpService', '/Services/GoogleAuthService', '/Services/LoginEventService', '/Services/SessionManagementService', '/Models/OtpChallenge', '/Models/LoginEvent'],
        'registration' => ['/Services/RegistrationService', '/Services/RosterService', '/Services/TournamentParticipationService', '/Models/TeamMember'],
        'scoring' => ['/Services/ScoringService', '/Models/Score', '/Models/ScoringRule'],
        'disputes' => ['/Services/DisputeService', '/Models/Dispute'],
        'api' => ['/Http/Controllers/Api/', '/Http/Resources/Api/', '/Support/ApiResponse.php', '/Services/ApiTokenService', '/Services/ApiClientService', '/Services/IdempotencyService', '/Http/Middleware/EnsureIdempotency', '/Models/Api'],
        'audit' => ['/Services/AuditLogService', '/Models/AuditLog.php'],
        'reconciliation' => ['/Services/ReconciliationService', '/Models/Webhook', '/Services/Webhook'],
        'notifications' => ['/Services/NotificationService', '/Services/LiveEventService', '/Models/Notification'],
    ];

    foreach ($rules as $domain => $patterns) {
        foreach ($patterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return $domain;
            }
        }
    }

    return null;
}

$totals = ['statements' => 0, 'covered' => 0];
$domains = [];

/** @var SimpleXMLElement $file */
foreach ($xml->xpath('//file') as $file) {
    $path = (string) $file['name'];
    $metrics = $file->xpath('./metrics');

    if ($metrics === []) {
        continue;
    }

    // File-level <metrics> attributes. `statements` / `coveredstatements` are
    // the executable-line counts — PCOV reports line coverage only, so this
    // is exactly what we sum.
    $statements = (int) ($metrics[0]['statements'] ?? 0);
    $covered = (int) ($metrics[0]['coveredstatements'] ?? 0);

    $totals['statements'] += $statements;
    $totals['covered'] += $covered;

    $domain = domainFor($path);

    if ($domain === null) {
        continue;
    }

    $domains[$domain] ??= ['elements' => 0, 'covered' => 0];
    $domains[$domain]['elements'] += $statements;
    $domains[$domain]['covered'] += $covered;
}

$global = $totals['statements'] > 0
    ? round(($totals['covered'] / $totals['statements']) * 100, 2)
    : 0.0;

echo "==============================\n";
echo " G3 coverage summary\n";
echo "==============================\n";
printf(" Line coverage : %6.2f%%  (%d / %d statements)\n", $global, $totals['covered'], $totals['statements']);
echo " Branch        : n/a (PCOV driver reports line coverage only)\n";
echo " Functions     : n/a (PCOV driver reports line coverage only)\n";
echo "\nCritical domains:\n";

ksort($domains);

foreach ($domains as $domain => $d) {
    $pct = $d['elements'] > 0 ? round(($d['covered'] / $d['elements']) * 100, 2) : 0.0;
    printf("  %-22s %6.2f%%  (%d / %d)\n", $domain, $pct, $d['covered'], $d['elements']);
}

echo "\nThresholds:\n";
printf("  global minimum  : %.2f%%\n", $minLine);
printf("  critical minimum: %.2f%%\n", $minCritical);

$failed = false;

if (! $noFail && $minLine > 0 && $global < $minLine) {
    printf("\nFAIL: global line coverage %.2f%% is below the %.2f%% threshold.\n", $global, $minLine);
    $failed = true;
}

if (! $noFail && $minCritical > 0) {
    foreach ($domains as $domain => $d) {
        $pct = $d['elements'] > 0 ? ($d['covered'] / $d['elements']) * 100 : 0.0;

        if ($pct < $minCritical) {
            printf("FAIL: %s coverage %.2f%% is below the %.2f%% critical threshold.\n", $domain, $pct, $minCritical);
            $failed = true;
        }
    }
}

if ($failed) {
    exit(2);
}

if (! $noFail) {
    echo "\nOK: coverage thresholds satisfied.\n";
}

exit(0);
