<?php

namespace App\Services;

use App\Models\IpIntel;
use App\Models\IpLink;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-aware IP intelligence (Phase 10).
 *
 * Raw IP addresses are never persisted: observations store an HMAC of the
 * address and an HMAC of its subnet (for grouping). Shared networks (NAT,
 * carriers, cafés, VPNs) are tolerated — an IP becomes a weak signal only
 * above a high threshold, and is never treated as proof of abuse.
 */
class IpIntelligenceService
{
    public function __construct(
        protected FraudRiskService $risk,
    ) {}

    /**
     * Pseudonymous hash of an IP address.
     */
    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', trim($ip), (string) config('app.key'));
    }

    /**
     * Pseudonymous hash of an IP's grouping subnet (/24 for IPv4, first four
     * groups for IPv6).
     */
    public function subnetHash(string $ip): string
    {
        $subnet = $this->subnetOf($ip);

        return hash_hmac('sha256', $subnet, (string) config('app.key'));
    }

    /**
     * Record a user's IP observation (login/registration). Never throws.
     */
    public function observe(Request $request, User $user): IpIntel
    {
        $ip = (string) $request->ip();

        if ($ip === '') {
            $ip = '0.0.0.0';
        }

        $ipHash = $this->ipHash($ip);
        $subnetHash = $this->subnetHash($ip);

        return DB::transaction(function () use ($ipHash, $subnetHash, $user) {
            $intel = IpIntel::where('ip_hash', $ipHash)->lockForUpdate()->first();

            if ($intel === null) {
                $intel = new IpIntel();
                $intel->ip_hash = $ipHash;
                $intel->subnet_hash = $subnetHash;
                $intel->observation_count = 1;
                $intel->suspicious_count = 0;
                $intel->first_seen_at = now();
                $intel->last_seen_at = now();
                $intel->save();
            } else {
                $intel->observation_count = $intel->observation_count + 1;
                $intel->last_seen_at = now();
                $intel->save();
            }

            $link = IpLink::where('ip_intel_id', $intel->id)
                ->where('user_id', $user->id)
                ->first();

            if ($link === null) {
                $link = new IpLink();
                $link->ip_intel_id = $intel->id;
                $link->user_id = $user->id;
                $link->first_seen_at = now();
                $link->last_seen_at = now();
                $link->save();
            } else {
                $link->last_seen_at = now();
                $link->save();
            }

            // Shared-network signal — only above a high tolerance, and low
            // severity (an IP is never proof).
            $distinct = IpLink::where('ip_intel_id', $intel->id)->count();
            $maxShared = (int) config('antifraud.ip.max_accounts_shared', 20);

            if ($distinct > $maxShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_IP_SHARED, RiskEvent::SEVERITY_LOW, 'ip', [
                    'ip_intel_id' => $intel->id,
                    'account_count' => $distinct,
                ]);
            }

            return $intel;
        });
    }

    /**
     * Intel record for a raw IP (admin use only).
     */
    public function intelFor(string $ip): ?IpIntel
    {
        return IpIntel::where('ip_hash', $this->ipHash($ip))->first();
    }

    /**
     * Grouping subnet for an IP address.
     */
    protected function subnetOf(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $groups = explode(':', $ip);

            return implode(':', array_slice($groups, 0, 4));
        }

        $octets = explode('.', $ip);

        return implode('.', array_slice($octets, 0, 3));
    }
}
