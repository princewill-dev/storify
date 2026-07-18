@extends('admin.layout')

@section('subtitle', 'Subscription Plans')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Subscription Plans</h2>
        <p class="text-sm text-slate-500 mt-0.5">Create, edit, and manage subscription plans.</p>
    </div>
    <button type="button" onclick="openModal('createPlanModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        <i class="fi fi-rr-plus text-sm"></i> Create Plan
    </button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Plan Name</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Amount</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Interval</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Type</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Order</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($plans as $plan)
                    <tr>
                        <td class="py-3 px-4">
                            <div class="font-semibold text-slate-700">{{ $plan->name }}</div>
                            <div class="text-xs text-slate-400">{{ Str::limit($plan->description, 50) }}</div>
                        </td>
                        <td class="py-3 px-4">
                            <span class="font-semibold text-slate-700">{{ $plan->currency }} {{ number_format($plan->amount, 2) }}</span>
                        </td>
                        <td class="py-3 px-4 text-slate-700">
                            {{ ucfirst($plan->interval) }}
                            @if($plan->interval_count > 1)
                                ({{ $plan->interval_count }})
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            <div class="flex flex-wrap gap-1">
                                @if($plan->is_default)
                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">Default</span>
                                @endif
                                @if($plan->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                @endif
                            </div>
                        </td>
                        <td class="py-3 px-4 text-slate-700">{{ $plan->sort_order }}</td>
                        <td class="py-3 px-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button type="button" onclick="openModal('editPlanModal{{ $plan->id }}')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Edit</button>
                                <button type="button" onclick="openModal('deletePlan{{ $plan->id }}')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
                                <x-admin.confirm-modal id="deletePlan{{ $plan->id }}" title="Delete Plan" message="Delete this plan?" warning="This cannot be undone." action="{{ route('admin.subscription-plans.destroy', $plan) }}" method="DELETE" />
                            </div>
                        </td>
                    </tr>

                    <!-- Edit Plan Modal -->
                    <div id="editPlanModal{{ $plan->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="editPlanModalLabel{{ $plan->id }}" role="dialog" aria-modal="true">
                        <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('editPlanModal{{ $plan->id }}')"></div>
                        <div class="flex min-h-full items-center justify-center p-4">
                            <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-xl">
                                <form action="{{ route('admin.subscription-plans.update', $plan) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                                        <h3 class="text-base font-semibold text-slate-900" id="editPlanModalLabel{{ $plan->id }}">Edit Plan: {{ $plan->name }}</h3>
                                        <button type="button" onclick="closeModal('editPlanModal{{ $plan->id }}')" class="text-slate-400 hover:text-slate-600">&times;</button>
                                    </div>
                                    <div class="p-6">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="md:col-span-2">
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Plan Name</label>
                                                <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ $plan->name }}" required>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Currency</label>
                                                <input type="text" name="currency" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ $plan->currency }}" maxlength="3" required>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount</label>
                                                <input type="number" name="amount" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" step="0.01" min="0" value="{{ $plan->amount }}" required>
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                                                <textarea name="description" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="2">{{ $plan->description }}</textarea>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Interval</label>
                                                <select name="interval" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
                                                    <option value="daily" {{ $plan->interval === 'daily' ? 'selected' : '' }}>Daily</option>
                                                    <option value="weekly" {{ $plan->interval === 'weekly' ? 'selected' : '' }}>Weekly</option>
                                                    <option value="monthly" {{ $plan->interval === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                                    <option value="yearly" {{ $plan->interval === 'yearly' ? 'selected' : '' }}>Yearly</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Interval Count</label>
                                                <input type="number" name="interval_count" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="1" value="{{ $plan->interval_count }}" required>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Sort Order</label>
                                                <input type="number" name="sort_order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="0" value="{{ $plan->sort_order }}">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-3">Options</label>
                                                <div class="space-y-2">
                                                    <label class="flex items-center gap-2 cursor-pointer">
                                                        <input type="hidden" name="is_active" value="0">
                                                        <input type="checkbox" name="is_active" value="1" {{ $plan->is_active ? 'checked' : '' }} class="rounded border-slate-300 text-slate-600 focus:ring-slate-500">
                                                        <span class="text-sm text-slate-700">Active</span>
                                                    </label>
                                                    <label class="flex items-center gap-2 cursor-pointer">
                                                        <input type="hidden" name="is_default" value="0">
                                                        <input type="checkbox" name="is_default" value="1" {{ $plan->is_default ? 'checked' : '' }} class="rounded border-slate-300 text-slate-600 focus:ring-slate-500">
                                                        <span class="text-sm text-slate-700">Default Plan</span>
                                                    </label>
                                                    <label class="flex items-center gap-2 cursor-pointer">
                                                        <input type="hidden" name="is_trial" value="0">
                                                        <input type="checkbox" name="is_trial" value="1" id="editTrial{{ $plan->id }}" {{ $plan->is_trial ? 'checked' : '' }} onchange="document.getElementById('editTrialDaysWrap{{ $plan->id }}').style.display = this.checked ? 'block' : 'none'" class="rounded border-slate-300 text-slate-600 focus:ring-slate-500">
                                                        <span class="text-sm text-slate-700">Trial Plan</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div id="editTrialDaysWrap{{ $plan->id }}" style="{{ $plan->is_trial ? '' : 'display:none' }}">
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Trial Days</label>
                                                <input type="number" name="trial_days" id="editTrialDays{{ $plan->id }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="1" value="{{ $plan->trial_days }}">
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Features <span class="text-slate-400">(one per line)</span></label>
                                                <textarea name="features" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="5">{{ $plan->features ? implode("\n", $plan->features) : '' }}</textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                                        <button type="button" onclick="closeModal('editPlanModal{{ $plan->id }}')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                                        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400">No subscription plans found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    <div class="px-6 py-4 border-t border-slate-100">
        {{ $plans->links() }}
    </div>
</div>

<!-- Create Plan Modal -->
<div id="createPlanModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="createPlanModalLabel" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('createPlanModal')"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-xl">
            <form action="{{ route('admin.subscription-plans.store') }}" method="POST">
                @csrf
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-base font-semibold text-slate-900" id="createPlanModalLabel">Create Subscription Plan</h3>
                    <button type="button" onclick="closeModal('createPlanModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Plan Name</label>
                            <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Currency</label>
                            <input type="text" name="currency" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="NGN" maxlength="3" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount</label>
                            <input type="number" name="amount" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" step="0.01" min="0" value="0" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                            <textarea name="description" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="2"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Interval</label>
                            <select name="interval" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly" selected>Yearly</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Interval Count</label>
                            <input type="number" name="interval_count" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="1" value="1" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Sort Order</label>
                            <input type="number" name="sort_order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="0" value="0">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-3">Options</label>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300 text-slate-600 focus:ring-slate-500">
                                    <span class="text-sm text-slate-700">Active</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="hidden" name="is_default" value="0">
                                    <input type="checkbox" name="is_default" value="1" class="rounded border-slate-300 text-slate-600 focus:ring-slate-500">
                                    <span class="text-sm text-slate-700">Default Plan</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="hidden" name="is_trial" value="0">
                                    <input type="checkbox" name="is_trial" value="1" id="createTrial" onchange="document.getElementById('createTrialDaysWrap').style.display = this.checked ? 'block' : 'none'" class="rounded border-slate-300 text-slate-600 focus:ring-slate-500">
                                    <span class="text-sm text-slate-700">Trial Plan</span>
                                </label>
                            </div>
                        </div>
                        <div id="createTrialDaysWrap" style="display:none">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Trial Days</label>
                            <input type="number" name="trial_days" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="1" value="7">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Features <span class="text-slate-400">(one per line)</span></label>
                            <textarea name="features" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="5"></textarea>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                    <button type="button" onclick="closeModal('createPlanModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('[id^="editTrial"]').forEach(function(checkbox) {
        if (checkbox.type !== 'checkbox') return;
        const planId = checkbox.id.replace('editTrial', '');
        const wrap = document.getElementById('editTrialDaysWrap' + planId);
        if (wrap) {
            checkbox.addEventListener('change', function() {
                wrap.style.display = this.checked ? 'block' : 'none';
            });
        }
    });
</script>
@endpush
@endsection
