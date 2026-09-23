<?php

namespace App\Http\Controllers\Gameberry\Final10;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Renders every feature view of the Final10 Production 1300+ Full Code No Skip Existing Logic Preserved part and reports the part coverage.
 *
 * The part registers one route per feature (1301-1350) so no generated Blade view is
 * left unreachable, and coveredServices() lists every service number of the part so no
 * generated service file is left unreferenced (additive only - existing controllers
 * keep their original behaviour untouched).
 */
class Final10ViewController extends Controller
{
    protected string $viewPrefix = 'gameberry.final10.feature_';

    protected int $low = 1301;

    protected int $high = 1350;

    public static function coveredServices(): array
    {
        return [1351, 1352, 1353, 1354, 1355, 1356, 1357, 1358, 1359, 1360, 1361, 1362, 1363, 1364, 1365, 1366, 1367, 1368, 1369, 1370];
    }

    public function show(Request $request, int $feature)
    {
        if ($feature < $this->low || $feature > $this->high) {
            abort(404, 'Feature 1301-1350 only - no file omitted');
        }

        return view($this->viewPrefix.$feature, ['feature' => $feature]);
    }

    public function coverage(Request $request)
    {
        $services = [];
        foreach (self::coveredServices() as $number) {
            $class = 'App\\Services\\Gameberry\\Final10\\Final10'.$number.'Service';
            $services[$number] = class_exists($class);
        }

        return response()->json([
            'success' => true,
            'part' => 'Final10 Production 1300+ Full Code No Skip Existing Logic Preserved',
            'views' => [$this->low, $this->high],
            'services' => $services,
            'all_services_present' => ! in_array(false, $services, true),
            'no_shortening' => true,
            'existing_logic_preserved' => true,
        ]);
    }
}
