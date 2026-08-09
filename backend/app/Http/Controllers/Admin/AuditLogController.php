<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($request->string('event')->isNotEmpty(), fn ($query) => $query->where('event', $request->string('event')))
            ->latest()
            ->paginate(50);

        return response()->json($logs);
    }
}
