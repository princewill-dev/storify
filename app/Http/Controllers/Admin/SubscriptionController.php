<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        Log::info('admin_subscriptions_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $query = Subscription::query()
            ->with(['business', 'subscriptionPlan'])
            ->latest('starts_at');

        if (in_array(strtolower((string) $status), ['active', 'expired', 'cancelled', 'trial'], true)) {
            $query->where('status', strtolower($status));
        }

        if ($q !== '') {
            $query->where(function ($x) use ($q) {
                $x->whereHas('business', fn($b) => $b->where('name', 'like', "%$q%"))
                  ->orWhereHas('subscriptionPlan', fn($p) => $p->where('name', 'like', "%$q%"));
            });
        }

        $subscriptions = $query->paginate(15)->withQueryString();

        return view('admin.subscriptions.index', compact('subscriptions', 'status', 'q'));
    }
}
