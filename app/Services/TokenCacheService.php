<?php
namespace App\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class TokenCacheService
{
    protected string $prefix = 'payment_token_';

    public function get(string $key): ?string
    {
        $cacheKey = $this->prefix . $key;
        
        // Try Redis first if available, then file cache
        $encrypted = Cache::get($cacheKey);
        if (!$encrypted) {
            return null;
        }
        
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Exception $e) {
            Cache::forget($cacheKey);
            return null;
        }
    }

    public function set(string $key, string $token, int $ttlMinutes = 50): void
    {
        $cacheKey = $this->prefix . $key;
        $encrypted = Crypt::encryptString($token);
        // TTL shorter than expiration for safety (5 min buffer)
        Cache::put($cacheKey, $encrypted, now()->addMinutes($ttlMinutes));
    }

    public function forget(string $key): void
    {
        Cache::forget($this->prefix . $key);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}
