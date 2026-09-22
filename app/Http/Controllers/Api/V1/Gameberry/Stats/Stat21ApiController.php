<?php
namespace App\Http\Controllers\Api\V1\Gameberry\Stats;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat21Service;
use Illuminate\Http\Request;
class Stat21ApiController extends Controller
{
    protected Stat21Service $service;
    public function __construct(Stat21Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : (int) $request->input('user_id', 1);
        return response()->json(['success' => true, 'data' => $this->service->getStats($userId), 'stat' => 21]);
    }
    public function show(Request $request, int $userId)
    {
        return response()->json(['success' => true, 'data' => $this->service->getStats($userId), 'stat' => 21]);
    }
    public function stats(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : (int) $request->input('user_id', 1);
        return response()->json(['success' => true, 'data' => $this->service->getAllStats($userId), 'stat' => 21]);
    }
}
