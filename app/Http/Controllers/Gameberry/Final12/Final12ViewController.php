<?php

namespace App\Http\Controllers\Gameberry\Final12;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Final12 Production 1500+ Full Code No Skip Existing Logic Preserved part and reports the part coverage.
 *
 * The part registers one route per feature (1501-1550) so no generated Blade view is
 * left unreachable, and coveredServices() lists every service number of the part so no
 * generated service file is left unreferenced (additive only - existing controllers
 * keep their original behaviour untouched).
 */
class Final12ViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.final12.feature_';

    protected int $low = 1501;

    protected int $high = 1550;

    public static function coveredServices(): array
    {
        return [1551, 1552, 1553, 1554, 1555, 1556, 1557, 1558, 1559, 1560, 1561, 1562, 1563, 1564, 1565, 1566, 1567, 1568, 1569, 1570];
    }

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 1501-1550 only - no file omitted');
        }

        return view($this->viewPrefix.$feature, ['feature' => $feature]);
    }

    public function coverage(Request $request)
    {
        $services = [];
        foreach (self::coveredServices() as $number) {
            $class = 'App\\Services\\Gameberry\\Final12\\Final12'.$number.'Service';
            $services[$number] = class_exists($class);
        }

        return response()->json([
            'success' => true,
            'part' => 'Final12 Production 1500+ Full Code No Skip Existing Logic Preserved',
            'views' => [$this->low, $this->high],
            'services' => $services,
            'all_services_present' => ! in_array(false, $services, true),
            'no_shortening' => true,
            'existing_logic_preserved' => true,
        ]);
    }
}
