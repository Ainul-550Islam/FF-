<?php

/**
 * G3 — coverage regression gate.
 *
 * Compares the freshly generated Clover report against a stored baseline
 * (storage/coverage/baseline.json) and fails when coverage has dropped by
 * more than the configured tolerance.
 *
 * The tolerance exists because coverage is measured on real test runs and
 * tiny single-file fluctuations (a new class, a moved line) must not flake
 * every PR. A material drop — more than the tolerance — still fails CI.
 *
 * Env:
 *   COVERAGE_REGRESSION_TOLERANCE_PCT  allowed drop in percentage POINTS
 *                                      (default 2.0)
 *   COVERAGE_BASELINE                  baseline JSON path (default
 *                                      storage/coverage/baseline.json)
 *
 * Exit codes:
 *   0 — no regression (or no baseline yet: first run records it and passes)
 *   1 — clover report missing
 *   2 — global coverage dropped below baseline - tolerance
 *   3 — a critical domain dropped below baseline - tolerance
 */

declare(strict_types=1);

$cloverPath = $argv[1] ?? 'storage/coverage/clover.xml';
$baselinePath = getenv('COVERAGE_BASELINE') ?: 'storage/coverage/baseline.json';
$tolerance = (float) (getenv('COVERAGE_REGRESSION_TOLERANCE_PCT') ?: 2.0);

if (! is_file($cloverPath)) {
    fwrite(STDERR, "ERROR: clover report not found at {$cloverPath}.\n");
    exit(1);
}

$xml = @simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, "ERROR: could not parse {$cloverPath}.\n");
    exit(1);
}

/**
 * Compute per-domain and global line coverage from the Clover report.
 */
function computeCoverage(SimpleXMLElement $xml): array
{
    $domainRules = [
        'payments' => ['/Services/Payment', '/Gateways/', '/Models/Payment', '/Models/Refund', '/Support/Money.php', '/Support/PaymentCallbackState.php', '/Support/GatewayHttp.php', '/Http/Controllers/CheckoutController', '/Http/Controllers/WebhookController', '/Http/Controllers/PaymentGatewayCallbackController'],
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

    $domains = [];
    $totalElements = 0;
    $totalCovered = 0;

    foreach ($xml->xpath('//file') as $file) {
        $path = (string) $file['name'];
        $metrics = $file->xpath('./metrics');

        if ($metrics === []) {
            continue;
        }

        $statements = $metrics[0]['statements'] ?? null;

        if ($statements === null) {
            continue;
        }

        // `statements` / `coveredstatements` are the executable-line counts
        // (PCOV reports line coverage only).
        $elements = (int) $statements;
        $covered = (int) ($metrics[0]['coveredstatements'] ?? 0);

        $totalElements += $elements;
        $totalCovered += $covered;

        // First-match-wins, identical to coverage-summary.php, so a file is
        // attributed to exactly one domain.
        foreach ($domainRules as $domain => $patterns) {
            $matched = false;

            foreach ($patterns as $pattern) {
                if (str_contains($path, $pattern)) {
                    $domains[$domain] ??= ['elements' => 0, 'covered' => 0];
                    $domains[$domain]['elements'] += $elements;
                    $domains[$domain]['covered'] += $covered;
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                break;
            }
        }
    }

    $result = [
        'global' => $totalElements > 0 ? round(($totalCovered / $totalElements) * 100, 2) : 0.0,
        'domains' => [],
    ];

    foreach ($domains as $domain => $d) {
        $result['domains'][$domain] = $d['elements'] > 0 ? round(($d['covered'] / $d['elements']) * 100, 2) : 0.0;
    }

    ksort($result['domains']);

    return $result;
}

$current = computeCoverage($xml);

// First run with no baseline: record it and pass.
if (! is_file($baselinePath)) {
    $dir = dirname($baselinePath);

    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($baselinePath, json_encode([
        'recorded_at' => gmdate('c'),
        'global_line' => $current['global'],
        'domains' => $current['domains'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    echo "No baseline found — recorded a new one at {$baselinePath} (global {$current['global']}%).\n";
    echo "OK: no regression (first run).\n";
    exit(0);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true);

if (! is_array($baseline)) {
    fwrite(STDERR, "ERROR: baseline {$baselinePath} is not valid JSON.\n");
    exit(1);
}

$baselineGlobal = (float) ($baseline['global_line'] ?? 0);
$baselineDomains = (array) ($baseline['domains'] ?? []);

$failed = false;

echo "==============================\n";
echo " G3 coverage regression\n";
echo "==============================\n";
printf(" Global: %6.2f%% (baseline %6.2f%%, tolerance %.2f pts)\n", $current['global'], $baselineGlobal, $tolerance);

$floor = $baselineGlobal - $tolerance;

if ($current['global'] < $floor) {
    printf("FAIL: global coverage %.2f%% dropped below floor %.2f%%.\n", $current['global'], $floor);
    $failed = true;
}

echo "\nDomain            current  baseline  floor\n";

foreach ($current['domains'] as $domain => $pct) {
    $base = (float) ($baselineDomains[$domain] ?? 0);
    $domainFloor = $base - $tolerance;

    printf("  %-16s %6.2f%%  %6.2f%%  %6.2f%%\n", $domain, $pct, $base, $domainFloor);

    if ($base > 0 && $pct < $domainFloor) {
        printf("  ^ FAIL: %s regressed below its floor.\n", $domain);
        $failed = true;
    }
}

exit($failed ? 2 : 0);
