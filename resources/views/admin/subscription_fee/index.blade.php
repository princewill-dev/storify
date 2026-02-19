@extends('admin.layout')

@section('subtitle', 'Subscription Plans')

@section('content')
<div class="row">
    <div class="col-12 mb-4 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0">Subscription Plans</h4>
            <p class="text-muted">Create, edit, and manage subscription plans for vendors.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPlanModal">
            <i class="bi bi-plus-circle me-1"></i> Create Plan
        </button>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body table-responsive">
                <table class="table align-middle table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Plan Name</th>
                            <th>Amount</th>
                            <th>Interval</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Order</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($plans as $plan)
                            <tr>
                                <td>
                                    <div class="fw-bold">{{ $plan->name }}</div>
                                    <small class="text-muted">{{ Str::limit($plan->description, 50) }}</small>
                                </td>
                                <td>
                                    @if($plan->is_trial)
                                        <span class="text-success fw-bold">Free</span>
                                    @else
                                        <span class="fw-bold">{{ $plan->currency }} {{ number_format($plan->amount, 2) }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ ucfirst($plan->interval) }}
                                    @if($plan->interval_count > 1)
                                        ({{ $plan->interval_count }})
                                    @endif
                                </td>
                                <td>
                                    @if($plan->is_trial)
                                        <span class="badge bg-info">Trial ({{ $plan->trial_days }}d)</span>
                                    @else
                                        <span class="badge bg-secondary">Paid</span>
                                    @endif
                                </td>
                                <td>
                                    @if($plan->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                    
                                    @if($plan->is_default)
                                        <span class="badge bg-primary ms-1">Default</span>
                                    @endif
                                </td>
                                <td>{{ $plan->sort_order }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editPlanModal{{ $plan->id }}">
                                        Edit
                                    </button>
                                    <form action="{{ route('admin.subscription-plans.destroy', $plan) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this plan? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>

                            <!-- Edit Plan Modal -->
                            <div class="modal fade" id="editPlanModal{{ $plan->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form action="{{ route('admin.subscription-plans.update', $plan) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Plan: {{ $plan->name }}</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Plan Name</label>
                                                        <input type="text" name="name" class="form-control" value="{{ $plan->name }}" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Currency</label>
                                                        <input type="text" name="currency" class="form-control" value="{{ $plan->currency }}" maxlength="3" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Amount</label>
                                                        <input type="number" name="amount" class="form-control" step="0.01" min="0" value="{{ $plan->amount }}" required>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Description</label>
                                                        <textarea name="description" class="form-control" rows="2">{{ $plan->description }}</textarea>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Interval</label>
                                                        <select name="interval" class="form-select" required>
                                                            <option value="daily" {{ $plan->interval === 'daily' ? 'selected' : '' }}>Daily</option>
                                                            <option value="weekly" {{ $plan->interval === 'weekly' ? 'selected' : '' }}>Weekly</option>
                                                            <option value="monthly" {{ $plan->interval === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                                            <option value="yearly" {{ $plan->interval === 'yearly' ? 'selected' : '' }}>Yearly</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Interval Count</label>
                                                        <input type="number" name="interval_count" class="form-control" min="1" value="{{ $plan->interval_count }}" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Sort Order</label>
                                                        <input type="number" name="sort_order" class="form-control" min="0" value="{{ $plan->sort_order }}">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="form-check form-switch mt-4">
                                                            <input type="hidden" name="is_active" value="0">
                                                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editActive{{ $plan->id }}" {{ $plan->is_active ? 'checked' : '' }}>
                                                            <label class="form-check-label" for="editActive{{ $plan->id }}">Active</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="form-check form-switch mt-4">
                                                            <input type="hidden" name="is_default" value="0">
                                                            <input class="form-check-input" type="checkbox" name="is_default" value="1" id="editDefault{{ $plan->id }}" {{ $plan->is_default ? 'checked' : '' }}>
                                                            <label class="form-check-label" for="editDefault{{ $plan->id }}">Default Plan</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="form-check form-switch mt-4">
                                                            <input type="hidden" name="is_trial" value="0">
                                                            <input class="form-check-input" type="checkbox" name="is_trial" value="1" id="editTrial{{ $plan->id }}" {{ $plan->is_trial ? 'checked' : '' }} onchange="document.getElementById('editTrialDays{{ $plan->id }}').parentElement.style.display = this.checked ? 'block' : 'none'">
                                                            <label class="form-check-label" for="editTrial{{ $plan->id }}">Trial Plan</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4" id="editTrialDaysWrap{{ $plan->id }}" style="{{ $plan->is_trial ? '' : 'display:none' }}">
                                                        <label class="form-label">Trial Days</label>
                                                        <input type="number" name="trial_days" id="editTrialDays{{ $plan->id }}" class="form-control" min="1" value="{{ $plan->trial_days }}">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Features <small class="text-muted">(one per line)</small></label>
                                                        <textarea name="features" class="form-control" rows="5">{{ $plan->features ? implode("\n", $plan->features) : '' }}</textarea>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4">No subscription plans found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-3">
                    {{ $plans->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Plan Modal -->
<div class="modal fade" id="createPlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="{{ route('admin.subscription-plans.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Create Subscription Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Plan Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency" class="form-control" value="NGN" maxlength="3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" value="0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Interval</label>
                            <select name="interval" class="form-select" required>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly" selected>Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Interval Count</label>
                            <input type="number" name="interval_count" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="createActive" checked>
                                <label class="form-check-label" for="createActive">Active</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input type="hidden" name="is_default" value="0">
                                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="createDefault">
                                <label class="form-check-label" for="createDefault">Default Plan</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4">
                                <input type="hidden" name="is_trial" value="0">
                                <input class="form-check-input" type="checkbox" name="is_trial" value="1" id="createTrial" onchange="document.getElementById('createTrialDaysWrap').style.display = this.checked ? 'block' : 'none'">
                                <label class="form-check-label" for="createTrial">Trial Plan</label>
                            </div>
                        </div>
                        <div class="col-md-4" id="createTrialDaysWrap" style="display:none">
                            <label class="form-label">Trial Days</label>
                            <input type="number" name="trial_days" class="form-control" min="1" value="7">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Features <small class="text-muted">(one per line)</small></label>
                            <textarea name="features" class="form-control" rows="5"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Fix trial days toggle for edit modals
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
