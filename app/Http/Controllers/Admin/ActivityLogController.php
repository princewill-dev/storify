<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAccess();

        $q = trim((string)$request->get('q', ''));
        $status = null; // placeholder for parity with other filters
        $userId = $request->get('user_id');
        $action = $request->get('action');
        $from = $request->get('from');
        $to = $request->get('to');

        $query = ActivityLog::query()->with(['user'])->latest();

        if ($q !== '') {
            $query->where(function($qq) use ($q) {
                $qq->where('action', 'like', "%$q%")
                   ->orWhere('description', 'like', "%$q%")
                   ->orWhere('ip_address', 'like', "%$q%")
                   ->orWhere('user_agent', 'like', "%$q%");
            });
        }

        if (!empty($userId)) {
            $query->where('user_id', $userId);
        }
        if (!empty($action)) {
            $query->where('action', $action);
        }
        if (!empty($from)) {
            $query->whereDate('created_at', '>=', $from);
        }
        if (!empty($to)) {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->paginate(50)->appends($request->query());
        $users = User::orderBy('name')->get(['id','name']);
        $actions = ActivityLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');

        return view('admin.activity_logs.index', compact('logs', 'users', 'actions', 'q', 'status', 'userId', 'action', 'from', 'to'));
    }

    private function authorizeAccess(): void
    {
        if (!auth()->check() || auth()->user()->role !== 'superadmin') {
            abort(403);
        }
    }
}
