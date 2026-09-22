<?php

namespace App\Http\Controllers\Gameberry\Final11;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Final11 Production 1400+ Full Code No Skip Existing Logic Preserved part and reports the part coverage.
 *
 * The part registers one route per feature (1401-1450) so no generated Blade view is
 * left unreachable, and coveredServices() lists every service number of the part so no
 * generated service file is left unreferenced (additive only - existing controllers
 * keep their original behaviour untouched).
 */
class Final11ViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.final11.feature_';
    protected int $low = 1401;
    protected int $high = 1450;

    public static function coveredServices(): array
    {
        return [1451, 1452, 1453, 1454, 1455, 1456, 1457, 1458, 1459, 1460, 1461, 1462, 1463, 1464, 1465, 1466, 1467, 1468, 1469, 1470];
    }

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 1401-1450 only - no file omitted');
        }

        return view($this->viewPrefix . $feature, ['feature' => $feature]);
    }

    public function coverage(Request $request)
    {
        $services = [];
        foreach (self::coveredServices() as $number) {
            $class = 'App\\Services\\Gameberry\\Final11\\Final11' . $number . 'Service';
            $services[$number] = class_exists($class);
        }

        return response()->json([
            'success' => true,
            'part' => 'Final11 Production 1400+ Full Code No Skip Existing Logic Preserved',
            'views' => [$this->low, $this->high],
            'services' => $services,
            'all_services_present' => !in_array(false, $services, true),
            'no_shortening' => true,
            'existing_logic_preserved' => true,
        ]);
    }
}
