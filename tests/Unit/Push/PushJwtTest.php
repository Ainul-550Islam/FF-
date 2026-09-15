<?php

namespace Tests\Unit\Push;

use App\Services\Push\PushJwt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushJwtTest extends TestCase
{
    #[Test]
    public function base64url_is_url_safe_and_unpadded(): void
    {
        $encoded = PushJwt::base64Url("abc\xff\xfe\xfd");

        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    #[Test]
    public function encode_segment_produces_valid_json_segment(): void
    {
        $segment = PushJwt::encodeSegment(['alg' => 'RS256', 'typ' => 'JWT']);

        $decoded = json_decode(base64_decode(strtr($segment, '-_', '+/')), true);

        $this->assertSame('RS256', $decoded['alg']);
    }

    #[Test]
    public function rs256_signs_with_a_service_account_key(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $pem = '';
        openssl_pkey_export($key, $pem);

        $signature = PushJwt::signRs256('header.claims', $pem);

        $this->assertNotSame('', $signature);

        // The signature must verify against the unsigned input (using the
        // public half of the key).
        $public = openssl_pkey_get_details($key)['key'];
        $raw = base64_decode(strtr($signature, '-_', '+/'));
        $ok = openssl_verify('header.claims', $raw, $public, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok);
    }

    #[Test]
    public function es256_produces_a_64_byte_raw_signature(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);

        $pem = '';
        openssl_pkey_export($key, $pem);

        $signature = PushJwt::signEs256('header.claims', $pem);

        // JWT ES256 is the base64url of raw R||S (64 bytes → 86 chars).
        $this->assertNotSame('', $signature);
        $this->assertSame(86, strlen($signature));
    }

    #[Test]
    public function es256_returns_empty_for_a_non_ec_key(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $pem = '';
        openssl_pkey_export($key, $pem);

        // RSA keys cannot produce an ES256 signature; the helper must report
        // failure rather than emit a corrupt token.
        $signature = PushJwt::signEs256('header.claims', $pem);

        $this->assertSame('', $signature);
    }
}
