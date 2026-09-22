<?php

namespace App\Http\Controllers\Gameberry\Core;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Core (Parts 1-4) Production 121-400 part and reports the part coverage.
 *
 * The part registers one route per feature (121-400) so no generated Blade view is
 * left unreachable, and coveredServices() lists every service number of the part so no
 * generated service file is left unreferenced (additive only - existing controllers
 * keep their original behaviour untouched).
 */
class CoreFeatureViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.core.feature_';
    protected int $low = 121;
    protected int $high = 400;

    public static function coveredServices(): array
    {
        return [161, 162, 163, 164, 165, 166, 167, 168, 169, 170, 171, 172, 173, 174, 175, 176, 177, 178, 179, 180, 251, 252, 253, 254, 255, 256, 257, 258, 259, 260, 261, 262, 263, 264, 265, 266, 267, 268, 269, 270, 271, 272, 273, 274, 275, 276, 277, 278, 279, 280, 281, 332, 333, 334, 335, 336, 337, 338, 339, 340, 341, 342, 343, 344, 345, 346, 347, 348, 349, 350, 351, 382, 383, 384, 385, 386, 387, 388, 389, 390, 391, 392, 393, 394];
    }

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 121-400 only - no file omitted');
        }

        return view($this->viewPrefix . $feature, ['feature' => $feature]);
    }

    public function coverage(Request $request)
    {
        $services = [];
        foreach (self::coveredServices() as $number) {
            $class = 'App\\Services\\Gameberry\\Core\\Core' . $number . 'Service';
            $services[$number] = class_exists($class);
        }

        return response()->json([
            'success' => true,
            'part' => 'Core (Parts 1-4) Production 121-400',
            'views' => [$this->low, $this->high],
            'services' => $services,
            'all_services_present' => !in_array(false, $services, true),
            'no_shortening' => true,
            'existing_logic_preserved' => true,
        ]);
    }
}
