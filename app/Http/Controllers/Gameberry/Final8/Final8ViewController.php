<?php

namespace App\Http\Controllers\Gameberry\Final8;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Final8 (Part 17) Production 1100+ part.
 *
 * The numbered part registers one route per feature (1101-1150) so no generated
 * Blade view is left unreachable, while the pre-existing controllers keep their
 * original behaviour untouched (additive only).
 */
class Final8ViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.final8.feature_';
    protected int $low = 1101;
    protected int $high = 1150;

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 1101-1150 only - no file omitted');
        }

        return view($this->viewPrefix . $feature, ['feature' => $feature]);
    }
}
