<?php

namespace App\Http\Controllers\Gameberry\Final7;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Final7 (Part 16) Production 1000+ part.
 *
 * The numbered part registers one route per feature (1001-1050) so no generated
 * Blade view is left unreachable, while the pre-existing controllers keep their
 * original behaviour untouched (additive only).
 */
class Final7ViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.final7.feature_';

    protected int $low = 1001;

    protected int $high = 1050;

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 1001-1050 only - no file omitted');
        }

        return view($this->viewPrefix.$feature, ['feature' => $feature]);
    }
}
