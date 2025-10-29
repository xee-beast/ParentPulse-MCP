<?php

namespace App\Http\Controllers;

use App\Services\HelpdeskTool;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HelpdeskController extends Controller
{
    public function query(Request $request, HelpdeskTool $helpdeskTool): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|min:1',
        ]);

        /** @var string|null $tenantId */
        $tenantId = app('tenant_id');

        $result = $helpdeskTool->generateHelpdeskResponse($validated['message'], (string) $tenantId);

        return response()->json($result);
    }
}
