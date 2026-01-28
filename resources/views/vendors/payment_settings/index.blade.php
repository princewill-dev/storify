@extends('vendors.layout')
@section('subtitle', 'Payment Settings')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Payment Settings</h4>
    </div>

    <!-- Store Payment Modes Section -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fi fi-rr-shop me-2"></i>Store Payment Modes</h5>
            <small class="text-muted">Choose how customers pay for orders at each store</small>
        </div>
        <div class="card-body">
            @if($stores->isEmpty())
                <div class="text-center py-4 text-muted">
                    <p class="mb-0">No stores found.</p>
                </div>
            @else
                <div class="row g-4">
                    @foreach($stores as $store)
                        @php
                            $hasBank = $bankAccounts->where('store_id', $store->id)->count() > 0;
                            $hasPaystack = $paymentGateways->where('store_id', $store->id)->where('is_active', true)->count() > 0;
                            $currentMode = $store->payment_mode ?? 'manual';
                        @endphp
                        <div class="col-12 col-lg-6">
                            <div class="card h-100 border {{ $currentMode === 'auto' ? 'border-primary' : 'border-success' }}">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="mb-1">{{ $store->name }}</h6>
                                            <small class="text-muted">
                                                @if($currentMode === 'auto')
                                                    <span class="badge bg-primary"><i class="fi fi-rr-bolt me-1"></i>Auto Mode</span>
                                                @else
                                                    <span class="badge bg-success"><i class="fi fi-rr-bank me-1"></i>Manual Mode</span>
                                                @endif
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-2">
                                        <!-- Auto Mode Option -->
                                        <div class="col-6">
                                            <form action="{{ route('vendor.payment-settings.toggle-mode', ['vendor' => $vendor, 'store' => $store->store_id]) }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="payment_mode" value="auto">
                                                <button type="submit" 
                                                        class="btn w-100 {{ $currentMode === 'auto' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                        {{ !$hasPaystack ? 'disabled' : '' }}>
                                                    <i class="fi fi-rr-bolt me-1"></i>
                                                    <span class="d-block fw-bold">Auto</span>
                                                    <small class="d-block opacity-75">Paystack</small>
                                                </button>
                                            </form>
                                            @if(!$hasPaystack)
                                                <small class="text-danger d-block mt-1 text-center">
                                                    <i class="fi fi-rr-exclamation me-1"></i>No API keys
                                                </small>
                                            @endif
                                        </div>
                                        
                                        <!-- Manual Mode Option -->
                                        <div class="col-6">
                                            <form action="{{ route('vendor.payment-settings.toggle-mode', ['vendor' => $vendor, 'store' => $store->store_id]) }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="payment_mode" value="manual">
                                                <button type="submit" 
                                                        class="btn w-100 {{ $currentMode === 'manual' ? 'btn-success' : 'btn-outline-success' }}"
                                                        {{ !$hasBank ? 'disabled' : '' }}>
                                                    <i class="fi fi-rr-bank me-1"></i>
                                                    <span class="d-block fw-bold">Manual</span>
                                                    <small class="d-block opacity-75">Bank Transfer</small>
                                                </button>
                                            </form>
                                            @if(!$hasBank)
                                                <small class="text-danger d-block mt-1 text-center">
                                                    <i class="fi fi-rr-exclamation me-1"></i>No bank account
                                                </small>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <!-- Bank Accounts Section -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0"><i class="fi fi-rr-bank me-2"></i>Bank Accounts</h5>
                <small class="text-muted">For Manual (Bank Transfer) payments</small>
            </div>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBankModal">
                <i class="fi fi-rr-plus me-1"></i> Add Bank Account
            </button>
        </div>
        <div class="card-body p-0">
            @if($bankAccounts->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="fi fi-rr-bank fs-1 mb-3 d-block opacity-50"></i>
                    <p class="mb-0">No bank accounts added yet.</p>
                    <small>Required for Manual (Bank Transfer) mode.</small>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Bank</th>
                                <th>Account Number</th>
                                <th>Account Name</th>
                                <th>Store</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bankAccounts as $bank)
                                <tr>
                                    <td><strong>{{ $bank->bank_name }}</strong></td>
                                    <td><code>{{ $bank->masked_account_number }}</code></td>
                                    <td>{{ $bank->account_name }}</td>
                                    <td><span class="badge bg-secondary">{{ $bank->store->name ?? 'N/A' }}</span></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary edit-bank-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editBankModal"
                                                data-id="{{ $bank->id }}"
                                                data-store-id="{{ $bank->store_id }}"
                                                data-bank-code="{{ $bank->bank_code }}"
                                                data-bank-name="{{ $bank->bank_name }}"
                                                data-account-number="{{ $bank->account_number }}"
                                                data-account-name="{{ $bank->account_name }}"
                                                data-is-primary="{{ $bank->is_primary ? '1' : '0' }}">
                                            <i class="fi fi-rr-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-bank-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteBankModal"
                                                data-id="{{ $bank->id }}"
                                                data-name="{{ $bank->bank_name }} - {{ $bank->account_name }}">
                                            <i class="fi fi-rr-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Paystack API Keys Section -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0"><i class="fi fi-rr-key me-2"></i>Paystack API Keys</h5>
                <small class="text-muted">For Auto (Paystack) payments</small>
            </div>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPaystackModal">
                <i class="fi fi-rr-plus me-1"></i> Add Paystack Keys
            </button>
        </div>
        <div class="card-body p-0">
            @if($paymentGateways->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="fi fi-rr-key fs-1 mb-3 d-block opacity-50"></i>
                    <p class="mb-0">No Paystack API keys added yet.</p>
                    <small>Required for Auto (Paystack) mode.</small>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Public Key</th>
                                <th>Store</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($paymentGateways as $gateway)
                                <tr>
                                    <td><code>{{ $gateway->masked_public_key }}</code></td>
                                    <td><span class="badge bg-secondary">{{ $gateway->store->name ?? 'N/A' }}</span></td>
                                    <td>
                                        @if($gateway->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-warning text-dark">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <form action="{{ route('vendor.payment-settings.paystack-keys.toggle', ['vendor' => $vendor, 'gateway' => $gateway->id]) }}" 
                                              method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-{{ $gateway->is_active ? 'warning' : 'success' }}" 
                                                    title="{{ $gateway->is_active ? 'Disable' : 'Enable' }}">
                                                <i class="fi fi-rr-{{ $gateway->is_active ? 'pause' : 'play' }}"></i>
                                            </button>
                                        </form>
                                        <button class="btn btn-sm btn-outline-primary edit-paystack-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editPaystackModal"
                                                data-id="{{ $gateway->id }}"
                                                data-store-id="{{ $gateway->store_id }}"
                                                data-public-key="{{ $gateway->public_key }}"
                                                data-secret-key="{{ $gateway->secret_key }}">
                                            <i class="fi fi-rr-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-paystack-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deletePaystackModal"
                                                data-id="{{ $gateway->id }}"
                                                data-name="{{ $gateway->store->name ?? 'Paystack' }}">
                                            <i class="fi fi-rr-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Modal Partials -->
@include('vendors.payment_settings.partials.add-bank-modal')
@include('vendors.payment_settings.partials.edit-bank-modal')
@include('vendors.payment_settings.partials.delete-bank-modal')
@include('vendors.payment_settings.partials.add-paystack-modal')
@include('vendors.payment_settings.partials.edit-paystack-modal')
@include('vendors.payment_settings.partials.delete-paystack-modal')
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit Bank Modal - populate data
    document.querySelectorAll('.edit-bank-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = document.getElementById('editBankModal');
            modal.querySelector('form').action = '{{ route("vendor.payment-settings.bank-accounts.update", ["vendor" => $vendor, "bank" => "__ID__"]) }}'.replace('__ID__', this.dataset.id);
            modal.querySelector('[name="store_id"]').value = this.dataset.storeId;
            modal.querySelector('[name="bank_code"]').value = this.dataset.bankCode;
            modal.querySelector('[name="bank_name"]').value = this.dataset.bankName;
            modal.querySelector('[name="account_number"]').value = this.dataset.accountNumber;
            modal.querySelector('[name="account_name"]').value = this.dataset.accountName;
            modal.querySelector('[name="is_primary"]').checked = this.dataset.isPrimary === '1';
        });
    });

    // Delete Bank Modal - populate data
    document.querySelectorAll('.delete-bank-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = document.getElementById('deleteBankModal');
            modal.querySelector('form').action = '{{ route("vendor.payment-settings.bank-accounts.destroy", ["vendor" => $vendor, "bank" => "__ID__"]) }}'.replace('__ID__', this.dataset.id);
            modal.querySelector('.bank-name').textContent = this.dataset.name;
        });
    });

    // Edit Paystack Modal - populate data
    document.querySelectorAll('.edit-paystack-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = document.getElementById('editPaystackModal');
            modal.querySelector('form').action = '{{ route("vendor.payment-settings.paystack-keys.update", ["vendor" => $vendor, "gateway" => "__ID__"]) }}'.replace('__ID__', this.dataset.id);
            modal.querySelector('[name="store_id"]').value = this.dataset.storeId;
            modal.querySelector('[name="public_key"]').value = this.dataset.publicKey;
            modal.querySelector('[name="secret_key"]').value = this.dataset.secretKey;
        });
    });

    // Delete Paystack Modal - populate data
    document.querySelectorAll('.delete-paystack-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = document.getElementById('deletePaystackModal');
            modal.querySelector('form').action = '{{ route("vendor.payment-settings.paystack-keys.destroy", ["vendor" => $vendor, "gateway" => "__ID__"]) }}'.replace('__ID__', this.dataset.id);
            modal.querySelector('.gateway-name').textContent = this.dataset.name;
        });
    });
});
</script>
@endpush
