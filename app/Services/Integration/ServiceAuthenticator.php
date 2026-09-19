<?php
namespace App\Services\Integration;
class ServiceAuthenticator
{
    public static function signRequest(string $secret, string $method, string $path, string $body, int $timestamp, string $nonce): string
    {
        $message=sprintf('%s:%s:%s:%d:%s',$method,$path,$body,$timestamp,$nonce);
        return hash_hmac('sha256',$message,$secret);
    }
    public static function verifyRequest(string $secret, string $method, string $path, string $body, string $signature, int $timestamp, string $nonce, int $tolerance=300): bool
    {
        $now=time(); if(abs($now-$timestamp)>$tolerance)return false;
        $expected=self::signRequest($secret,$method,$path,$body,$timestamp,$nonce);
        return hash_equals($expected,$signature);
    }
    public static function generateTimestamp(): int{return time();}
    public static function generateNonce(): string{return \Illuminate\Support\Str::uuid()->toString();}
    public static function generateHeaders(string $secret, string $method, string $path, string $body): array
    {
        $timestamp=self::generateTimestamp(); $nonce=self::generateNonce(); $signature=self::signRequest($secret,$method,$path,$body,$timestamp,$nonce);
        return ['X-Timestamp'=>$timestamp,'X-Nonce'=>$nonce,'X-Signature'=>$signature,'X-Service-ID'=>config('services_go_rust.service_auth.service_id','ffarena-laravel')];
    }
}
