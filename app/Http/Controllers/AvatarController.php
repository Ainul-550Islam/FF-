<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AvatarController extends Controller
{
    public function show(User $user): BinaryFileResponse|Response
    {
        if ($user->avatar_path && Storage::disk('local')->exists($user->avatar_path)) {
            return response()->file(Storage::disk('local')->path($user->avatar_path));
        }

        return $this->defaultAvatar($user);
    }

    public function thumbnail(User $user): BinaryFileResponse|Response
    {
        return $this->show($user);
    }

    protected function defaultAvatar(User $user): Response
    {
        $initials = strtoupper(mb_substr($user->name, 0, 2));
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
    <rect width="100" height="100" fill="#1e293b"/>
    <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="#38bdf8" font-size="36" font-family="sans-serif" font-weight="bold">{$initials}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
