<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->string('q'));

        if (mb_strlen($query) < 2) {
            return $this->ok([
                'businesses' => [],
                'users' => [],
                'stores' => [],
                'transactions' => [],
                'coupons' => [],
            ]);
        }

        $term = '%'.$query.'%';
        $user = $request->user();
        $results = [
            'businesses' => [],
            'users' => [],
            'stores' => [],
            'transactions' => [],
            'coupons' => [],
        ];

        if ($user->can('admin.businesses')) {
            $results['businesses'] = Business::query()
                ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('business_code', 'like', $term))
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Business $business) => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'business_code' => $business->business_code,
                    'status' => $business->status,
                ])->values()->all();
        }

        if ($user->can('admin.users')) {
            $results['users'] = User::query()
                ->whereIn('role', [User::ROLE_BUSINESS_OWNER, 'staff'])
                ->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('account_code', 'like', $term)
                    ->orWhere('phone', 'like', $term))
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (User $staff) => [
                    'id' => $staff->id,
                    'account_code' => $staff->account_code,
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'role' => $staff->role,
                    'status' => $staff->status,
                ])->values()->all();
        }

        if ($user->can('admin.stores')) {
            $results['stores'] = Store::query()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('store_id', 'like', $term)
                    ->orWhere('slug', 'like', $term))
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Store $store) => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'status' => $store->status,
                ])->values()->all();
        }

        if ($user->can('admin.transactions')) {
            $results['transactions'] = Transaction::query()
                ->where('reference', 'like', $term)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status instanceof \BackedEnum ? $transaction->status->value : $transaction->status,
                ])->values()->all();
        }

        if ($user->can('admin.coupons')) {
            $results['coupons'] = Coupon::query()
                ->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term))
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Coupon $coupon) => [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'name' => $coupon->name,
                    'is_active' => (bool) $coupon->is_active,
                ])->values()->all();
        }

        return $this->ok($results);
    }
}
