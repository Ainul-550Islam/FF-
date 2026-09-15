<?php

namespace App\Services\Push;

/**
 * Phase 19 — JWT helpers shared by the FCM (RS256) and APNs (ES256)
 * transports. Pure, dependency-free signing utilities so both transports
 * build their provider tokens identically.
 */
final class PushJwt
{
    /**
     * URL-safe base64 without padding (JWT segment encoding).
     */
    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Encode a JSON payload as a JWT segment.
     */
    public static function encodeSegment(array $payload): string
    {
        return self::base64Url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * RS256 signature (Google service accounts). Returns the base64url
     * signature, or '' when the key cannot sign.
     */
    public static function signRs256(string $unsigned, string $privateKey): string
    {
        $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            return '';
        }

        return self::base64Url($signature);
    }

    /**
     * ES256 signature (Apple .p8 keys). `openssl_sign` with an EC key emits a
     * DER-encoded ECDSA signature, but JWT requires the raw R||S (64-byte)
     * form, so the DER structure is parsed and flattened. Returns '' when the
     * key is not a usable P-256 private key.
     */
    public static function signEs256(string $unsigned, string $privateKey): string
    {
        $ok = openssl_sign($unsigned, $der, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            return '';
        }

        $raw = self::derToRaw($der);

        return $raw === null ? '' : self::base64Url($raw);
    }

    /**
     * Minimal ASN.1 parse of `SEQUENCE { INTEGER r, INTEGER s }` into the
     * 64-byte raw R||S form expected by JWT ES256.
     */
    private static function derToRaw(string $der): ?string
    {
        $len = strlen($der);
        $offset = 0;

        if ($offset >= $len || ord($der[$offset++]) !== 0x30) {
            return null; // not a SEQUENCE
        }
        if ($offset >= $len) {
            return null;
        }

        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $numBytes = $seqLen & 0x7F;
            if ($numBytes > 2) {
                return null; // defensive — signatures are short
            }
            $seqLen = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                if ($offset >= $len) {
                    return null;
                }
                $seqLen = ($seqLen << 8) | ord($der[$offset++]);
            }
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if ($offset >= $len || ord($der[$offset++]) !== 0x02) {
                return null; // not an INTEGER
            }
            if ($offset >= $len) {
                return null;
            }
            $intLen = ord($der[$offset++]);
            if ($offset + $intLen > $len) {
                return null;
            }
            $value = substr($der, $offset, $intLen);
            $offset += $intLen;

            // Strip a leading zero sign byte (ASN.1 positive INTEGER).
            if ($intLen > 0 && ord($value[0]) === 0x00) {
                $value = substr($value, 1);
            }

            // Left-pad each coordinate to exactly 32 bytes.
            $parts[] = str_pad($value, 32, "\x00", STR_PAD_LEFT);
        }

        return $parts[0].$parts[1];
    }
}
