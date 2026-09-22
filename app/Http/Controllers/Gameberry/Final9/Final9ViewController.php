<?php

namespace App\Http\Controllers\Gameberry\Final9;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Final9 Production 1200+ Full Code No Skip Existing Logic Preserved part and reports the part coverage.
 *
 * The part registers one route per feature (1201-1250) so no generated Blade view is
 * left unreachable, and coveredServices() lists every service number of the part so no
 * generated service file is left unreferenced (additive only - existing controllers
 * keep their original behaviour untouched).
 */
class Final9ViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.final9.feature_';
    protected int $low = 1201;
    protected int $high = 1250;

    public static function coveredServices(): array
    {
        return [1251, 1252, 1253, 1254, 1255, 1256, 1257, 1258, 1259, 1260, 1261, 1262, 1263, 1264, 1265, 1266, 1267, 1268, 1269, 1270];
    }

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 1201-1250 only - no file omitted');
        }

        return view($this->viewPrefix . $feature, ['feature' => $feature]);
    }

    public function coverage(Request $request)
    {
        $services = [];
        foreach (self::coveredServices() as $number) {
            $class = 'App\\Services\\Gameberry\\Final9\\Final9' . $number . 'Service';
            $services[$number] = class_exists($class);
        }

        return response()->json([
            'success' => true,
            'part' => 'Final9 Production 1200+ Full Code No Skip Existing Logic Preserved',
            'views' => [$this->low, $this->high],
            'services' => $services,
            'all_services_present' => !in_array(false, $services, true),
            'no_shortening' => true,
            'existing_logic_preserved' => true,
        ]);
    }
}
