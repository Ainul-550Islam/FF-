<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves user avatars from the local disk (uploads) or renders a generated
 * initials fallback.
 *
 * AUDIT FIX (2026-10-08, GAPS-10) — two hardening layers on the read side:
 *
 *  1. UPLOAD WHITELIST. `response()->file()` trusts Symfony's mime guesser,
 *     which keys off the FILE EXTENSION — anything ever stored with a `.html`,
 *     `.shtm`, `.svg` or double-dot suffix was served as scriptable content
 *     from the app origin. Uploads now can only be jpg/jpeg/png/webp (see
 *     ProfileController), but pre-existing rows are defended too: a stored
 *     path whose extension is not whitelisted is simply NOT served — the
 *     bytes are never written to the response at all.
 *
 *  2. EXPLICIT, LOCKED HEADERS. Content-Type is set from the whitelist (never
 *     guessed), with `X-Content-Type-Options: nosniff` so a browser cannot
 *     re-sniff a mislabelled body, `Content-Disposition: inline` and a CSP of
 *     `default-src 'none'` on the image document itself.
 *
 *  3. The generated initials SVG escapes the display name — a name containing
 *     markup used to be injected raw into an image/svg+xml response.
 */
class AvatarController extends Controller
{
    /**
     * Extensions that may be served from disk, mapped to their fixed media
     * type. Anything else falls back to the generated avatar.
     *
     * @var array<string, string>
     */
    protected const SERVEABLE = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function show(User $user): BinaryFileResponse|Response
    {
        $path = $user->avatar_path;

        if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (isset(self::SERVEABLE[$extension])) {
                return response()->file(Storage::disk('local')->path($path), [
                    'Content-Type' => self::SERVEABLE[$extension],
                    'X-Content-Type-Options' => 'nosniff',
                    'Content-Disposition' => 'inline; filename="avatar.'.$extension.'"',
                    'Content-Security-Policy' => "default-src 'none'",
                    'Cache-Control' => 'private, max-age=3600',
                ]);
            }

            // Legacy/foreign upload shape: refuse to serve the bytes.
            logger()->warning('Avatar path has a non-whitelisted extension; refusing to serve.', [
                'user_id' => $user->id,
                'extension' => $extension,
            ]);
        }

        return $this->defaultAvatar($user);
    }

    public function thumbnail(User $user): BinaryFileResponse|Response
    {
        return $this->show($user);
    }

    protected function defaultAvatar(User $user): Response
    {
        // Display names are user data — they must never break out of the SVG
        // text node.
        $initials = htmlspecialchars(
            mb_strtoupper(mb_substr(trim($user->name), 0, 2)),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
    <rect width="100" height="100" fill="#1e293b"/>
    <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="#38bdf8" font-size="36" font-family="sans-serif" font-weight="bold">{$initials}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
