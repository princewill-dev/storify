@extends('admin.layout')

@section('subtitle', 'Subscription Fees')

@section('content')
<div class="row">
    <div class="col-12 mb-4">
        <h4 class="mb-0">Subscription Fees</h4>
        <p class="text-muted">Manage the amounts charged for different subscription plans.</p>
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
                            <th>Status</th>
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
                                    <span class="fw-bold">{{ $plan->currency }} {{ number_format($plan->amount, 2) }}</span>
                                </td>
                                <td>
                                    {{ ucfirst($plan->interval) }}
                                    @if($plan->interval_count > 1)
                                        (Every {{ $plan->interval_count }} {{ Str::plural($plan->interval, $plan->interval_count) }})
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
                                <td class="text-end">
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editPlanModal{{ $plan->id }}">
                                        Edit Fee
                                    </button>
                                </td>
                            </tr>

                            <!-- Edit Plan Modal -->
                            <div class="modal fade" id="editPlanModal{{ $plan->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form action="{{ route('admin.subscription-plans.update', $plan) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Subscription Fee: {{ $plan->name }}</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Plan Name</label>
                                                    <input type="text" name="name" class="form-control" value="{{ $plan->name }}" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Description</label>
                                                    <textarea name="description" class="form-control" rows="3">{{ $plan->description }}</textarea>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Amount ({{ $plan->currency }})</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">{{ $plan->currency }}</span>
                                                        <input type="number" name="amount" class="form-control" step="0.01" min="0" value="{{ $plan->amount }}" required>
                                                    </div>
                                                    <div class="form-text">Enter the yearly amount to be charged to vendors.</div>
                                                </div>
                                                <div class="mb-3">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="is_active" value="0">
                                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="activeSwitch{{ $plan->id }}" {{ $plan->is_active ? 'checked' : '' }}>
                                                        <label class="form-check-label" for="activeSwitch{{ $plan->id }}">Active Status</label>
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
                                <td colspan="5" class="text-center py-4">No subscription plans found.</td>
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
@endsection
