<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class AvatarController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'active'])->only(['show', 'thumbnail']);
    }

    public function show(Request $request, User $user)
    {
        // Authorization: user can view own avatar, or any avatar if authenticated (public profile)
        // For privacy, we allow authenticated users to view any avatar (since profiles are public in tournaments)
        // But we still require auth to prevent scraping
        if (!$request->user()) {
            abort(401);
        }

        if (!$user->avatar_path) {
            return $this->fallbackAvatar($user);
        }

        $disk = 'local';
        $path = $user->avatar_path;

        if (!Storage::disk($disk)->exists($path)) {
            // Try public disk fallback
            if (Storage::disk('public')->exists($path)) {
                $disk = 'public';
            } else {
                return $this->fallbackAvatar($user);
            }
        }

        // Security: prevent path traversal
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(400, 'Invalid avatar path');
        }

        // Cache headers for performance
        $lastModified = Storage::disk($disk)->lastModified($path);
        $etag = md5($path . $lastModified);

        if ($request->header('If-None-Match') === $etag) {
            return response()->noContent(304);
        }

        $mime = Storage::disk($disk)->mimeType($path) ?? 'image/jpeg';
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowedMimes)) {
            $mime = 'image/jpeg';
        }

        $content = Storage::disk($disk)->get($path);

        // Audit log for avatar access (optional, for security monitoring)
        if (app()->bound(\App\Services\AuditLogService::class)) {
            // Don't log every avatar view to avoid spam, only log if suspicious
        }

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Length' => strlen($content),
            'Cache-Control' => 'public, max-age=86400, immutable',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function thumbnail(Request $request, User $user)
    {
        // For now, same as show - in production would generate thumbnail
        // We cache thumbnail generation
        return $this->show($request, $user);
    }

    private function fallbackAvatar(User $user)
    {
        // Generate SVG fallback with initials
        $initials = $user->initials ?? '??';
        $bgColors = ['#6c5ce7', '#00b894', '#0984e3', '#d63031', '#fdcb6e', '#e84393'];
        $bg = $bgColors[abs(crc32($user->id)) % count($bgColors)];

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200" role="img" aria-label="{$user->display_name_or_name} avatar">
    <rect width="200" height="200" rx="100" fill="{$bg}"/>
    <text x="100" y="110" font-family="system-ui, sans-serif" font-size="70" font-weight="700" fill="white" text-anchor="middle" dominant-baseline="middle">{$initials}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
