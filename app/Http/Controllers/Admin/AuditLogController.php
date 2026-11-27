<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    private const AREAS = ['admin' => 'অ্যাডমিন', 'org' => 'অর্গানাইজেশন', 'privacy' => 'প্রাইভেসি'];

    public function index(Request $request): View
    {
        $area = array_key_exists((string) $request->query('area'), self::AREAS) ? $request->query('area') : null;

        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($area, fn ($q) => $q->where('action', 'like', $area . '.%'))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit-logs.index', ['logs' => $logs, 'area' => $area, 'areas' => self::AREAS]);
    }
}
