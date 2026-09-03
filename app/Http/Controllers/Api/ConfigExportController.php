<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentSlot;
use App\Services\ConfigExportService;
use Illuminate\Http\Request;

class ConfigExportController extends Controller
{
    public function __construct(protected ConfigExportService $service) {}

    public function __invoke(Request $request)
    {
        // Slot API key auth — bot fetches its own accounts using Bearer slot api_key
        $apiKey = $request->bearerToken();
        if ($apiKey) {
            $slot = AgentSlot::where('api_key', $apiKey)->first();
            if ($slot) {
                return response()->json($this->service->exportForSlot($slot));
            }
        }

        return response()->json(
            $this->service->export($request->user())
        );
    }
}
